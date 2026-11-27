<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Kernel;

use Drupal\wahosearch_connector\Service\ContentSelection;
use Drupal\wahosearch_connector\Service\ItemTracker;
use Drupal\wahosearch_connector\Service\SyncManager;
use Drupal\node\NodeInterface;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\user\RoleInterface;

/**
 * Tests path rules, priorities, reconciliation and held back documents.
 *
 * @group wahosearch_connector
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class IndexingRulesTest extends ConnectorKernelTestBase {

  /**
   * Creates an article with an alias.
   */
  private function articleAt(string $alias, string $title = 'An article'): NodeInterface {
    $node = $this->createArticle(['title' => $title]);
    PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => $alias, 'langcode' => 'en'])->save();
    return $node;
  }

  /**
   * Sets one or more connector settings.
   */
  private function setRules(array $values): void {
    $config = $this->config('wahosearch_connector.settings');
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Include and exclude rules decide what is sent. Exclusions win.
   */
  public function testPathRules(): void {
    $news = $this->articleAt('/news/one');
    $this->articleAt('/about/us');
    $hidden = $this->articleAt('/news/internal');
    $this->setRules(['include_paths' => '/news/*', 'exclude_paths' => '/news/internal']);
    $this->drainQueue();
    $this->assertSame(['node:' . $news->id() . ':en'], $this->sentIds());
    $this->assertNotContains('node:' . $hidden->id() . ':en', $this->sentIds());
  }

  /**
   * The internal path can be used as well as the alias.
   */
  public function testInternalPathMatches(): void {
    $node = $this->articleAt('/news/one');
    $this->setRules(['exclude_paths' => '/node/' . $node->id()]);
    $this->drainQueue();
    $this->assertSame([], $this->sentIds());
  }

  /**
   * Content that stops matching is removed by a reconciliation.
   */
  public function testRuleChangeRemovesContent(): void {
    $node = $this->articleAt('/news/one');
    $this->drainQueue();
    $this->setRules(['exclude_paths' => '/news/*']);
    $this->syncManager()->requestReconciliation();
    $this->syncManager()->reconcileIfDue();
    $this->drainQueue();
    $this->assertSame(['node:' . $node->id() . ':en'], $this->deletedIds());
  }

  /**
   * The first matching section gives the rank. Others have no priority.
   */
  public function testPrioritySections(): void {
    $this->setRules(['priority_sections' => "/admissions/*\n/news/*"]);
    $this->articleAt('/news/one');
    $this->articleAt('/other/page');
    $this->drainQueue();
    $documents = RecordingApiClient::bodies('content/bulk');
    $sent = array_column(array_merge(...array_column($documents, 'documents')), NULL, 'title');
    $this->assertCount(1, $sent, 'Both articles have the same title in this test.');

    RecordingApiClient::reset();
    $this->articleAt('/news/two', 'Priority one');
    $this->articleAt('/other/two', 'No priority');
    $this->drainQueue();
    $sent = array_column(array_merge(...array_column(RecordingApiClient::bodies('content/bulk'), 'documents')), NULL, 'title');
    $this->assertSame(['pattern' => '/news/*', 'rank' => 2], $sent['Priority one']['priority_section']);
    $this->assertArrayNotHasKey('priority_section', $sent['No priority']);
  }

  /**
   * Only whole sections can be given a priority.
   */
  public function testPatternValidation(): void {
    $this->assertSame([], ContentSelection::invalidPatterns("/news/*\n\n<front>\n/a/b"));
    $this->assertSame(['news'], ContentSelection::invalidPatterns("news\n/ok"));
    $this->assertSame(['/news/one'], ContentSelection::invalidPatterns("/news/*\n/news/one", TRUE));
    $this->assertSame("/a/*\n/b", ContentSelection::cleanPatterns("  /a/* \r\n\r\n/b\n"));
  }

  /**
   * The preview reports both directions without changing anything.
   */
  public function testPreview(): void {
    $this->articleAt('/news/one', 'News item');
    $this->articleAt('/about/us', 'About page');
    $this->setRules(['exclude_paths' => '/about/*']);
    $selection = $this->container->get('wahosearch_connector.content_selection');

    $preview = $selection->previewPathRules('', '/news/*');
    $this->assertSame(1, $preview['add']);
    $this->assertSame(['About page'], $preview['add_samples']);
    $this->assertSame(1, $preview['remove']);
    $this->assertSame(['News item'], $preview['remove_samples']);
    $this->assertSame('/about/*', $this->config('wahosearch_connector.settings')->get('exclude_paths'));
  }

  /**
   * The schedule only runs when due and when nothing is waiting.
   */
  public function testScheduledReconciliation(): void {
    $this->createArticle();
    $state = $this->container->get('state');
    $manager = $this->syncManager();
    $this->setRules(['reconcile_days' => 7]);

    $manager->reconcileIfDue();
    $this->assertNull($manager->getLastReconcile(), 'A waiting queue postpones the run.');

    $this->drainQueue();
    $manager->reconcileIfDue();
    $this->assertNotNull($manager->getLastReconcile(), 'A first run happens once the queue is empty.');
    $this->assertSame(1, $manager->getQueueSize());
    $this->drainQueue();
    $this->assertCount(1, $this->sentIds(), 'Unchanged content is compared but not sent again.');

    $manager->reconcileIfDue();
    $this->assertSame(0, $manager->getQueueSize(), 'Not due yet.');

    $state->set(SyncManager::STATE_LAST_RECONCILE, $this->container->get('datetime.time')->getCurrentTime() - 8 * 86400);
    $manager->reconcileIfDue();
    $this->assertSame(1, $manager->getQueueSize(), 'Due again after the interval.');
    $this->drainQueue();

    $this->setRules(['reconcile_days' => 0]);
    $state->set(SyncManager::STATE_LAST_RECONCILE, 0);
    $manager->reconcileIfDue();
    $this->assertSame(0, $manager->getQueueSize(), 'Zero turns the schedule off.');
  }

  /**
   * Terms are part of a reconciliation.
   */
  public function testReconciliationIncludesTerms(): void {
    $term = $this->createTerm('Scholarships');
    $this->createArticle(['field_topics' => [['target_id' => $term->id()]]]);
    $this->drainQueue();
    RecordingApiClient::reset();
    $this->syncManager()->queueReconciliation();
    $this->drainQueue();
    $terms = array_merge(...array_column(RecordingApiClient::bodies('taxonomy/bulk'), 'terms'));
    $this->assertSame(['taxonomy_term:' . $term->id()], array_column($terms, 'id'));
  }

  /**
   * Taking away view access removes public content after the request.
   */
  public function testPermissionChangeRemovesContent(): void {
    $node = $this->createArticle();
    $this->drainQueue();

    user_role_revoke_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $this->assertTrue((bool) $this->container->get('state')->get(SyncManager::STATE_RECONCILE_REQUESTED));
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();

    $this->syncManager()->destruct();
    $this->assertFalse((bool) $this->container->get('state')->get(SyncManager::STATE_RECONCILE_REQUESTED));
    $this->assertSame(1, $this->syncManager()->getQueueSize());
    $this->drainQueue();
    $this->assertSame(['node:' . $node->id() . ':en'], $this->deletedIds());
  }

  /**
   * Saving a role without changing permissions does nothing.
   */
  public function testUnchangedRoleDoesNotReconcile(): void {
    $this->container->get('entity_type.manager')->getStorage('user_role')->load('member')->save();
    $this->assertFalse((bool) $this->container->get('state')->get(SyncManager::STATE_RECONCILE_REQUESTED));
  }

  /**
   * Automatic sync being off also stops permission driven runs.
   */
  public function testPermissionChangeRespectsAutoSync(): void {
    $this->setRules(['sync_on_save' => FALSE]);
    user_role_revoke_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $this->assertFalse((bool) $this->container->get('state')->get(SyncManager::STATE_RECONCILE_REQUESTED));
  }

  /**
   * Held back documents are not failures and are offered again.
   */
  public function testDeferredDocuments(): void {
    $node = $this->createArticle();
    $id = 'node:' . $node->id() . ':en';
    RecordingApiClient::$bulkResponse = ['deferred' => [['id' => $id, 'message' => 'Page quota reached']]];

    $this->assertSame(0, $this->syncManager()->syncNodes([(int) $node->id()]), 'A held back document does not count as failed.');
    $tracker = $this->container->get('wahosearch_connector.tracker');
    $this->assertSame(1, $tracker->countByStatus()[ItemTracker::STATUS_DEFERRED]);
    $this->assertNull($tracker->getHash($id));
    $this->assertNull($this->syncManager()->getLastSuccess(), 'Nothing was delivered yet.');

    RecordingApiClient::$bulkResponse = [];
    $this->syncManager()->syncNodes([(int) $node->id()]);
    $this->assertCount(2, $this->sentIds(), 'It is offered again and can then be accepted.');
    $this->assertSame(0, $tracker->countByStatus()[ItemTracker::STATUS_DEFERRED]);
    $this->assertNotNull($this->syncManager()->getLastSuccess());
  }

}
