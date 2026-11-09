<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Plugin\QueueWorker;

use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\wahosearch_connector\Service\SyncManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends queued content changes to the platform on cron.
 *
 * @QueueWorker(
 *   id = "wahosearch_connector_sync",
 *   title = @Translation("WahoSearch Connector synchronization"),
 *   cron = {"time" = 30}
 * )
 */
final class SyncQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SyncManager $syncManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('wahosearch_connector.sync_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    try {
      $this->syncManager->processItem($data);
    }
    catch (ApiException $e) {
      // The platform is down or busy. Keep the item and stop this cron run.
      throw new SuspendQueueException($e->getMessage(), 0, $e);
    }
  }

}
