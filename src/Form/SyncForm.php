<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Form;

use Drupal\wahosearch_connector\Batch\SyncBatch;
use Drupal\wahosearch_connector\Service\ApiClient;
use Drupal\wahosearch_connector\Service\ItemTracker;
use Drupal\wahosearch_connector\Service\SyncLog;
use Drupal\wahosearch_connector\Service\SyncManager;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Synchronization status and manual controls.
 */
final class SyncForm extends FormBase {

  public function __construct(
    private readonly SyncManager $syncManager,
    private readonly ItemTracker $tracker,
    private readonly SyncLog $log,
    private readonly ApiClient $apiClient,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wahosearch_connector.sync_manager'),
      $container->get('wahosearch_connector.tracker'),
      $container->get('wahosearch_connector.sync_log'),
      $container->get('wahosearch_connector.api_client'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wahosearch_connector_sync';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $configured = $this->apiClient->isConfigured();

    if (!$configured) {
      $form['not_configured'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'warning' => [
            $this->t('The connection is not set up yet. Please complete the <a href=":url">connection settings</a> first.', [
              ':url' => Url::fromRoute('wahosearch_connector.settings')->toString(),
            ]),
          ],
        ],
        '#status_headings' => ['warning' => $this->t('Warning message')],
      ];
    }

    $counts = $this->tracker->countByStatus();
    $lastFull = $this->syncManager->getLastFullSync();
    $lastSuccess = $this->syncManager->getLastSuccess();
    $lastReconcile = $this->syncManager->getLastReconcile();
    $lastError = $this->log->getLastError();

    $form['status'] = [
      '#type' => 'table',
      '#caption' => $this->t('Current status'),
      '#header' => [$this->t('Item'), $this->t('Value')],
      '#rows' => [
        [$this->t('Indexed documents'), (string) $counts[ItemTracker::STATUS_INDEXED]],
        [$this->t('Documents with errors'), (string) $counts[ItemTracker::STATUS_ERROR]],
        [$this->t('Documents held back by the platform'), (string) $counts[ItemTracker::STATUS_DEFERRED]],
        [$this->t('Changes waiting to be sent'), (string) $this->syncManager->getQueueSize()],
        [
          $this->t('Last full synchronization'),
          $lastFull ? $this->dateFormatter->format($lastFull, 'short') : $this->t('Never'),
        ],
        [
          $this->t('Last successful synchronization'),
          $lastSuccess ? $this->dateFormatter->format($lastSuccess, 'short') : $this->t('Never'),
        ],
        [
          $this->t('Last reconciliation'),
          $lastReconcile ? $this->dateFormatter->format($lastReconcile, 'short') : $this->t('Never'),
        ],
        [
          $this->t('Last error'),
          $lastError ? $this->dateFormatter->format((int) $lastError->created, 'short') . ': ' . $lastError->message : $this->t('None'),
        ],
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['process'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send pending changes now'),
      '#submit' => ['::submitProcess'],
      '#disabled' => !$configured,
      '#limit_validation_errors' => [],
    ];
    $form['actions']['retry'] = [
      '#type' => 'submit',
      '#value' => $this->t('Retry failed items'),
      '#submit' => ['::submitRetry'],
      '#disabled' => !$configured,
      '#limit_validation_errors' => [],
    ];
    $form['actions']['full'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run full synchronization'),
      '#submit' => ['::submitFull'],
      '#disabled' => !$configured,
      '#button_type' => 'primary',
      '#limit_validation_errors' => [],
    ];

    $form['single'] = [
      '#type' => 'details',
      '#title' => $this->t('Update a single item'),
      '#open' => FALSE,
    ];
    $form['single']['nid'] = [
      '#type' => 'number',
      '#title' => $this->t('Node ID'),
      '#min' => 1,
    ];
    $form['single']['send'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send this item'),
      '#submit' => ['::submitSingle'],
      '#disabled' => !$configured,
      '#limit_validation_errors' => [['nid']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Each button has its own submit handler.
  }

  /**
   * Sends pending changes.
   */
  public function submitProcess(array &$form, FormStateInterface $form_state): void {
    batch_set(SyncBatch::processQueue());
  }

  /**
   * Queues failed items and sends them.
   */
  public function submitRetry(array &$form, FormStateInterface $form_state): void {
    $count = $this->syncManager->queueFailed();
    $this->messenger()->addStatus($this->t('@count items were queued to update the search index.', ['@count' => $count]));
    batch_set(SyncBatch::processQueue());
  }

  /**
   * Starts a full synchronization.
   */
  public function submitFull(array &$form, FormStateInterface $form_state): void {
    batch_set(SyncBatch::fullSync());
  }

  /**
   * Sends one node.
   */
  public function submitSingle(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->getValue('nid');
    if ($nid < 1 || !$this->entityTypeManager->getStorage('node')->load($nid)) {
      $this->messenger()->addError($this->t('No content was found with that ID.'));
      return;
    }
    $this->syncManager->queueNode($nid);
    batch_set(SyncBatch::processQueue());
  }

}
