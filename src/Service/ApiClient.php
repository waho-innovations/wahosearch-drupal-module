<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Service;

use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;

/**
 * Talks to the WahoSearch connector API.
 *
 * Every request is signed with HMAC-SHA256 over the timestamp, method, path
 * and body, so the secret never travels over the wire. See
 * docs/API_CONTRACT.md for the full contract.
 */
class ApiClient {

  /**
   * Version sent in the X-Connector-Version header.
   */
  public const VERSION = '1.1.0';

  /**
   * State key holding the shared secret when not set in settings.php.
   */
  public const SECRET_STATE_KEY = 'wahosearch_connector.secret';

  /**
   * Settings.php key that overrides the stored secret.
   */
  public const SECRET_SETTINGS_KEY = 'wahosearch_connector_secret';

  /**
   * Seconds allowed for sending one file.
   */
  protected const UPLOAD_TIMEOUT = 120;

  /**
   * Seconds a visitor waits at most for search results.
   */
  protected const SEARCH_TIMEOUT = 10;

  /**
   * Seconds to wait for suggestions; late ones are no use while typing.
   */
  protected const SUGGEST_TIMEOUT = 3;

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  /**
   * Whether endpoint, site ID and secret are all present.
   */
  public function isConfigured(): bool {
    $config = $this->configFactory->get('wahosearch_connector.settings');
    return $config->get('endpoint') && $config->get('site_id') && $this->getSecret();
  }

  /**
   * Returns the shared secret. settings.php wins over the stored value.
   */
  public function getSecret(): ?string {
    $secret = Settings::get(self::SECRET_SETTINGS_KEY) ?: $this->state->get(self::SECRET_STATE_KEY);
    return is_string($secret) && $secret !== '' ? $secret : NULL;
  }

  /**
   * Whether the secret comes from settings.php.
   */
  public function isSecretOverridden(): bool {
    return (bool) Settings::get(self::SECRET_SETTINGS_KEY);
  }

  /**
   * Stores the secret outside exported configuration.
   */
  public function setSecret(string $secret): void {
    $this->state->set(self::SECRET_STATE_KEY, $secret);
  }

  /**
   * Checks that the SaaS accepts our credentials.
   *
   * @return array
   *   Decoded response, for example site name and plan.
   */
  public function ping(): array {
    return $this->request('GET', 'connector/ping');
  }

  /**
   * Creates or updates documents.
   *
   * @param array $documents
   *   Normalized documents.
   * @param string|null $syncId
   *   Full sync run ID, if this call is part of a full sync.
   */
  public function upsert(array $documents, ?string $syncId = NULL): array {
    $body = ['documents' => array_values($documents)];
    if ($syncId) {
      $body['sync_id'] = $syncId;
    }
    return $this->request('POST', 'content/bulk', $body);
  }

  /**
   * Sends the contents of a PDF the platform asked for.
   */
  public function uploadFile(string $id, array $file, string $content): array {
    return $this->request('POST', 'content/file', [
      'id' => $id,
      'filename' => $file['filename'],
      'mime' => $file['mime'],
      'sha256' => $file['sha256'],
      'content_base64' => base64_encode($content),
    ], self::UPLOAD_TIMEOUT);
  }

  /**
   * Removes documents from the index.
   *
   * @param string[] $ids
   *   Document IDs such as node:12:fr.
   */
  public function delete(array $ids): array {
    return $this->request('POST', 'content/delete', ['ids' => array_values($ids)]);
  }

  /**
   * Sends taxonomy terms so the platform can build filters.
   */
  public function upsertTerms(array $terms, ?string $syncId = NULL): array {
    $body = ['terms' => array_values($terms)];
    if ($syncId) {
      $body['sync_id'] = $syncId;
    }
    return $this->request('POST', 'taxonomy/bulk', $body);
  }

  /**
   * Removes taxonomy terms.
   *
   * @param string[] $ids
   *   Term document IDs such as taxonomy_term:5.
   */
  public function deleteTerms(array $ids): array {
    return $this->request('POST', 'taxonomy/delete', ['ids' => array_values($ids)]);
  }

  /**
   * Starts a full sync run. Returns the run ID.
   */
  public function startFullSync(): string {
    $response = $this->request('POST', 'sync/full/start', ['started' => $this->time->getRequestTime()]);
    if (empty($response['sync_id']) || !is_string($response['sync_id'])) {
      throw new ApiException('The platform did not return a sync_id.', 200, FALSE);
    }
    return $response['sync_id'];
  }

  /**
   * Finishes a full sync run. The platform removes what was not seen.
   */
  public function finishFullSync(string $syncId): array {
    return $this->request('POST', 'sync/full/finish', ['sync_id' => $syncId]);
  }

