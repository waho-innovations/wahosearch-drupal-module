<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Kernel;

use Drupal\wahosearch_connector\Service\ApiClient;

/**
 * Records calls instead of talking to the platform.
 */
class RecordingApiClient extends ApiClient {

  /**
   * Calls received, each with path and body.
   */
  public static array $calls = [];

  /**
   * Exception thrown by the next calls, if any.
   */
  public static ?\Throwable $failWith = NULL;

  /**
   * Response returned for content/bulk.
   */
  public static array $bulkResponse = [];

  /**
   * Resets the recorder.
   */
  public static function reset(): void {
    self::$calls = [];
    self::$failWith = NULL;
    self::$bulkResponse = [];
  }

  /**
   * Returns the bodies sent to a path.
   */
  public static function bodies(string $path): array {
    return array_column(array_filter(self::$calls, fn ($call) => $call['path'] === $path), 'body');
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function request(string $method, string $path, ?array $body = NULL): array {
    if (self::$failWith) {
      throw self::$failWith;
    }
    self::$calls[] = ['path' => $path, 'body' => $body];
    return match ($path) {
      'sync/full/start' => ['sync_id' => 'run-1'],
      'content/bulk' => self::$bulkResponse,
      default => [],
    };
  }

}
