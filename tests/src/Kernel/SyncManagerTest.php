<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Kernel;

use Drupal\wahosearch_connector\Batch\SyncBatch;
use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\wahosearch_connector\Service\ItemTracker;
use Drupal\node\Entity\Node;

/**
 * Tests incremental and full synchronization.
 *
 * @group wahosearch_connector
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class SyncManagerTest extends ConnectorKernelTestBase {

  /**
   * New content is queued and sent once. Unchanged saves send nothing.
   */
  public function testIncrementalSync(): void {
    $node = $this->createArticle();
    $this->assertSame(1, $this->syncManager()->getQueueSize());
    $this->assertSame([], RecordingApiClient::$calls, 'Nothing is sent while the editor waits.');

    $this->drainQueue();
    $this->assertSame(['node:' . $node->id() . ':en'], $this->sentIds());
    $this->assertSame(0, $this->syncManager()->getQueueSize());

    Node::load($node->id())->save();
    $this->drainQueue();
    $this->assertCount(1, $this->sentIds(), 'An unchanged node is not sent again.');

    $node = Node::load($node->id());
    $node->setTitle('Changed');
    $node->save();
    $this->drainQueue();
    $this->assertCount(2, $this->sentIds());
  }

  /**
   * Unpublishing removes the document, publishing brings it back.
   */
  public function testUnpublishAndRepublish(): void {
    $node = $this->createArticle();
    $this->drainQueue();

    $node = Node::load($node->id());
    $node->setUnpublished()->save();
    $this->drainQueue();
    $this->assertSame(['node:' . $node->id() . ':en'], $this->deletedIds());

    $node = Node::load($node->id());
    $node->setPublished()->save();
    $this->drainQueue();
    $this->assertCount(2, $this->sentIds());
  }

  /**
   * Deleting a node removes all of its languages.
   */
  public function testDelete(): void {
    $node = $this->createArticle();
    $node->addTranslation('fr', ['title' => 'Titre', 'status' => 1])->save();
    $this->drainQueue();
    $id = $node->id();

    $node->delete();
    $this->drainQueue();

    $deleted = $this->deletedIds();
    sort($deleted);
    $this->assertSame(['node:' . $id . ':en', 'node:' . $id . ':fr'], $deleted);
  }

  /**
   * Removing a translation only removes that language.
   */
  public function testTranslationRemoved(): void {
    $node = $this->createArticle();
    $node->addTranslation('fr', ['title' => 'Titre', 'status' => 1])->save();
    $this->drainQueue();

    $node = Node::load($node->id());
    $node->removeTranslation('fr');
    $node->save();
    $this->drainQueue();

    $this->assertSame(['node:' . $node->id() . ':fr'], $this->deletedIds());
  }

  /**
   * Excluding an item that was already sent removes it.
   */
  public function testExclusionRemovesDocument(): void {
    $node = $this->createArticle();
    $this->drainQueue();

    $this->container->get('wahosearch_connector.content_selection')->setExcluded('node', $node->id(), TRUE);
    $this->syncManager()->queueNode((int) $node->id());
    $this->drainQueue();

    $this->assertSame(['node:' . $node->id() . ':en'], $this->deletedIds());
  }

  /**
   * Content types that are not selected never leave the site.
   */
  public function testDisabledContentTypeNotQueued(): void {
    Node::create(['type' => 'page', 'title' => 'Internal', 'status' => 1, 'uid' => 0])->save();
    $this->assertSame(0, $this->syncManager()->getQueueSize());
  }

  /**
   * Switching a content type off removes what was sent.
   */
  public function testContentTypeRemoval(): void {
    $node = $this->createArticle();
    $this->drainQueue();

    $this->config('wahosearch_connector.settings')
      ->set('bundles', ['article' => ['enabled' => FALSE, 'include_private' => FALSE]])
      ->save();
    $this->assertSame(1, $this->syncManager()->queueBundleRemoval('article'));
    $this->drainQueue();

    $this->assertSame(['node:' . $node->id() . ':en'], $this->deletedIds());
  }

  /**
   * While the platform is down, work stays queued.
   */
  public function testRetryableFailureKeepsItem(): void {
    $node = $this->createArticle();
    RecordingApiClient::$failWith = new ApiException('Down', 503, TRUE);

    $this->assertSame(0, $this->syncManager()->processQueue(5));
    $this->assertSame(1, $this->syncManager()->getQueueSize());

    RecordingApiClient::$failWith = NULL;
    $this->resetQueueLease();
    $this->drainQueue();
    $this->assertSame(['node:' . $node->id() . ':en'], $this->sentIds());
  }

  /**
   * A refused document is reported and can be retried.
   */
  public function testPermanentFailureIsRecorded(): void {
    $node = $this->createArticle();
    RecordingApiClient::$failWith = new ApiException('Bad data', 422, FALSE);

    $this->drainQueue();

    $tracker = $this->container->get('wahosearch_connector.tracker');
    $this->assertSame(0, $this->syncManager()->getQueueSize());
    $this->assertSame(1, $tracker->countByStatus()[ItemTracker::STATUS_ERROR]);
    $this->assertSame('Bad data', $this->container->get('wahosearch_connector.sync_log')->getLastError()->message);

    RecordingApiClient::$failWith = NULL;
    $this->assertSame(1, $this->syncManager()->queueFailed());
    $this->drainQueue();
    $this->assertSame(['node:' . $node->id() . ':en'], $this->sentIds());
    $this->assertSame(0, $tracker->countByStatus()[ItemTracker::STATUS_ERROR]);
  }

  /**
   * One rejected document does not fail the others.
   */
  public function testPartialRejection(): void {
    $first = $this->createArticle();
    $second = $this->createArticle(['title' => 'Second']);
    RecordingApiClient::$bulkResponse = [
      'rejected' => [
        ['id' => 'node:' . $second->id() . ':en', 'message' => 'Too large'],
      ],
    ];

    $failed = $this->syncManager()->syncNodes([(int) $first->id(), (int) $second->id()]);

    $this->assertSame(1, $failed);
    $counts = $this->container->get('wahosearch_connector.tracker')->countByStatus();
    $this->assertSame(1, $counts[ItemTracker::STATUS_INDEXED]);
    $this->assertSame(1, $counts[ItemTracker::STATUS_ERROR]);
  }

  /**
   * Renaming a term updates the term and the nodes using it.
   */
  public function testTermRenameUpdatesNodes(): void {
    $term = $this->createTerm('Old name');
    $node = $this->createArticle(['field_topics' => [$term->id()]]);
    $this->drainQueue();
    RecordingApiClient::$calls = [];

    $term->setName('New name')->save();
    $this->drainQueue();

    $this->assertSame('New name', RecordingApiClient::bodies('taxonomy/bulk')[0]['terms'][0]['names']['en']);
    $this->assertSame(['node:' . $node->id() . ':en'], $this->sentIds());
  }

  /**
   * Deleting a term removes it and refreshes the nodes that used it.
   */
  public function testTermDeleteUpdatesNodes(): void {
    $term = $this->createTerm('Going away');
    $tid = $term->id();
    $node = $this->createArticle(['field_topics' => [$tid]]);
    $this->drainQueue();
    RecordingApiClient::$calls = [];

    $term->delete();
    $this->drainQueue();

    $this->assertSame(['taxonomy_term:' . $tid], RecordingApiClient::bodies('taxonomy/delete')[0]['ids']);
    $this->assertSame([], RecordingApiClient::bodies('content/bulk')[0]['documents'][0]['taxonomy']);
    $this->assertSame('node:' . $node->id() . ':en', RecordingApiClient::bodies('content/bulk')[0]['documents'][0]['id']);
  }

  /**
   * The full sync sends everything and closes the run.
   */
  public function testFullSync(): void {
    $term = $this->createTerm('Education');
    $first = $this->createArticle(['field_topics' => [$term->id()]]);
    $this->drainQueue();
    RecordingApiClient::$calls = [];

    // Unchanged content is still sent, so the platform knows it exists.
    $results = $this->runFullSync();

    $this->assertTrue($results['processed'] === 1);
    $body = RecordingApiClient::bodies('content/bulk')[0];
    $this->assertSame('run-1', $body['sync_id']);
    $this->assertSame('node:' . $first->id() . ':en', $body['documents'][0]['id']);
    $this->assertSame('run-1', RecordingApiClient::bodies('taxonomy/bulk')[0]['sync_id']);
    $this->assertSame([['sync_id' => 'run-1']], RecordingApiClient::bodies('sync/full/finish'));
    $this->assertNotNull($this->syncManager()->getLastFullSync());
  }

  /**
   * A broken run must not be closed, or good content would be deleted.
   */
  public function testIncompleteFullSyncIsNotClosed(): void {
    $this->createArticle();
    $this->drainQueue();
    RecordingApiClient::$calls = [];
    RecordingApiClient::$bulkResponse = ['rejected' => [['id' => 'node:1:en', 'message' => 'Refused']]];

    $results = $this->runFullSync();

    $this->assertSame(1, $results['failed']);
    $this->assertSame([], RecordingApiClient::bodies('sync/full/finish'));
    $this->assertNull($this->syncManager()->getLastFullSync());
  }

  /**
   * An outage during the run stops it without closing it.
   */
  public function testFullSyncOutageIsNotClosed(): void {
    $this->createArticle();
    $this->drainQueue();
    RecordingApiClient::$calls = [];

    $context = ['results' => [], 'sandbox' => [], 'finished' => 1, 'message' => ''];
    SyncBatch::startFullSync($context);
    RecordingApiClient::$failWith = new ApiException('Down', 503, TRUE);
    SyncBatch::syncBundle('article', $context);
    RecordingApiClient::$failWith = NULL;
    SyncBatch::finishFullSync(TRUE, $context['results'], []);

    $this->assertSame('Down', $context['results']['fatal']);
    $this->assertSame([], RecordingApiClient::bodies('sync/full/finish'));
  }

  /**
   * Immediate mode sends after the response, only when something changed.
   */
  public function testImmediateModeUsesDestruct(): void {
    $this->config('wahosearch_connector.settings')->set('immediate', TRUE)->save();

    $this->syncManager()->destruct();
    $this->assertSame([], RecordingApiClient::$calls);

    $node = $this->createArticle();
    $this->assertSame([], RecordingApiClient::$calls);
    $this->syncManager()->destruct();
    $this->assertSame(['node:' . $node->id() . ':en'], $this->sentIds());
  }

  /**
   * A platform error in immediate mode never reaches the editor.
   */
  public function testImmediateModeSwallowsErrors(): void {
    $this->config('wahosearch_connector.settings')->set('immediate', TRUE)->save();
    $this->createArticle();
    RecordingApiClient::$failWith = new \RuntimeException('Unexpected');

    $this->syncManager()->destruct();

    $this->assertSame('Unexpected', $this->container->get('wahosearch_connector.sync_log')->getLastError()->message);
  }

  /**
   * Runs every step of the full sync batch.
   */
  private function runFullSync(): array {
    $context = ['results' => [], 'sandbox' => [], 'finished' => 1, 'message' => ''];
    SyncBatch::startFullSync($context);
    do {
      $context['finished'] = 1;
      SyncBatch::syncBundle('article', $context);
    } while ($context['finished'] < 1);
    SyncBatch::syncTerms($context);
    SyncBatch::finishFullSync(TRUE, $context['results'], []);
    return $context['results'];
  }

  /**
   * Makes released items claimable again.
   */
  private function resetQueueLease(): void {
    $this->container->get('database')->update('queue')->fields(['expire' => 0])->execute();
  }

}
