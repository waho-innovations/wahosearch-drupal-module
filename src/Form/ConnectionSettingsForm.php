<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Form;

use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\wahosearch_connector\Service\ApiClient;
use Drupal\wahosearch_connector\Service\SyncLog;
use Drupal\wahosearch_connector\Service\SyncManager;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Connection settings. Saving also tests the connection.
 */
final class ConnectionSettingsForm extends ConfigFormBase {

  /**
   * The API client.
   */
  private ApiClient $apiClient;

  /**
   * The sync manager.
   */
  private SyncManager $syncManager;

  /**
   * The sync log.
   */
  private SyncLog $log;

  /**
   * The date formatter.
   */
  private DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->apiClient = $container->get('wahosearch_connector.api_client');
    $instance->syncManager = $container->get('wahosearch_connector.sync_manager');
    $instance->log = $container->get('wahosearch_connector.sync_log');
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wahosearch_connector_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['wahosearch_connector.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('wahosearch_connector.settings');

    $status = $this->syncManager->getConnectionStatus();
    $lastSuccess = $this->syncManager->getLastSuccess();
    $form['#cache']['max-age'] = 0;
    $form['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Connection status'),
      '#markup' => match (TRUE) {
        $status === NULL => $this->t('Not tested yet.'),
        $status['ok'] => $this->t('Connected, checked @date.', ['@date' => $this->dateFormatter->format($status['time'], 'short')]),
        default => $this->t('Not connected, checked @date: @reason', [
          '@date' => $this->dateFormatter->format($status['time'], 'short'),
          '@reason' => $status['message'],
        ]),
      },
    ];
    $form['last_success'] = [
      '#type' => 'item',
      '#title' => $this->t('Last successful synchronization'),
      '#markup' => $lastSuccess ? $this->dateFormatter->format($lastSuccess, 'short') : $this->t('Never'),
    ];

    $form['connection'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Platform connection'),
    ];
    $form['connection']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Platform URL'),
      '#description' => $this->t('The address of the search platform. It must use HTTPS.'),
      '#default_value' => $config->get('endpoint'),
      '#required' => TRUE,
    ];
    $form['connection']['site_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site ID'),
      '#description' => $this->t('Shown on the website page of the platform dashboard.'),
      '#default_value' => $config->get('site_id'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];
    if ($this->apiClient->isSecretOverridden()) {
      $form['connection']['secret_notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Secret key'),
        '#markup' => $this->t('The secret key is set in settings.php.'),
      ];
    }
    else {
      $hasSecret = (bool) $this->apiClient->getSecret();
      $form['connection']['secret'] = [
        '#type' => 'password',
        '#title' => $this->t('Secret key'),
        '#description' => $hasSecret
          ? $this->t('A secret key is saved. Leave this empty to keep it, or enter a new one to replace it.')
          : $this->t('Shown once when the website is created on the platform.'),
        '#required' => !$hasSecret,
        '#attributes' => ['autocomplete' => 'new-password'],
      ];
    }

    $form['behaviour'] = [
      '#type' => 'details',
      '#title' => $this->t('Synchronization behaviour'),
      '#open' => FALSE,
    ];
    $form['behaviour']['sync_on_save'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update the search index automatically when content changes'),
      '#default_value' => $config->get('sync_on_save'),
    ];
    $form['behaviour']['immediate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send changes right after saving instead of waiting for cron'),
      '#default_value' => $config->get('immediate'),
      '#states' => ['visible' => [':input[name="sync_on_save"]' => ['checked' => TRUE]]],
    ];
    $form['behaviour']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Items per request'),
      '#default_value' => $config->get('batch_size'),
      '#min' => 1,
      '#max' => 200,
      '#required' => TRUE,
    ];
    $form['behaviour']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request timeout in seconds'),
      '#default_value' => $config->get('timeout'),
      '#min' => 1,
      '#max' => 120,
      '#required' => TRUE,
    ];
    $form['behaviour']['log_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to keep log entries'),
      '#default_value' => $config->get('log_retention_days'),
      '#min' => 1,
      '#max' => 365,
      '#required' => TRUE,
    ];

    $form['behaviour']['reconcile_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days between automatic reconciliation runs'),
      '#description' => $this->t('Compares all content with the platform and fixes differences. Enter 0 to turn this off.'),
      '#default_value' => $config->get('reconcile_days'),
      '#min' => 0,
      '#max' => 90,
      '#required' => TRUE,
    ];
    $form['behaviour']['pdf_max_mb'] = [
      '#type' => 'number',
      '#title' => $this->t('Largest PDF to send, in megabytes'),
      '#default_value' => $config->get('pdf_max_mb'),
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#value'] = $this->t('Save and test connection');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $endpoint = trim((string) $form_state->getValue('endpoint'));
    if ($endpoint !== '' && ApiClient::validateEndpoint($endpoint) !== NULL) {
      $form_state->setErrorByName('endpoint', $this->t('The platform URL must use HTTPS. Plain HTTP is only allowed on local development hosts.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('wahosearch_connector.settings')
      ->set('endpoint', rtrim(trim((string) $form_state->getValue('endpoint')), '/'))
      ->set('site_id', trim((string) $form_state->getValue('site_id')))
      ->set('sync_on_save', (bool) $form_state->getValue('sync_on_save'))
      ->set('immediate', (bool) $form_state->getValue('immediate'))
      ->set('batch_size', (int) $form_state->getValue('batch_size'))
      ->set('timeout', (int) $form_state->getValue('timeout'))
      ->set('log_retention_days', (int) $form_state->getValue('log_retention_days'))
      ->set('reconcile_days', (int) $form_state->getValue('reconcile_days'))
      ->set('pdf_max_mb', (int) $form_state->getValue('pdf_max_mb'))
      ->save();

    $secret = (string) $form_state->getValue('secret');
    if ($secret !== '') {
      $replaced = (bool) $this->apiClient->getSecret();
      $this->apiClient->setSecret($secret);
      $this->log->info('connection', $replaced ? 'Secret key replaced.' : 'Secret key saved.');
    }

    parent::submitForm($form, $form_state);

    try {
      $response = $this->syncManager->testConnection();
      $this->messenger()->addStatus($this->t('Connected to the platform as %site.', [
        '%site' => (string) ($response['site_name'] ?? $form_state->getValue('site_id')),
      ]));
    }
    catch (ApiException $e) {
      $this->messenger()->addError($this->t('The settings were saved, but the connection failed: @reason', ['@reason' => $e->getMessage()]));
    }
  }

}
