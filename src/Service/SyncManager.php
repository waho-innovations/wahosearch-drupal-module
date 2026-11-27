<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Service;

use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Coordinates queueing and sending content to the platform.
 */
class SyncManager implements DestructableInterface {

  public const QUEUE = 'wahosearch_connector_sync';

  public const STATE_LAST_FULL_SYNC = 'wahosearch_connector.last_full_sync';

  public const STATE_LAST_SUCCESS = 'wahosearch_connector.last_success';

  public const STATE_LAST_RECONCILE = 'wahosearch_connector.last_reconcile';

  public const STATE_RECONCILE_REQUESTED = 'wahosearch_connector.reconcile_requested';

  public const STATE_CONNECTION = 'wahosearch_connector.connection';

  /**
   * State key holding the plan and the search page it allows.
   */
  public const STATE_PLAN = 'wahosearch_connector.plan';

  /**
   * Seconds between plan checks on cron.
   */
  public const PLAN_CHECK_INTERVAL = 86400;

  /**
   * Seconds spent sending changes at the end of an editor's request.
   */
  protected const IMMEDIATE_SECONDS = 5;

  /**
   * Whether this request added anything to the queue.
   */
  protected bool $queuedThisRequest = FALSE;

  public function __construct(
    protected ApiClient $apiClient,
    protected ContentNormalizer $normalizer,
    protected ContentSelection $selection,
    protected ItemTracker $tracker,
    protected SyncLog $log,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  /**
   * Queues a node after it changed, if the connector cares about it.
   */
  public function onNodeChange(NodeInterface $node): void {
    if (!$this->autoSyncEnabled()) {
      return;
    }
    if ($this->selection->isBundleEnabled($node->bundle()) || $this->tracker->getDocIdsForEntity('node', (int) $node->id())) {
      $this->enqueue('node', (int) $node->id());
    }
  }

  /**
   * Queues a term and the nodes that display it.
   *
   * Call before the term is deleted, so references can still be found.
   */
  public function onTermChange(TermInterface $term): void {
    if (!$this->autoSyncEnabled() || !in_array($term->bundle(), $this->selection->getReferencedVocabularies(), TRUE)) {
      return;
    }
    $this->enqueue('taxonomy_term', (int) $term->id());
    foreach ($this->findNodesUsingTerm((int) $term->id()) as $nid) {
      $this->enqueue('node', $nid);
    }
  }

  /**
   * Queues every node of a content type.
   */
  public function queueBundle(string $bundle): int {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->execute();
    foreach ($nids as $nid) {
      $this->enqueue('node', (int) $nid);
    }
    return count($nids);
  }

  /**
   * Queues the removal of everything sent for a content type.
   */
  public function queueBundleRemoval(string $bundle): int {
    $ids = $this->tracker->getEntityIdsForBundle($bundle);
    foreach ($ids as $id) {
      $this->enqueue('node', $id);
    }
    return count($ids);
  }

  /**
   * Queues one node regardless of the automatic sync setting.
   */
  public function queueNode(int $nid): void {
    $this->enqueue('node', $nid);
  }

  /**
   * Queues every item whose last send failed.
   */
  public function queueFailed(): int {
    $ids = $this->tracker->getFailedEntityIds();
    foreach ($ids as $id) {
      $this->enqueue('node', $id);
    }
    return count($ids);
  }

  /**
   * Rechecks all content soon after a change that affects who can see it.
   */
  public function onPermissionsChanged(): void {
    if ($this->autoSyncEnabled()) {
      $this->requestReconciliation();
    }
  }

  /**
   * Asks for a reconciliation at the end of this request, or on the next cron.
   */
  public function requestReconciliation(): void {
    $this->state->set(self::STATE_RECONCILE_REQUESTED, TRUE);
  }

  /**
   * Queues everything that should be compared with the platform.
   *
   * Only real differences are sent, because unchanged documents keep their
   * hash. Anything tracked that is no longer eligible gets removed.
   *
   * @return int
   *   Number of items queued.
   */
  public function queueReconciliation(): int {
    $this->state->delete(self::STATE_RECONCILE_REQUESTED);
    $ids = [];
    foreach ($this->selection->getEnabledBundles() as $bundle) {
      $nids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->execute();
      foreach ($nids as $nid) {
        $ids[(int) $nid] = (int) $nid;
      }
    }
    foreach ($this->tracker->getTrackedEntityIds('node') as $id) {
      $ids[$id] = $id;
    }
    foreach ($ids as $id) {
      $this->enqueue('node', $id);
    }
    $queued = count($ids);
    $vocabularies = $this->selection->getReferencedVocabularies();
    if ($vocabularies) {
      $tids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', $vocabularies, 'IN')
        ->execute();
      foreach ($tids as $tid) {
        $this->enqueue('taxonomy_term', (int) $tid);
      }
      $queued += count($tids);
    }
    $this->state->set(self::STATE_LAST_RECONCILE, $this->time->getCurrentTime());
    $this->log->info('reconcile', sprintf('Reconciliation queued %d items.', $queued));
    return $queued;
  }

  /**
   * Runs a requested or scheduled reconciliation. Called from cron.
   */
  public function reconcileIfDue(): void {
    if (!$this->apiClient->isConfigured()) {
      return;
    }
    if ($this->state->get(self::STATE_RECONCILE_REQUESTED)) {
      $this->queueReconciliation();
      return;
    }
    $days = (int) $this->configFactory->get('wahosearch_connector.settings')->get('reconcile_days');
    if (!$this->autoSyncEnabled() || $days < 1 || $this->getQueueSize() > 0) {
      return;
    }
    if ((int) $this->state->get(self::STATE_LAST_RECONCILE, 0) + $days * 86400 <= $this->time->getCurrentTime()) {
      $this->queueReconciliation();
    }
  }

  /**
   * Tests the connection and remembers the result.
   *
   * @throws \Drupal\wahosearch_connector\Exception\ApiException
   *   If the platform refuses or cannot be reached.
   */
  public function testConnection(): array {
    $now = $this->time->getCurrentTime();
    try {
      $response = $this->apiClient->ping();
    }
    catch (ApiException $e) {
      $this->state->set(self::STATE_CONNECTION, ['ok' => FALSE, 'time' => $now, 'message' => $e->getMessage()]);
      $this->log->error('connection', 'Connection test failed: ' . $e->getMessage());
      throw $e;
    }
    $this->state->set(self::STATE_CONNECTION, ['ok' => TRUE, 'time' => $now, 'message' => '']);
    $this->log->info('connection', 'Connection test succeeded.');
    if (isset($response['search_page']) && in_array($response['search_page'], ['drupal', 'hosted'], TRUE)) {
      $this->state->set(self::STATE_PLAN, [
        'plan' => is_string($response['plan'] ?? NULL) ? $response['plan'] : '',
        'search_page' => $response['search_page'],
        'time' => $now,
      ]);
    }
    return $response;
  }

  /**
   * Checks the plan again once a day, so a plan change reaches the site.
   */
  public function refreshPlanIfDue(): void {
    $plan = $this->state->get(self::STATE_PLAN);
    if (!$this->apiClient->isConfigured() || (is_array($plan) && $this->time->getCurrentTime() - (int) ($plan['time'] ?? 0) < self::PLAN_CHECK_INTERVAL)) {
      return;
    }
    try {
      $this->testConnection();
    }
    catch (ApiException) {
      // Already logged. The last known plan stays in use.
    }
  }

  /**
   * Returns the last connection test result, if any.
   */
  public function getConnectionStatus(): ?array {
    return $this->state->get(self::STATE_CONNECTION);
  }

  /**
   * Returns the time content was last delivered to the platform.
   */
  public function getLastSuccess(): ?int {
    return $this->state->get(self::STATE_LAST_SUCCESS);
  }

  /**
   * Returns the time of the last reconciliation.
   */
  public function getLastReconcile(): ?int {
    return $this->state->get(self::STATE_LAST_RECONCILE);
  }

  /**
   * Returns the number of changes waiting to be sent.
   */
  public function getQueueSize(): int {
    return $this->getQueue()->numberOfItems();
  }

  /**
   * Handles one queue item.
   *
   * @throws \Drupal\wahosearch_connector\Exception\ApiException
   *   Only for failures worth retrying. Others are logged here.
   */
  public function processItem(array $item): void {
    if ($item['entity_type'] === 'taxonomy_term') {
      $this->syncTerm((int) $item['entity_id']);
      return;
    }
    $this->syncNodes([(int) $item['entity_id']]);
  }

  /**
   * Works through the queue for a limited time.
   */
  public function processQueue(int $seconds): int {
    $queue = $this->getQueue();
    $end = microtime(TRUE) + $seconds;
    $processed = 0;
    while (microtime(TRUE) < $end && ($claimed = $queue->claimItem(120))) {
      try {
        $this->processItem($claimed->data);
      }
      catch (ApiException $e) {
        $queue->releaseItem($claimed);
        $this->log->warning('queue', 'Paused, will retry later: ' . $e->getMessage());
        break;
      }
      $queue->deleteItem($claimed);
      $processed++;
    }
    return $processed;
  }

  /**
   * Sends nodes to the platform and removes what should no longer be there.
   *
   * @param int[] $nids
   *   Node IDs.
   * @param string|null $syncId
   *   Full sync run ID. When set, every document is sent even if unchanged,
   *   so the platform knows it still exists.
   *
   * @throws \Drupal\wahosearch_connector\Exception\ApiException
   *   Only for failures worth retrying.
   */
  public function syncNodes(array $nids, ?string $syncId = NULL): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $nodes = $storage->loadMultiple($nids);
    $failed = 0;
    $outgoing = [];

    foreach ($nids as $nid) {
      $node = $nodes[$nid] ?? NULL;
      $tracked = $this->tracker->getDocIdsForEntity('node', $nid);
      try {
        $documents = $node instanceof NodeInterface ? $this->normalizer->buildDocuments($node) : [];
      }
      catch (\Throwable $e) {
        $failed++;
        $langcode = $node->language()->getId();
        $message = 'Could not prepare content: ' . $e->getMessage();
        $this->tracker->markError(ContentNormalizer::documentId($nid, $langcode), 'node', $nid, $langcode, $node->bundle(), $message);
        $this->log->error('prepare', $message, 'node:' . $nid);
        continue;
      }

      $stale = array_values(array_diff($tracked, array_keys($documents)));
      if ($stale) {
        $failed += $this->removeDocuments($stale);
      }

      foreach ($documents as $id => $document) {
        if ($syncId !== NULL || $this->tracker->getHash($id) !== $document['hash']) {
          $outgoing[$id] = $document;
        }
      }
    }

    $batchSize = max(1, (int) ($this->configFactory->get('wahosearch_connector.settings')->get('batch_size') ?: 25));
    foreach (array_chunk($outgoing, $batchSize, TRUE) as $chunk) {
      $failed += $this->sendDocuments($chunk, $syncId);
    }

    $storage->resetCache($nids);
    return $failed;
  }

