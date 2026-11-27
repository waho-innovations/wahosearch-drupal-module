<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Form;

use Drupal\wahosearch_connector\Service\ContentSelection;
use Drupal\wahosearch_connector\Service\SyncManager;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists individually excluded content and lets it be included again.
 */
final class ExcludedItemsForm extends FormBase {

  public function __construct(
    private readonly ContentSelection $selection,
    private readonly SyncManager $syncManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wahosearch_connector.content_selection'),
      $container->get('wahosearch_connector.sync_manager'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wahosearch_connector_excluded';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $rows = $this->selection->getExcluded();
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple(array_column($rows, 'entity_id'));

    $options = [];
    foreach ($rows as $row) {
      $node = $nodes[$row['entity_id']] ?? NULL;
      $options[$row['entity_id']] = [
        'title' => $node ? ['data' => $node->toLink()->toRenderable()] : (string) $row['entity_id'],
        'type' => $node ? $node->type->entity->label() : '',
        'date' => $this->dateFormatter->format((int) $row['created'], 'short'),
      ];
    }

    $form['items'] = [
      '#type' => 'tableselect',
      '#header' => [
        'title' => $this->t('Title'),
        'type' => $this->t('Content type'),
        'date' => $this->t('Excluded on'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No individual content is excluded. To exclude an item, use the WahoSearch option on its edit form.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Include selected items again'),
      '#access' => (bool) $options,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!array_filter($form_state->getValue('items') ?: [])) {
      $form_state->setErrorByName('items', $this->t('Please select at least one item.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ids = array_filter($form_state->getValue('items'));
    foreach ($ids as $id) {
      $this->selection->setExcluded('node', (int) $id, FALSE);
      $this->syncManager->queueNode((int) $id);
    }
    $this->messenger()->addStatus($this->t('@count items were queued to update the search index.', ['@count' => count($ids)]));
  }

}
