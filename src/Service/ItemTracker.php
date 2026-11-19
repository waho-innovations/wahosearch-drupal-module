<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Remembers what was sent to the platform, one row per document.
 */
class ItemTracker {

  public const TABLE = 'wahosearch_connector_item';

  public const STATUS_INDEXED = 'indexed';

  public const STATUS_ERROR = 'error';

  public const STATUS_DEFERRED = 'deferred';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Returns the hash of an indexed document, or NULL if it must be sent.
   */
  public function getHash(string $docId): ?string {
    $hash = $this->database->select(self::TABLE, 'i')
      ->fields('i', ['hash'])
      ->condition('doc_id', $docId)
      ->condition('status', self::STATUS_INDEXED)
      ->execute()
      ->fetchField();
    return $hash === FALSE || $hash === NULL ? NULL : (string) $hash;
  }

  /**
   * Records a successful send.
   */
  public function markIndexed(array $document): void {
    $this->database->merge(self::TABLE)
      ->keys(['doc_id' => $document['id']])
      ->fields([
        'entity_type' => $document['entity_type'],
        'entity_id' => $document['entity_id'],
        'langcode' => $document['langcode'],
        'bundle' => $document['bundle'],
        'status' => self::STATUS_INDEXED,
        'hash' => $document['hash'],
        'last_synced' => $this->time->getCurrentTime(),
        'last_error' => NULL,
      ])
      ->execute();
  }

  /**
   * Records a failed send. The cleared hash forces a resend on retry.
   */
  public function markError(string $docId, string $entityType, int $entityId, string $langcode, string $bundle, string $message): void {
    $this->database->merge(self::TABLE)
      ->keys(['doc_id' => $docId])
      ->fields([
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'langcode' => $langcode,
        'bundle' => $bundle,
        'status' => self::STATUS_ERROR,
        'hash' => NULL,
        'last_error' => mb_substr($message, 0, 1000),
      ])
      ->execute();
  }

  /**
   * Records a document the platform is holding back, for example over quota.
   *
   * No hash is kept, so it is offered again on the next run.
   */
  public function markDeferred(array $document, string $reason): void {
    $this->database->merge(self::TABLE)
      ->keys(['doc_id' => $document['id']])
      ->fields([
        'entity_type' => $document['entity_type'],
        'entity_id' => $document['entity_id'],
        'langcode' => $document['langcode'],
        'bundle' => $document['bundle'],
        'status' => self::STATUS_DEFERRED,
        'hash' => NULL,
        'last_synced' => $this->time->getCurrentTime(),
        'last_error' => mb_substr($reason, 0, 1000),
      ])
      ->execute();
  }

  /**
   * Returns the IDs of all tracked entities of a type.
   */
  public function getTrackedEntityIds(string $entityType): array {
    $ids = $this->database->select(self::TABLE, 'i')
      ->fields('i', ['entity_id'])
      ->condition('entity_type', $entityType)
      ->distinct()
      ->execute()
      ->fetchCol();
    return array_map('intval', $ids);
  }

  /**
   * Forgets documents.
   *
   * @param string[] $docIds
   *   Document IDs.
   */
  public function remove(array $docIds): void {
    if ($docIds) {
      $this->database->delete(self::TABLE)->condition('doc_id', $docIds, 'IN')->execute();
    }
  }

  /**
   * Returns every tracked document ID of an entity.
   */
  public function getDocIdsForEntity(string $entityType, int $entityId): array {
    return $this->database->select(self::TABLE, 'i')
      ->fields('i', ['doc_id'])
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $entityId)
      ->execute()
      ->fetchCol();
  }

  /**
   * Returns the IDs of tracked entities of a bundle.
   */
  public function getEntityIdsForBundle(string $bundle): array {
    $ids = $this->database->select(self::TABLE, 'i')
      ->fields('i', ['entity_id'])
      ->condition('bundle', $bundle)
      ->distinct()
      ->execute()
      ->fetchCol();
    return array_map('intval', $ids);
  }

  /**
   * Returns the IDs of entities with at least one failed document.
   */
  public function getFailedEntityIds(): array {
    $ids = $this->database->select(self::TABLE, 'i')
      ->fields('i', ['entity_id'])
      ->condition('status', self::STATUS_ERROR)
      ->distinct()
      ->execute()
      ->fetchCol();
    return array_map('intval', $ids);
  }

  /**
   * Returns the number of indexed documents of a bundle.
   */
  public function countIndexedForBundle(string $bundle): int {
    return (int) $this->database->select(self::TABLE, 'i')
      ->condition('bundle', $bundle)
      ->condition('status', self::STATUS_INDEXED)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns document counts keyed by status.
   *
   * @return array<string, int>
   *   Always contains the indexed, error and deferred keys.
   */
  public function countByStatus(): array {
    $counts = [self::STATUS_INDEXED => 0, self::STATUS_ERROR => 0, self::STATUS_DEFERRED => 0];
    $query = $this->database->select(self::TABLE, 'i')->fields('i', ['status']);
    $query->addExpression('COUNT(*)', 'total');
    foreach ($query->groupBy('status')->execute() as $row) {
      $counts[$row->status] = (int) $row->total;
    }
    return $counts;
  }

  /**
   * Forgets indexed documents not seen since a time (after a full sync).
   */
  public function removeNotSyncedSince(int $timestamp): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('status', self::STATUS_INDEXED)
      ->condition('last_synced', $timestamp, '<')
      ->execute();
  }

}