  /**
   * Sends one term, or removes it when it no longer exists.
   *
   * @throws \Drupal\wahosearch_connector\Exception\ApiException
   *   Only for failures worth retrying.
   */
  public function syncTerm(int $tid): void {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    try {
      if ($term instanceof TermInterface && $term->isPublished()) {
        $this->apiClient->upsertTerms([$this->normalizer->normalizeTerm($term)]);
      }
      else {
        $this->apiClient->deleteTerms([ContentNormalizer::termDocumentId($tid)]);
      }
    }
    catch (ApiException $e) {
      if ($e->isRetryable()) {
        throw $e;
      }
      $this->log->error('taxonomy', $e->getMessage(), ContentNormalizer::termDocumentId($tid));
    }
  }

  /**
   * Sends every published term of the vocabularies in use.
   *
   * @throws \Drupal\wahosearch_connector\Exception\ApiException
   *   If the platform refuses or cannot be reached.
   */
  public function syncAllTerms(?string $syncId = NULL): int {
    $vocabularies = $this->selection->getReferencedVocabularies();
    if (!$vocabularies) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabularies, 'IN')
      ->condition('status', 1)
      ->execute();
    $batchSize = max(1, (int) ($this->configFactory->get('wahosearch_connector.settings')->get('batch_size') ?: 25));
    foreach (array_chunk(array_values($tids), $batchSize) as $chunk) {
      $documents = [];
      foreach ($storage->loadMultiple($chunk) as $term) {
        $documents[] = $this->normalizer->normalizeTerm($term);
      }
      $this->apiClient->upsertTerms($documents, $syncId);
      $storage->resetCache($chunk);
    }
    return count($tids);
  }

  /**
   * Records a completed full sync.
   */
  public function completeFullSync(string $syncId, int $startedAt, int $documents): void {
    $this->apiClient->finishFullSync($syncId);
    $this->tracker->removeNotSyncedSince($startedAt);
    $this->state->set(self::STATE_LAST_FULL_SYNC, $this->time->getCurrentTime());
    $this->log->info('full_sync', sprintf('Full synchronization finished, %d items processed.', $documents));
  }

  /**
   * Returns the time of the last completed full sync.
   */
  public function getLastFullSync(): ?int {
    return $this->state->get(self::STATE_LAST_FULL_SYNC);
  }

  /**
   * {@inheritdoc}
   *
   * Sends changes after the response so editors never wait for the platform.
   */
  public function destruct(): void {
    $reconcile = (bool) $this->state->get(self::STATE_RECONCILE_REQUESTED);
    if (!$reconcile && !$this->queuedThisRequest) {
      return;
    }
    try {
      if ($reconcile) {
        $this->queueReconciliation();
      }
      if ($this->configFactory->get('wahosearch_connector.settings')->get('immediate')) {
        $this->processQueue(self::IMMEDIATE_SECONDS);
      }
    }
    catch (\Throwable $e) {
      // Cron picks the work up later. A sync problem must never break a page.
      $this->log->error('queue', $e->getMessage());
    }
  }

  /**
   * Sends one chunk of documents.
   */
  protected function sendDocuments(array $documents, ?string $syncId): int {
    try {
      $response = $this->apiClient->upsert($documents, $syncId);
    }
    catch (ApiException $e) {
      if ($e->isRetryable()) {
        throw $e;
      }
      foreach ($documents as $id => $document) {
        $this->markDocumentFailed($document, $e->getMessage());
      }
      $this->log->error('upsert', $e->getMessage(), (string) array_key_first($documents));
      return count($documents);
    }

    $rejected = $this->messagesById($response['rejected'] ?? [], 'Rejected by the platform.');
    $deferred = $this->messagesById($response['deferred'] ?? [], 'Held back by the platform.');
    $needFiles = array_flip(array_filter((array) ($response['need_files'] ?? []), 'is_string'));
    $failed = 0;
    $sent = 0;
    foreach ($documents as $id => $document) {
      if (isset($rejected[$id])) {
        $this->markDocumentFailed($document, $rejected[$id]);
        $this->log->error('upsert', $rejected[$id], (string) $id);
        $failed++;
      }
      elseif (isset($deferred[$id])) {
        $this->tracker->markDeferred($document, $deferred[$id]);
        $this->log->warning('deferred', $deferred[$id], (string) $id);
      }
      elseif (isset($needFiles[$id]) && isset($document['file']) && !$this->uploadDocumentFile($document)) {
        $failed++;
      }
      else {
        $this->tracker->markIndexed($document);
        $sent++;
      }
    }
    if ($sent) {
      $this->state->set(self::STATE_LAST_SUCCESS, $this->time->getCurrentTime());
    }
    $this->log->info('upsert', sprintf('%d documents sent.', $sent), (string) array_key_first($documents));
    return $failed;
  }

  /**
   * Indexes a list of {id, message} entries by ID.
   *
   * @return array<string, string>
   *   Messages keyed by document ID.
   */
  protected function messagesById(mixed $entries, string $default): array {
    $messages = [];
    foreach (is_array($entries) ? $entries : [] as $entry) {
      if (is_array($entry) && isset($entry['id'])) {
        $messages[(string) $entry['id']] = (string) ($entry['message'] ?? $default);
      }
    }
    return $messages;
  }

  /**
   * Uploads the file of a PDF document.
   *
   * @return bool
   *   FALSE if the file failed permanently.
   *
   * @throws \Drupal\wahosearch_connector\Exception\ApiException
   *   Only for failures worth retrying.
   */
  protected function uploadDocumentFile(array $document): bool {
    $file = $this->entityTypeManager->getStorage('file')->load($document['file']['fid']);
    $content = $file && is_readable($file->getFileUri()) ? file_get_contents($file->getFileUri()) : FALSE;
    if ($content === FALSE) {
      $this->markDocumentFailed($document, 'The file could not be read.');
      $this->log->error('pdf', 'The file could not be read.', $document['id']);
      return FALSE;
    }
    try {
      $this->apiClient->uploadFile($document['id'], $document['file'], $content);
    }
    catch (ApiException $e) {
      if ($e->isRetryable()) {
        throw $e;
      }
      $this->markDocumentFailed($document, $e->getMessage());
      $this->log->error('pdf', $e->getMessage(), $document['id']);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Removes documents from the platform and the tracker.
   */
  protected function removeDocuments(array $docIds): int {
    try {
      $this->apiClient->delete($docIds);
    }
    catch (ApiException $e) {
      if ($e->isRetryable()) {
        throw $e;
      }
      $this->log->error('delete', $e->getMessage(), $docIds[0]);
      return count($docIds);
    }
    $this->tracker->remove($docIds);
    $this->log->info('delete', sprintf('%d documents removed.', count($docIds)), $docIds[0]);
    return 0;
  }

  /**
   * Records a document that the platform refused.
   */
  protected function markDocumentFailed(array $document, string $message): void {
    $this->tracker->markError($document['id'], $document['entity_type'], $document['entity_id'], $document['langcode'], $document['bundle'], $message);
  }

  /**
   * Finds nodes in mapped fields that reference a term.
   */
  protected function findNodesUsingTerm(int $tid): array {
    $found = [];
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ($this->selection->getMappedTaxonomyFields() as $bundle => $fields) {
      $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle);
      $or = $query->orConditionGroup();
      foreach ($fields as $field) {
        $or->condition($field . '.target_id', $tid);
      }
      foreach ($query->condition($or)->execute() as $nid) {
        $found[(int) $nid] = (int) $nid;
      }
    }
    return array_values($found);
  }

  /**
   * Whether content changes should be queued automatically.
   */
  protected function autoSyncEnabled(): bool {
    return (bool) $this->configFactory->get('wahosearch_connector.settings')->get('sync_on_save') && $this->apiClient->isConfigured();
  }

  /**
   * Adds an item to the queue.
   */
  protected function enqueue(string $entityType, int $entityId): void {
    $this->getQueue()->createItem(['entity_type' => $entityType, 'entity_id' => $entityId]);
    $this->queuedThisRequest = TRUE;
  }

  /**
   * Returns the sync queue.
   */
  protected function getQueue(): QueueInterface {
    return $this->queueFactory->get(self::QUEUE, TRUE);
  }

}
