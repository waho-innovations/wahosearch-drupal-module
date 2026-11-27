<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Form;

use Drupal\wahosearch_connector\Service\ContentSelection;
use Drupal\wahosearch_connector\Service\SyncLog;
use Drupal\wahosearch_connector\Service\SyncManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * URL path rules and priority sections, with a preview before saving.
 */
final class IndexingRulesForm extends FormBase {

  public function __construct(
    protected ContentSelection $selection,
    protected SyncManager $syncManager,
    protected SyncLog $log,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wahosearch_connector.content_selection'),
      $container->get('wahosearch_connector.sync_manager'),
      $container->get('wahosearch_connector.sync_log'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wahosearch_connector_indexing_rules';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('wahosearch_connector.settings');
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('One path per line. Use * as a wildcard, for example /news/*. These rules apply on top of the content types you selected.') . '</p>',
    ];
    $form['include_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Only index these paths'),
      '#description' => $this->t('Leave empty to allow every path.'),
      '#default_value' => $config->get('include_paths'),
      '#rows' => 4,
    ];
    $form['exclude_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Never index these paths'),
      '#description' => $this->t('These always win over the list above.'),
      '#default_value' => $config->get('exclude_paths'),
      '#rows' => 4,
    ];
    $form['priority_sections'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Priority sections'),
      '#description' => $this->t('Most important first. Each line must be a whole section ending in /*, for example /admissions/*. Single pages cannot be prioritized, so results stay relevance first.'),
      '#default_value' => $config->get('priority_sections'),
      '#rows' => 4,
    ];

    $preview = $form_state->get('preview');
    if ($preview) {
      $form['preview'] = [
        '#type' => 'table',
        '#caption' => $this->t('Effect of these rules on published content'),
        '#header' => [$this->t('Change'), $this->t('Items'), $this->t('Examples')],
        '#rows' => [
          [$this->t('Will be added'), (string) $preview['add'], implode(', ', $preview['add_samples'])],
          [$this->t('Will be removed'), (string) $preview['remove'], implode(', ', $preview['remove_samples'])],
        ],
      ];
    }
    $form['confirm_removal'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand that these rules can remove content from search'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview changes'),
      '#submit' => ['::submitPreview'],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save rules'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['include_paths', 'exclude_paths'] as $field) {
      $invalid = ContentSelection::invalidPatterns((string) $form_state->getValue($field));
      if ($invalid) {
        $form_state->setErrorByName($field, $this->t('Paths must start with /: @lines', ['@lines' => implode(', ', $invalid)]));
      }
    }
    $invalid = ContentSelection::invalidPatterns((string) $form_state->getValue('priority_sections'), TRUE);
    if ($invalid) {
      $form_state->setErrorByName('priority_sections', $this->t('Priority sections must start with / and end with /*: @lines', ['@lines' => implode(', ', $invalid)]));
    }
    if ($form_state->getErrors() || ($form_state->getTriggeringElement()['#parents'][0] ?? '') !== 'submit') {
      return;
    }
    $preview = $this->preview($form_state);
    if ($preview['remove'] > 0 && !$form_state->getValue('confirm_removal')) {
      $form_state->setErrorByName('confirm_removal', $this->t('These rules remove about @count items from search. Tick the confirmation to continue.', ['@count' => $preview['remove']]));
    }
  }

  /**
   * Shows what the rules would change without saving them.
   */
  public function submitPreview(array &$form, FormStateInterface $form_state): void {
    $form_state->set('preview', $this->preview($form_state));
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $preview = $this->preview($form_state);
    $this->configFactory()->getEditable('wahosearch_connector.settings')
      ->set('include_paths', ContentSelection::cleanPatterns((string) $form_state->getValue('include_paths')))
      ->set('exclude_paths', ContentSelection::cleanPatterns((string) $form_state->getValue('exclude_paths')))
      ->set('priority_sections', ContentSelection::cleanPatterns((string) $form_state->getValue('priority_sections')))
      ->save();
    $this->log->info('config', sprintf('Indexing rules changed by user %d. About %d items added, %d removed.', $this->currentUser()->id(), $preview['add'], $preview['remove']));
    $this->syncManager->requestReconciliation();
    $this->messenger()->addStatus($this->t('The rules were saved. The search index will be updated in the background.'));
  }

  /**
   * Works out the effect of the submitted rules.
   */
  private function preview(FormStateInterface $form_state): array {
    return $this->selection->previewPathRules(
      ContentSelection::cleanPatterns((string) $form_state->getValue('include_paths')),
      ContentSelection::cleanPatterns((string) $form_state->getValue('exclude_paths')),
    );
  }

}