  /**
   * Searches the website's indexed content.
   *
   * @param array $request
   *   The search request: query, langcode, roles, page, and optionally the
   *   filters, sort, mode and facets, as described in docs/API_CONTRACT.md.
   *
   * @throws \Drupal\wahosearch_connector\Exception\ApiException
   */
  public function search(array $request): array {
    return $this->request('POST', 'search', $request, self::SEARCH_TIMEOUT);
  }

  /**
   * Returns suggestions for the words a visitor has typed so far.
   *
   * @param array $request
   *   The words typed (query), langcode and roles.
   *
   * @throws \Drupal\wahosearch_connector\Exception\ApiException
   */
  public function suggest(array $request): array {
    return $this->request('POST', 'search/suggest', $request, self::SUGGEST_TIMEOUT);
  }

  /**
   * Builds the request signature.
   */
  public static function sign(string $secret, string $timestamp, string $method, string $path, string $body): string {
    return hash_hmac('sha256', $timestamp . "\n" . strtoupper($method) . "\n" . $path . "\n" . $body, $secret);
  }

  /**
   * Validates an endpoint URL. HTTPS is required except on local hosts.
   */
  public static function validateEndpoint(string $endpoint): ?string {
    $parts = parse_url($endpoint);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
      return 'The URL is not valid.';
    }
    $local = in_array($parts['host'], ['localhost', '127.0.0.1', '::1'], TRUE) || str_ends_with($parts['host'], '.localhost') || str_ends_with($parts['host'], '.ddev.site');
    if ($parts['scheme'] !== 'https' && !($parts['scheme'] === 'http' && $local)) {
      return 'The URL must use HTTPS.';
    }
    return NULL;
  }

  /**
   * Sends a signed JSON request.
   *
   * @throws \Drupal\wahosearch_connector\Exception\ApiException
   */
  protected function request(string $method, string $path, ?array $body = NULL, ?int $timeout = NULL): array {
    $config = $this->configFactory->get('wahosearch_connector.settings');
    $endpoint = rtrim((string) $config->get('endpoint'), '/');
    $siteId = (string) $config->get('site_id');
    $secret = $this->getSecret();
    if ($endpoint === '' || $siteId === '' || !$secret) {
      throw new ApiException('The connector is not configured.', 0, FALSE);
    }
    if ($error = self::validateEndpoint($endpoint)) {
      throw new ApiException($error, 0, FALSE);
    }

    $fullPath = '/api/v1/' . $path;
    $url = $endpoint . $fullPath;
    $json = $body === NULL ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $timestamp = (string) $this->time->getCurrentTime();
    $parsedPath = parse_url($url, PHP_URL_PATH) ?: $fullPath;

    $options = [
      RequestOptions::HEADERS => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'X-Site-Id' => $siteId,
        'X-Timestamp' => $timestamp,
        'X-Signature' => self::sign($secret, $timestamp, $method, $parsedPath, $json),
        'X-Connector-Version' => 'drupal/' . self::VERSION,
      ],
      RequestOptions::TIMEOUT => $timeout ?? max(1, (int) ($config->get('timeout') ?: 15)),
      RequestOptions::CONNECT_TIMEOUT => 10,
      RequestOptions::HTTP_ERRORS => TRUE,
      RequestOptions::ALLOW_REDIRECTS => FALSE,
    ];
    if ($json !== '') {
      $options[RequestOptions::BODY] = $json;
    }

    try {
      $response = $this->httpClient->request($method, $url, $options);
    }
    catch (ConnectException $e) {
      throw new ApiException('Could not reach the platform: ' . $e->getMessage(), 0, TRUE, $e);
    }
    catch (RequestException $e) {
      $status = $e->getResponse()?->getStatusCode() ?? 0;
      $detail = '';
      $code = $message = $retryAfter = NULL;
      if ($e->getResponse()) {
        $decoded = json_decode((string) $e->getResponse()->getBody(), TRUE);
        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
          $message = mb_substr($decoded['message'], 0, 300);
          $detail = ': ' . $message;
        }
        if (is_array($decoded) && isset($decoded['code']) && is_string($decoded['code'])) {
          $code = mb_substr($decoded['code'], 0, 64);
        }
        $header = $e->getResponse()->getHeaderLine('Retry-After');
        if (ctype_digit($header)) {
          $retryAfter = (int) $header;
        }
      }
      $retryable = $status === 0 || $status === 408 || $status === 429 || $status >= 500;
      throw new ApiException(sprintf('The platform returned HTTP %d%s', $status, $detail), $status, $retryable, $e, $code, $message, $retryAfter);
    }
    catch (GuzzleException $e) {
      throw new ApiException('Request failed: ' . $e->getMessage(), 0, TRUE, $e);
    }

    $content = (string) $response->getBody();
    if ($content === '') {
      return [];
    }
    $decoded = json_decode($content, TRUE);
    if (!is_array($decoded)) {
      throw new ApiException('The platform returned a response that is not JSON.', $response->getStatusCode(), FALSE);
    }
    return $decoded;
  }

}
