<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Unit;

use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\wahosearch_connector\Service\ApiClient;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * @coversDefaultClass \Drupal\wahosearch_connector\Service\ApiClient
 * @group wahosearch_connector
 */
class ApiClientTest extends UnitTestCase {

  private const SECRET = 'test-secret-value';

  /**
   * Requests captured by the mock handler.
   */
  private array $history = [];

  /**
   * Builds a client answering with the given responses.
   */
  private function client(array $responses, string $endpoint = 'https://platform.example'): ApiClient {
    new Settings([]);
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));

    $config = $this->getConfigFactoryStub([
      'wahosearch_connector.settings' => ['endpoint' => $endpoint, 'site_id' => 'site-1', 'timeout' => 5],
    ]);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(self::SECRET);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1800000000);
    $time->method('getRequestTime')->willReturn(1800000000);

    return new ApiClient(new Client(['handler' => $stack]), $config, $state, $time);
  }

  /**
   * Requests carry a signature the platform can verify.
   */
  public function testRequestIsSigned(): void {
    $client = $this->client([new Response(200, [], '{"accepted":1}')]);

    $this->assertSame(['accepted' => 1], $client->upsert([['id' => 'node:1:fr', 'title' => "\u{C9}t\u{E9}"]]));

    $request = $this->history[0]['request'];
    $body = (string) $request->getBody();
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://platform.example/api/v1/content/bulk', (string) $request->getUri());
    $this->assertSame('site-1', $request->getHeaderLine('X-Site-Id'));
    $this->assertSame('1800000000', $request->getHeaderLine('X-Timestamp'));
    $this->assertStringContainsString("\u{C9}t\u{E9}", $body);
    $this->assertSame(
      hash_hmac('sha256', "1800000000\nPOST\n/api/v1/content/bulk\n" . $body, self::SECRET),
      $request->getHeaderLine('X-Signature'),
    );
    $this->assertStringNotContainsString(self::SECRET, implode(' ', array_map(fn ($h) => implode(' ', $h), $request->getHeaders())));
  }

  /**
   * Plain HTTP is refused before anything is sent.
   */
  public function testInsecureEndpointRefused(): void {
    $client = $this->client([new Response(200)], 'http://platform.example');
    try {
      $client->ping();
      $this->fail('An exception was expected.');
    }
    catch (ApiException $e) {
      $this->assertFalse($e->isRetryable());
      $this->assertCount(0, $this->history);
    }
  }

  /**
   * @covers ::validateEndpoint
   * @dataProvider endpointProvider
   */
  public function testValidateEndpoint(string $url, bool $valid): void {
    $this->assertSame($valid, ApiClient::validateEndpoint($url) === NULL);
  }

  /**
   * Data for testValidateEndpoint().
   */
  public static function endpointProvider(): array {
    return [
      'https' => ['https://platform.example', TRUE],
      'http remote' => ['http://platform.example', FALSE],
      'http localhost' => ['http://localhost:8000', TRUE],
      'look-alike host' => ['http://localhost.evil.example', FALSE],
      'other scheme' => ['ftp://platform.example', FALSE],
      'not a url' => ['platform', FALSE],
    ];
  }

  /**
   * Temporary problems can be retried, refused requests cannot.
   *
   * @dataProvider statusProvider
   */
  public function testRetryClassification(int $status, bool $retryable): void {
    $client = $this->client([new Response($status, [], '{"message":"Details"}')]);
    try {
      $client->ping();
      $this->fail('An exception was expected.');
    }
    catch (ApiException $e) {
      $this->assertSame($status, $e->getStatusCode());
      $this->assertSame($retryable, $e->isRetryable());
      $this->assertStringContainsString('Details', $e->getMessage());
    }
  }

  /**
   * Data for testRetryClassification().
   */
  public static function statusProvider(): array {
    return [
      [400, FALSE],
      [401, FALSE],
      [422, FALSE],
      [408, TRUE],
      [429, TRUE],
      [500, TRUE],
      [503, TRUE],
    ];
  }

  /**
   * Network failures can be retried.
   */
  public function testNetworkFailureIsRetryable(): void {
    $client = $this->client([new ConnectException('Timeout', new Request('GET', 'https://platform.example'))]);
    $this->expectException(ApiException::class);
    try {
      $client->ping();
    }
    catch (ApiException $e) {
      $this->assertTrue($e->isRetryable());
      throw $e;
    }
  }

  /**
   * A full sync cannot start without a run ID.
   */
  public function testMissingSyncIdIsAnError(): void {
    $client = $this->client([new Response(200, [], '{}')]);
    $this->expectException(ApiException::class);
    $client->startFullSync();
  }

  /**
   * An answer that is not JSON is an error.
   */
  public function testInvalidJson(): void {
    $client = $this->client([new Response(200, [], '<html>')]);
    $this->expectException(ApiException::class);
    $client->ping();
  }

  /**
   * File uploads are signed like every other request.
   */
  public function testUploadFile(): void {
    $client = $this->client([new Response(200, [], '{}')]);
    $client->uploadFile('node:1:en:file:2', ['filename' => 'a.pdf', 'mime' => 'application/pdf', 'sha256' => 'abc'], 'PDFDATA');
    $request = $this->history[0]['request'];
    $body = (string) $request->getBody();
    $this->assertSame('/api/v1/content/file', $request->getUri()->getPath());
    $this->assertSame(base64_encode('PDFDATA'), json_decode($body, TRUE)['content_base64']);
    $this->assertSame(ApiClient::sign(self::SECRET, '1800000000', 'POST', '/api/v1/content/file', $body), $request->getHeaderLine('X-Signature'));
    $this->assertSame(120, $this->history[0]['options']['timeout']);
  }


  /**
   * Searches are signed and sent to the search endpoint.
   */
  public function testSearch(): void {
    $client = $this->client([new Response(200, [], '{"results":[],"has_more":false}')]);

    $this->assertSame(['results' => [], 'has_more' => FALSE], $client->search(['query' => 'souris', 'langcode' => 'fr', 'roles' => [], 'page' => 1]));

    $request = $this->history[0]['request'];
    $this->assertSame('https://platform.example/api/v1/search', (string) $request->getUri());
    $this->assertSame(['query' => 'souris', 'langcode' => 'fr', 'roles' => [], 'page' => 1], json_decode((string) $request->getBody(), TRUE));
    $this->assertSame(10, $this->history[0]['options']['timeout']);
  }

  /**
   * The platform's error code, message and wait time are kept.
   */
  public function testErrorDetailsAreKept(): void {
    $client = $this->client([
      new Response(429, ['Retry-After' => '17'], '{"code":"rate_limited","message":"Trop de recherches."}'),
    ]);

    try {
      $client->search(['query' => 'souris', 'langcode' => 'fr']);
      $this->fail('An exception was expected.');
    }
    catch (ApiException $e) {
      $this->assertSame(429, $e->getStatusCode());
      $this->assertSame('rate_limited', $e->getErrorCode());
      $this->assertSame('Trop de recherches.', $e->getPlatformMessage());
      $this->assertSame(17, $e->getRetryAfter());
      $this->assertTrue($e->isRetryable());
    }
  }

}
