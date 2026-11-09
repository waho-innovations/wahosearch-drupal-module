<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Drush\Commands;

use Drupal\wahosearch_connector\Batch\SyncBatch;
use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\wahosearch_connector\Service\ApiClient;
use Drupal\wahosearch_connector\Service\ItemTracker;
use Drupal\wahosearch_connector\Service\SyncManager;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Command line controls for the connector.
 */
final class WahoSearchCommands extends DrushCommands {

  public function __construct(
    private readonly SyncManager $syncManager,
    private readonly ItemTracker $tracker,
    private readonly ApiClient $apiClient,
  ) {
    parent::__construct();
  }

  /**
   * Creates the command object.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('wahosearch_connector.sync_manager'),
      $container->get('wahosearch_connector.tracker'),
      $container->get('wahosearch_connector.api_client'),
    );
  }

  /**
   * Shows the synchronization status.
   */
  #[CLI\Command(name: 'wahosearch:status', aliases: ['wss'])]
  public function status(): void {
    $counts = $this->tracker->countByStatus();
    $last = $this->syncManager->getLastFullSync();
    $this->io()->writeln('Configured: ' . ($this->apiClient->isConfigured() ? 'yes' : 'no'));
    $this->io()->writeln('Indexed documents: ' . $counts[ItemTracker::STATUS_INDEXED]);
    $this->io()->writeln('Documents with errors: ' . $counts[ItemTracker::STATUS_ERROR]);
    $this->io()->writeln('Changes waiting: ' . $this->syncManager->getQueueSize());
    $this->io()->writeln('Last full synchronization: ' . ($last ? gmdate('c', $last) : 'never'));
  }

  /**
   * Tests the connection to the platform.
   */
  #[CLI\Command(name: 'wahosearch:test', aliases: ['wst'])]
  public function test(): void {
    try {
      $response = $this->apiClient->ping();
      $this->io()->success('Connected as ' . ($response['site_name'] ?? 'this site'));
    }
    catch (ApiException $e) {
      $this->io()->error($e->getMessage());
    }
  }

  /**
   * Runs a full synchronization.
   */
  #[CLI\Command(name: 'wahosearch:full-sync', aliases: ['wsf'])]
  public function fullSync(): void {
    batch_set(SyncBatch::fullSync());
    drush_backend_batch_process();
  }

  /**
   * Sends the changes waiting in the queue.
   */
  #[CLI\Command(name: 'wahosearch:send', aliases: ['wso'])]
  #[CLI\Option(name: 'seconds', description: 'How long to keep sending.')]
  public function send(array $options = ['seconds' => 60]): void {
    $count = $this->syncManager->processQueue((int) $options['seconds']);
    $this->io()->writeln($count . ' changes processed, ' . $this->syncManager->getQueueSize() . ' left.');
  }

}
