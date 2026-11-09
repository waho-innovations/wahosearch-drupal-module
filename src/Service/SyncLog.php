<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Psr\Log\LoggerInterface;

/**
 * Stores synchronization events for the administration screen.
 */
class SyncLog {

  public const TABLE = 'wahosearch_connector_log';

  public const LEVELS = ['info', 'warning', 'error'];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Adds an informational entry.
   */
  public function info(string $operation, string $message, ?string $reference = NULL): void {
    $this->write('info', $operation, $message, $reference);
  }

  /**
   * Adds a warning entry.
   */
  public function warning(string $operation, string $message, ?string $reference = NULL): void {
    $this->write('warning', $operation, $message, $reference);
    $this->logger->warning('@operation: @message', ['@operation' => $operation, '@message' => $message]);
  }

  /**
   * Adds an error entry.
   */
  public function error(string $operation, string $message, ?string $reference = NULL): void {
    $this->write('error', $operation, $message, $reference);
    $this->logger->error('@operation: @message', ['@operation' => $operation, '@message' => $message]);
  }

  /**
   * Returns one page of entries, newest first.
   *
   * @return array
   *   Row objects.
   */
  public function getPage(?string $level, int $limit = 50): array {
    $query = $this->database->select(self::TABLE, 'l')
      ->extend(PagerSelectExtender::class)
      ->limit($limit);
    $query->fields('l');
    if ($level) {
      $query->condition('level', $level);
    }
    return $query->orderBy('id', 'DESC')->execute()->fetchAll();
  }

  /**
   * Returns the newest error entry, if any.
   */
  public function getLastError(): ?object {
    $row = $this->database->select(self::TABLE, 'l')
      ->fields('l')
      ->condition('level', 'error')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Deletes entries older than the given number of days.
   */
  public function purge(int $days): void {
    if ($days > 0) {
      $this->database->delete(self::TABLE)
        ->condition('created', $this->time->getCurrentTime() - ($days * 86400), '<')
        ->execute();
    }
  }

  /**
   * Inserts a row.
   */
  protected function write(string $level, string $operation, string $message, ?string $reference): void {
    $this->database->insert(self::TABLE)
      ->fields([
        'created' => $this->time->getCurrentTime(),
        'level' => $level,
        'operation' => mb_substr($operation, 0, 32),
        'message' => mb_substr($message, 0, 2000),
        'reference' => $reference === NULL ? NULL : mb_substr($reference, 0, 255),
      ])
      ->execute();
  }

}
