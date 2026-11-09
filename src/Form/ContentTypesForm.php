<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Form;

use Drupal\wahosearch_connector\Service\ContentSelection;
use Drupal\wahosearch_connector\Service\ItemTracker;
use Drupal\wahosearch_connector\Service\SyncManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets administrators choose exactly which content types are indexed.
 */
final class ContentTypesForm extends FormBase {

  public function __construct(
    private readonly ContentSelection $selection,
    private readonly ItemTracker $tracker,
    private readonly SyncManager $syncManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wahosearch_connector.content_selection'),
      $container->get('wahosearch_connector.tracker'),
      $container->get('wahosearch_connector.sync_manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wahosearch_connector_content_types';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = $this->selection->getBundleSettings();

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Only the content types ticked here are sent to the search platform. Everything else stays private to this site.') . '</p>',
    ];
    $form['bundles'] = [
      '#type' => 'table',
      '#caption' => $this->t('Content types on this site'),
      '#header' => [
        $this->t('Content type'),
        $this->t('Index'),
        $this->t('Include restricted content'),
        $this->t('Published items'),
        $this->t('Indexed documents'),
        $this->t('Fields'),
      ],
      '#empty' => $this->t('This site has no content types yet.'),
    ];

    foreach ($this->selection->getAllBundles() as $bundle => $label) {
      $published = (int) $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->condition('status', 1)
        ->count()
        ->execute();

      $form['bundles'][$bundle]['label'] = ['#plain_text' => $label];
      $form['bundles'][$bundle]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Index @type', ['@type' => $label]),
        '#title_display' => 'invisible',
        '#default_value' => !empty($settings[$bundle]['enabled']),
      ];
      $form['bundles'][$bundle]['include_private'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Include restricted @type content', ['@type' => $label]),
        '#title_display' => 'invisible',
        '#default_value' => !empty($settings[$bundle]['include_private']),
      ];
      $form['bundles'][$bundle]['published'] = ['#plain_text' => (string) $published];
      $form['bundles'][$bundle]['indexed'] = ['#plain_text' => (string) $this->tracker->countIndexedForBundle($bundle)];
      $form['bundles'][$bundle]['fields'] = Link::fromTextAndUrl(
        $this->t('Choose fields for @type', ['@type' => $label]),
        Url::fromRoute('wahosearch_connector.field_mapping', ['bundle' => $bundle]),
      )->toRenderable();
    }

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Restricted content is content that anonymous visitors cannot open. When it is included, the roles allowed to view it are sent with it.') . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save content types'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory()->getEditable('wahosearch_connector.settings');
    $before = $this->selection->getBundleSettings();
    $after = $before;
    $changed = [];
    $removed = [];

    foreach ($form_state->getValue('bundles') ?: [] as $bundle => $row) {
      $enabled = (bool) $row['enabled'];
      $private = (bool) $row['include_private'];
      $wasEnabled = !empty($before[$bundle]['enabled']);
      $hadPrivate = !empty($before[$bundle]['include_private']);

      $after[$bundle] = ['enabled' => $enabled, 'include_private' => $private] + ($before[$bundle] ?? []);
      if ($enabled && (!$wasEnabled || $hadPrivate !== $private)) {
        $changed[] = $bundle;
      }
      elseif (!$enabled && $wasEnabled) {
        $removed[] = $bundle;
      }
    }

    $config->set('bundles', $after)->save();

    $queued = 0;
    foreach ($changed as $bundle) {
      $queued += $this->syncManager->queueBundle($bundle);
    }
    foreach ($removed as $bundle) {
      $queued += $this->syncManager->queueBundleRemoval($bundle);
    }

    $this->messenger()->addStatus($this->t('The content types were saved.'));
    if ($queued) {
      $this->messenger()->addStatus($this->t('@count items were queued to update the search index.', ['@count' => $queued]));
    }
  }

}
