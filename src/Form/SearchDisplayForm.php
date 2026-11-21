<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Form;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\path_alias\PathAliasInterface;
use Drupal\wahosearch_connector\Service\WidgetEmbed;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Turns the search page and the chatbot on or off.
 */
final class SearchDisplayForm extends ConfigFormBase {

  /**
   * Internal path of the search page.
   */
  public const PAGE_PATH = '/site-search';

  /**
   * Storage of URL aliases.
   */
  private EntityStorageInterface $aliasStorage;

  /**
   * The language manager.
   */
  private LanguageManagerInterface $languages;

  /**
   * The widget markup builder, which knows the plan.
   */
  private WidgetEmbed $embed;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->aliasStorage = $container->get('entity_type.manager')->getStorage('path_alias');
    $form->languages = $container->get('language_manager');
    $form->embed = $container->get('wahosearch_connector.widget_embed');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wahosearch_connector_search_display';
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
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Search runs inside your Drupal pages, so your theme, menus and language switcher stay in charge. Place the WahoSearch box block wherever visitors should see it.') . '</p>',
    ];
    $form['search_page'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Provide a search page'),
      '#description' => $this->t('Results are shown in the language of the page: the English page shows English content only, the French page French content only.'),
      '#default_value' => $config->get('search_page'),
    ];
    $form['search_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('How results are shown'),
      '#options' => [
        'drupal' => $this->t('Inside your Drupal pages (Starter, Pro and Enterprise plans)'),
        'hosted' => $this->t('Page hosted by WahoSearch, shown in a frame (all plans, including Free)'),
      ],
      'drupal' => [
        '#description' => $this->t('Results use your theme and the module templates, and logged-in visitors also find restricted content they may view.'),
      ],
      'hosted' => [
        '#description' => $this->t('Public content only. The platform address must be reachable by visitors, and the website address on the platform must match this site.'),
      ],
      '#default_value' => $this->embed->isHostedMode() ? 'hosted' : 'drupal',
      '#states' => ['visible' => [':input[name="search_page"]' => ['checked' => TRUE]]],
    ];
    $paid = $this->embed->planAllowsDrupalPage();
    if (!$paid) {
      $form['search_mode']['drupal']['#disabled'] = TRUE;
      $form['search_mode']['#description'] = $this->t('Your plan uses the page hosted by WahoSearch. Search inside your Drupal pages and custom page addresses come with the Starter, Pro and Enterprise plans.');
    }
    $form['search_filters'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Let visitors filter and sort the results'),
      '#description' => $this->t('By type (pages or files), content type and topic, with the values found in your own content, and by best match, newest or title. Visitors can also choose to search the exact words.'),
      '#default_value' => $config->get('search_filters') ?? TRUE,
      '#states' => [
        'visible' => [
          ':input[name="search_page"]' => ['checked' => TRUE],
          ':input[name="search_mode"]' => ['value' => 'drupal'],
        ],
      ],
    ];
    $form['search_suggestions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Suggest searches while visitors type'),
      '#description' => $this->t('Earlier searches on this site that found something, and titles of pages the visitor may see.'),
      '#default_value' => $config->get('search_suggestions') ?? TRUE,
      '#states' => ['visible' => [':input[name="search_page"]' => ['checked' => TRUE]]],
    ];
    $form['addresses'] = [
      '#type' => 'details',
      '#title' => $this->t('Page address'),
      '#open' => TRUE,
      '#description' => $this->t('Leave empty to use @path. The language prefix, such as /fr, is added by Drupal.', ['@path' => self::PAGE_PATH]),
      '#states' => ['visible' => [':input[name="search_page"]' => ['checked' => TRUE]]],
    ];
    foreach ($this->languages->getLanguages() as $langcode => $language) {
      $form['addresses']['address_' . $langcode] = [
        '#type' => 'textfield',
        '#title' => $this->t('Address in @language', ['@language' => $language->getName()]),
        '#default_value' => $this->alias($langcode)?->getAlias() ?? '',
        '#placeholder' => self::PAGE_PATH,
        '#maxlength' => 128,
        '#size' => 30,
        '#disabled' => !$paid,
      ];
    }
    $form['chatbot'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the chatbot on public pages'),
      '#default_value' => $config->get('chatbot'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (array_keys($this->languages->getLanguages()) as $langcode) {
      $key = 'address_' . $langcode;
      $address = trim((string) $form_state->getValue($key));
      if ($address === '' || $address === self::PAGE_PATH) {
        continue;
      }
      if (!preg_match('@^/[\pL\pN_~./-]+$@u', $address) || str_contains($address, '//') || str_ends_with($address, '/')) {
        $form_state->setErrorByName($key, $this->t('The address must start with / and contain only letters, numbers, - and /, for example /search.'));
        continue;
      }
      foreach ($this->aliasStorage->loadByProperties(['alias' => $address, 'langcode' => $langcode]) as $existing) {
        if ($existing->getPath() !== self::PAGE_PATH) {
          $form_state->setErrorByName($key, $this->t('%address is already used by another page.', ['%address' => $address]));
        }
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('wahosearch_connector.settings')
      ->set('search_page', (bool) $form_state->getValue('search_page'))
      ->set('search_mode', $form_state->getValue('search_mode') === 'hosted' || !$this->embed->planAllowsDrupalPage() ? 'hosted' : 'drupal')
      ->set('search_filters', (bool) $form_state->getValue('search_filters'))
      ->set('search_suggestions', (bool) $form_state->getValue('search_suggestions'))
      ->set('chatbot', (bool) $form_state->getValue('chatbot'))
      ->save();
    // Custom page addresses come with the paid plans.
    $paid = $this->embed->planAllowsDrupalPage();
    foreach (array_keys($this->languages->getLanguages()) as $langcode) {
      $this->saveAlias($langcode, $paid ? trim((string) $form_state->getValue('address_' . $langcode)) : '');
    }
    parent::submitForm($form, $form_state);
  }

  /**
   * Returns the alias of the search page in a language, if any.
   */
  private function alias(string $langcode): ?PathAliasInterface {
    $aliases = $this->aliasStorage->loadByProperties(['path' => self::PAGE_PATH, 'langcode' => $langcode]);
    return $aliases ? reset($aliases) : NULL;
  }

  /**
   * Creates, changes or removes the alias of the search page in a language.
   */
  private function saveAlias(string $langcode, string $address): void {
    $alias = $this->alias($langcode);
    if ($address === '' || $address === self::PAGE_PATH) {
      $alias?->delete();
      return;
    }
    if ($alias === NULL) {
      $alias = $this->aliasStorage->create(['path' => self::PAGE_PATH, 'langcode' => $langcode]);
    }
    if ($alias->getAlias() !== $address) {
      $alias->setAlias($address);
      $alias->save();
    }
  }

}
