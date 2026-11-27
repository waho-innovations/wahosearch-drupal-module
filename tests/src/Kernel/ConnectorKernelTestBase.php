<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Kernel;

use Drupal\wahosearch_connector\Service\SyncManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Shared setup, a bilingual site with one indexed content type.
 */
abstract class ConnectorKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'language',
    'content_translation',
    'path_alias',
    'file',
    'wahosearch_connector',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('wahosearch_connector.api_client')->setClass(RecordingApiClient::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('wahosearch_connector', [
      'wahosearch_connector_item',
      'wahosearch_connector_excluded',
      'wahosearch_connector_log',
    ]);
    $this->installConfig(['system', 'filter', 'node', 'language', 'wahosearch_connector']);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
    // Drupal 10 ships this storage with the node module, Drupal 11 does not.
    if (!FieldStorageConfig::loadByName('node', 'body')) {
      FieldStorageConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'type' => 'text_with_summary',
      ])->save();
    }
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Body',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_topics',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_topics',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Topics',
      'settings' => ['handler_settings' => ['target_bundles' => ['topics' => 'topics']]],
    ])->save();

    $manager = $this->container->get('content_translation.manager');
    $manager->setEnabled('node', 'article', TRUE);
    $manager->setEnabled('taxonomy_term', 'topics', TRUE);
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('router.builder')->rebuild();

    Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous'])->save();
    Role::create(['id' => RoleInterface::AUTHENTICATED_ID, 'label' => 'Authenticated'])->save();
    Role::create(['id' => 'member', 'label' => 'Member'])->save();
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID, 'member'] as $role) {
      user_role_grant_permissions($role, ['access content']);
    }

    $this->config('wahosearch_connector.settings')
      ->set('bundles', ['article' => ['enabled' => TRUE, 'include_private' => FALSE]])
      ->set('sync_on_save', TRUE)
      ->set('immediate', FALSE)
      ->save();

    $this->container->get('state')->delete(SyncManager::STATE_RECONCILE_REQUESTED);
    RecordingApiClient::reset();
  }

  /**
   * Returns the sync manager.
   */
  protected function syncManager(): SyncManager {
    return $this->container->get('wahosearch_connector.sync_manager');
  }

  /**
   * Creates a published article.
   */
  protected function createArticle(array $values = []): NodeInterface {
    $node = Node::create($values + [
      'type' => 'article',
      'title' => 'Engineering scholarships',
      'langcode' => 'en',
      'status' => 1,
      'uid' => 0,
      'body' => ['value' => '<p>First paragraph.</p><p>Second &amp; last.</p>', 'format' => 'plain_text'],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a term.
   */
  protected function createTerm(string $name, array $values = []): TermInterface {
    $term = Term::create($values + ['vid' => 'topics', 'name' => $name, 'langcode' => 'en']);
    $term->save();
    return $term;
  }

  /**
   * Sends everything waiting in the queue.
   */
  protected function drainQueue(): void {
    $this->syncManager()->processQueue(30);
  }

  /**
   * Returns every document ID sent so far.
   */
  protected function sentIds(): array {
    $ids = [];
    foreach (RecordingApiClient::bodies('content/bulk') as $body) {
      $ids = array_merge($ids, array_column($body['documents'], 'id'));
    }
    return $ids;
  }

  /**
   * Returns every document ID removed so far.
   */
  protected function deletedIds(): array {
    $ids = [];
    foreach (RecordingApiClient::bodies('content/delete') as $body) {
      $ids = array_merge($ids, $body['ids']);
    }
    return $ids;
  }

}
