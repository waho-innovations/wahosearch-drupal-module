<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Form;

use Drupal\wahosearch_connector\Service\ContentSelection;
use Drupal\wahosearch_connector\Service\SyncManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Chooses the fields of one content type and how they are named.
 */
final class FieldMappingForm extends FormBase {

  public function __construct(
    private readonly ContentSelection $selection,
    private readonly SyncManager $syncManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wahosearch_connector.content_selection'),
      $container->get('wahosearch_connector.sync_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wahosearch_connector_field_mapping';
  }

  /**
   * Page title callback.
   */
  public function title(string $bundle): TranslatableMarkup {
    return $this->t('Fields of @type', ['@type' => $this->selection->getAllBundles()[$bundle] ?? $bundle]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $bundle = NULL): array {
    if ($bundle === NULL || !isset($this->selection->getAllBundles()[$bundle])) {
      throw new NotFoundHttpException();
    }
    $form_state->set('bundle', $bundle);
    $mapping = $this->selection->getFieldMapping($bundle);

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('The title, address, language and dates are always sent. Choose the other fields to include.') . '</p><p>' . $this->t('Tick "Only without PDF" for a field that holds a copy of the text of the attached file: it is then sent only for items whose PDF is not indexed, such as Word or Excel files, so the same text is not indexed twice.') . '</p>',
    ];
    $form['fields'] = [
      '#type' => 'table',
      '#caption' => $this->t('Available fields'),
      '#header' => [
        $this->t('Field'),
        $this->t('Field type'),
        $this->t('Include'),
        $this->t('Name in search index'),
        $this->t('Used as'),
        $this->t('Only without PDF'),
      ],
      '#empty' => $this->t('This content type has no fields that can be indexed.'),
    ];

    foreach ($this->selection->getCandidateFields($bundle) as $name => $definition) {
      $label = (string) $definition->getLabel();
      $isTaxonomy = $this->selection->isTaxonomyField($definition);
      $roles = $isTaxonomy
        ? ['taxonomy' => $this->t('Taxonomy filter')]
        : [
          'content' => $this->t('Main searchable text'),
          'summary' => $this->t('Summary'),
          'metadata' => $this->t('Metadata'),
        ];

      $form['fields'][$name]['label'] = [
        '#markup' => $this->t('@label <small>(@name)</small>', ['@label' => $label, '@name' => $name]),
      ];
      $form['fields'][$name]['type'] = ['#plain_text' => $definition->getType()];
      $form['fields'][$name]['include'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Include @field', ['@field' => $label]),
        '#title_display' => 'invisible',
        '#default_value' => isset($mapping[$name]),
      ];
      $form['fields'][$name]['target'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name in search index for @field', ['@field' => $label]),
        '#title_display' => 'invisible',
        '#default_value' => $mapping[$name]['target'] ?? ContentSelection::defaultTarget($name),
        '#size' => 30,
        '#maxlength' => 64,
      ];
      $form['fields'][$name]['role'] = [
        '#type' => 'select',
        '#title' => $this->t('Use of @field', ['@field' => $label]),
        '#title_display' => 'invisible',
        '#options' => $roles,
        '#default_value' => isset($mapping[$name]['role'], $roles[$mapping[$name]['role']]) ? $mapping[$name]['role'] : array_key_first($roles),
      ];
      $form['fields'][$name]['pdf_fallback'] = $isTaxonomy ? ['#markup' => ''] : [
        '#type' => 'checkbox',
        '#title' => $this->t('Send @field only when no PDF of the item is indexed', ['@field' => $label]),
        '#title_display' => 'invisible',
        '#default_value' => !empty($mapping[$name]['pdf_fallback']),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save fields'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $seen = [];
    foreach ($form_state->getValue('fields') ?: [] as $name => $row) {
      if (empty($row['include'])) {
        continue;
      }
      $target = trim((string) $row['target']);
      if (!preg_match('/^[a-z][a-z0-9_]*$/', $target)) {
        $form_state->setErrorByName("fields][$name][target", $this->t('Index names can only use lowercase letters, numbers and underscores, and must start with a letter.'));
      }
      elseif (isset($seen[$target])) {
        $form_state->setErrorByName("fields][$name][target", $this->t('The index name %name is used twice.', ['%name' => $target]));
      }
      $seen[$target] = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $bundle = $form_state->get('bundle');
    $mapping = [];
    foreach ($form_state->getValue('fields') ?: [] as $name => $row) {
      if (!empty($row['include'])) {
        $mapping[$name] = ['target' => trim((string) $row['target']), 'role' => (string) $row['role']];
        if (!empty($row['pdf_fallback'])) {
          $mapping[$name]['pdf_fallback'] = TRUE;
        }
      }
    }

    $config = $this->configFactory()->getEditable('wahosearch_connector.settings');
    $bundles = $config->get('bundles') ?: [];
    $bundles[$bundle] = ['fields' => $mapping] + ($bundles[$bundle] ?? ['enabled' => FALSE, 'include_private' => FALSE]);
    $bundles[$bundle]['fields'] = $mapping;
    $config->set('bundles', $bundles)->save();

    $this->messenger()->addStatus($this->t('The field selection was saved.'));
    if ($this->selection->isBundleEnabled($bundle)) {
      $count = $this->syncManager->queueBundle($bundle);
      $this->messenger()->addStatus($this->t('@count items were queued to update the search index.', ['@count' => $count]));
    }
    $form_state->setRedirect('wahosearch_connector.content_types');
  }

}
