<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Service;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\wahosearch_connector\Exception\ApiException;

/**
 * Runs visitor searches through the platform.
 *
 * The search is sent by this server, signed with the site's secret, so the
 * secret never reaches the browser. The platform gets the language of the page
 * and the visitor's roles, and only returns results in that language that the
 * visitor may see.
 */
class SiteSearch {

  use StringTranslationTrait;

  /**
   * Languages the platform searches.
   */
  public const LANGUAGES = ['en', 'fr'];

  /**
   * Longest question sent, in characters.
   */
  public const MAX_QUERY = 200;

  /**
   * Highest page number offered.
   */
  public const MAX_PAGE = 20;

  public function __construct(
    protected ApiClient $apiClient,
    protected LanguageManagerInterface $languageManager,
    protected AccountInterface $currentUser,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Searches in the language of the current page.
   *
   * @return array
   *   Keys: results (list of results, see ::result()), has_more (bool),
   *   page (int), langcode (string), error (string or NULL, ready to show)
   *   and hosted_only (TRUE when the plan only allows the hosted page).
   */
  public function search(string $query, int $page = 1): array {
    $langcode = $this->language();
    $page = max(1, min(self::MAX_PAGE, $page));
    $outcome = [
      'results' => [],
      'has_more' => FALSE,
      'page' => $page,
      'langcode' => $langcode,
      'error' => NULL,
      'hosted_only' => FALSE,
    ];
    $query = self::cleanQuery($query);
    if ($query === '') {
      return $outcome;
    }
    if (!in_array($langcode, self::LANGUAGES, TRUE)) {
      $outcome['error'] = (string) $this->t('Search is available in English and French only.');
      return $outcome;
    }
    if (!$this->apiClient->isConfigured()) {
      $outcome['error'] = (string) $this->t('Search is not available right now. Please try again later.');
      return $outcome;
    }

    try {
      $response = $this->apiClient->search([
        'query' => $query,
        'langcode' => $langcode,
        'roles' => $this->roles(),
        'page' => $page,
      ]);
    }
    catch (ApiException $e) {
      if ($e->getErrorCode() === 'hosted_page_only') {
        // The plan only allows the hosted page: the caller shows it instead.
        $outcome['hosted_only'] = TRUE;
        return $outcome;
      }
      $outcome['error'] = $this->errorMessage($e);
      return $outcome;
    }

    foreach ($response['results'] ?? [] as $item) {
      if (is_array($item) && ($result = $this->result($item))) {
        $outcome['results'][] = $result;
      }
    }
    $outcome['has_more'] = !empty($response['has_more']) && $page < self::MAX_PAGE;
    return $outcome;
  }

  /**
   * Trims a question to what the platform accepts.
   */
  public static function cleanQuery(string $query): string {
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    return mb_substr($query, 0, self::MAX_QUERY);
  }

  /**
   * Returns the language of the current page.
   */
  public function language(): string {
    return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
  }

  /**
   * Returns the visitor's roles as the platform knows them.
   *
   * Anonymous visitors send none and only see public content.
   */
  protected function roles(): array {
    if ($this->currentUser->isAnonymous()) {
      return [];
    }
    return array_values(array_diff($this->currentUser->getRoles(), [AccountInterface::ANONYMOUS_ROLE]));
  }

  /**
   * Keeps the fields the templates use, and only safe links.
   */
  protected function result(array $item): ?array {
    $url = $this->safeUrl($item['url'] ?? NULL);
    $title = is_string($item['title'] ?? NULL) ? trim($item['title']) : '';
    if ($url === NULL) {
      return NULL;
    }
    $parent = NULL;
    if (is_array($item['parent'] ?? NULL) && ($parentUrl = $this->safeUrl($item['parent']['url'] ?? NULL))) {
      $parent = [
        'title' => is_string($item['parent']['title'] ?? NULL) ? $item['parent']['title'] : $parentUrl,
        'url' => $parentUrl,
      ];
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    return [
      'id' => is_string($item['id'] ?? NULL) ? $item['id'] : '',
      'type' => ($item['type'] ?? '') === 'file' ? 'file' : 'page',
      'title' => $title !== '' ? $title : basename($path),
      'url' => $url,
      'snippet' => is_string($item['snippet'] ?? NULL) ? $item['snippet'] : '',
      'heading' => array_values(array_filter((array) ($item['heading'] ?? []), 'is_string')),
      'content_type' => is_string($item['content_type'] ?? NULL) ? $item['content_type'] : '',
      'tags' => array_values(array_filter((array) ($item['tags'] ?? []), 'is_string')),
      'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
      'parent' => $parent,
    ];
  }

  /**
   * Returns the link if it is a web address, NULL otherwise.
   */
  protected function safeUrl(mixed $url): ?string {
    if (!is_string($url) || $url === '') {
      return NULL;
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
      return $url;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], TRUE) && UrlHelper::isValid($url, TRUE) ? $url : NULL;
  }

  /**
   * Turns a failed search into a message for the visitor.
   */
  protected function errorMessage(ApiException $e): string {
    $code = $e->getErrorCode();
    // The platform's own messages are written for visitors and already in the
    // language of the page.
    if (in_array($code, ['rate_limited', 'search_limit_reached', 'search_busy', 'empty_query'], TRUE) && $e->getPlatformMessage()) {
      return $e->getPlatformMessage();
    }
    $this->logger->warning('Search failed: @message', ['@message' => $e->getMessage()]);
    if ($e->getStatusCode() === 429 || $code === 'search_busy') {
      return (string) $this->t('Search is busy right now. Please try again in a moment.');
    }
    return (string) $this->t('Search is not available right now. Please try again later.');
  }

}
