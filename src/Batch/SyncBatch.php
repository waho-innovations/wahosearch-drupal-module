<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Batch;

use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\wahosearch_connector\Service\SyncManager;
use Drupal\Core\Batch\BatchBuilder;

/**
 * Batch definitions and callbacks for manual synchronization.
 *
 * Static on purpose, because batch callbacks are stored between requests.
 */
final class SyncBatch {

  /**
   * Builds the full synchronization batch.
   */
  public static function fullSync(): array {
    $builder = (new BatchBuilder())
      ->setTitle(t('Full synchronization'))
      ->setProgressMessage(t('Completed @current of @total steps.'))
      ->setErrorMessage(t('The full synchronization stopped because of an error.'))
      ->setFinishCallback([self::class, 'finishFullSync'])
      ->addOperation([self::class, 'startFullSync']);
    foreach (\Drupal::service('wahosearch_connector.content_selection')->getEnabledBundles() as $bundle) {
      $builder->addOperation([self::class, 'syncBundle'], [$bundle]);
    }
    $builder->addOperation([self::class, 'syncTerms']);
    return $builder->toArray();
  }

  /**
   * Builds the batch that sends pending changes.
   */
  public static function processQueue(): array {
    return (new BatchBuilder())
      ->setTitle(t('Sending pending changes'))
      ->setFinishCallback([self::class, 'finishProcessQueue'])
      ->addOperation([self::class, 'processQueueStep'])
      ->toArray();
  }

  /**
   * Opens a full sync run on the platform.
   */
  public static function startFullSync(array &$context): void {
    $context['results'] += ['processed' => 0, 'failed' => 0, 'fatal' => NULL];
    $context['results']['started'] = \Drupal::time()->getCurrentTime();
    try {
      $context['results']['sync_id'] = \Drupal::service('wahosearch_connector.api_client')->startFullSync();
    }
    catch (ApiException $e) {
      $context['results']['fatal'] = $e->getMessage();
    }
  }

  /**
   * Sends one step of a content type.
   */
  public static function syncBundle(string $bundle, array &$context): void {
    if (!empty($context['results']['fatal'])) {
      $context['finished'] = 1;
      return;
    }
    $query = fn () => \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle);

    if (!isset($context['sandbox']['last'])) {
      $context['sandbox']['last'] = 0;
      $context['sandbox']['done'] = 0;
      $context['sandbox']['total'] = (int) $query()->count()->execute();
    }
    $size = max(1, (int) \Drupal::config('wahosearch_connector.settings')->get('batch_size'));
    $nids = array_map('intval', array_values($query()
      ->condition('nid', $context['sandbox']['last'], '>')
      ->sort('nid')
      ->range(0, $size)
      ->execute()));

    if (!$nids) {
      $context['finished'] = 1;
      return;
    }

    try {
      $context['results']['failed'] += self::syncManager()->syncNodes($nids, $context['results']['sync_id']);
    }
    catch (ApiException $e) {
      $context['results']['fatal'] = $e->getMessage();
      $context['finished'] = 1;
      return;
    }

    $context['sandbox']['last'] = end($nids);
    $context['sandbox']['done'] += count($nids);
    $context['results']['processed'] += count($nids);
    $context['message'] = t('Content type @bundle: @done of @total', [
      '@bundle' => $bundle,
      '@done' => $context['sandbox']['done'],
      '@total' => $context['sandbox']['total'],
    ]);
    $context['finished'] = $context['sandbox']['total'] > 0 ? min(1, $context['sandbox']['done'] / $context['sandbox']['total']) : 1;
    // Nodes added during the run can make done overshoot, so finish on a
    // short page as well.
    if (count($nids) < $size) {
      $context['finished'] = 1;
    }
  }

  /**
   * Sends the vocabularies in use.
   */
  public static function syncTerms(array &$context): void {
    if (!empty($context['results']['fatal'])) {
      return;
    }
    try {
      self::syncManager()->syncAllTerms($context['results']['sync_id']);
    }
    catch (ApiException $e) {
      $context['results']['fatal'] = $e->getMessage();
    }
  }

  /**
   * Closes the run, only when everything was delivered.
   *
   * The platform removes whatever it did not see during the run, so closing
   * an incomplete run would delete good content from the index.
   */
  public static function finishFullSync(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $log = \Drupal::service('wahosearch_connector.sync_log');

    if (!$success || !empty($results['fatal']) || empty($results['sync_id'])) {
      $reason = $results['fatal'] ?? 'The batch did not complete.';
      $log->error('full_sync', 'Full synchronization stopped: ' . $reason);
      $messenger->addError(t('The full synchronization stopped: @reason. Nothing was removed from the index. Please try again.', ['@reason' => $reason]));
      return;
    }
    if (!empty($results['failed'])) {
      $log->warning('full_sync', sprintf('Full synchronization left open, %d documents failed.', $results['failed']));
      $messenger->addWarning(t('@count documents could not be sent, so old content was not cleaned up. Please check the log and run the full synchronization again.', ['@count' => $results['failed']]));
      return;
    }
    try {
      self::syncManager()->completeFullSync($results['sync_id'], $results['started'], $results['processed']);
      $messenger->addStatus(t('Full synchronization finished. @count items processed.', ['@count' => $results['processed']]));
    }
    catch (ApiException $e) {
      $log->error('full_sync', 'Could not close the run: ' . $e->getMessage());
      $messenger->addError(t('All content was sent, but the run could not be closed: @reason', ['@reason' => $e->getMessage()]));
    }
  }

  /**
   * Sends a slice of the queue.
   */
  public static function processQueueStep(array &$context): void {
    $context['results'] += ['processed' => 0];
    $manager = self::syncManager();
    $handled = $manager->processQueue(5);
    $context['results']['processed'] += $handled;
    $remaining = $manager->getQueueSize();
    $context['message'] = t('@count changes left.', ['@count' => $remaining]);
    // No progress means an empty queue or an unreachable platform.
    $context['finished'] = ($handled === 0 || $remaining === 0) ? 1 : $context['results']['processed'] / ($context['results']['processed'] + $remaining);
  }

  /**
   * Reports the result of sending pending changes.
   */
  public static function finishProcessQueue(bool $success, array $results, array $operations): void {
    $remaining = self::syncManager()->getQueueSize();
    \Drupal::messenger()->addStatus(t('@count changes processed.', ['@count' => $results['processed'] ?? 0]));
    if ($remaining > 0) {
      \Drupal::messenger()->addWarning(t('@count changes are still waiting. Please check the log.', ['@count' => $remaining]));
    }
  }

  /**
   * Returns the sync manager.
   */
  private static function syncManager(): SyncManager {
    return \Drupal::service('wahosearch_connector.sync_manager');
  }

}
