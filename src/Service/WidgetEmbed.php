<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Builds the search box and the markup that loads the hosted chatbot.
 *
 * Only the site ID and language reach the browser, never the secret.
 */
class WidgetEmbed {

  use StringTranslationTrait;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LanguageManagerInterface $languageManager,
    protected StateInterface $state,
  ) {}

  /**
   * Whether the widgets have what they need to load.
   */
  public function isReady(): bool {
    $config = $this->configFactory->get('wahosearch_connector.settings');
    return $config->get('endpoint') && $config->get('site_id');
  }

  /**
   * Whether the search page is switched on.
   */
  public function isSearchPageEnabled(): bool {
    return (bool) $this->configFactory->get('wahosearch_connector.settings')->get('search_page');
  }

  /**
   * Whether results come from the page hosted by the platform, in a frame.
   */
  public function isHostedMode(): bool {
    return !$this->planAllowsDrupalPage() || $this->configFactory->get('wahosearch_connector.settings')->get('search_mode') === 'hosted';
  }

  /**
   * Whether the plan allows the search page inside Drupal.
   *
   * The Free plan only allows the hosted page. Until the platform has said
   * which plan the site is on, the choice is left to the administrator.
   */
  public function planAllowsDrupalPage(): bool {
    $plan = $this->state->get(SyncManager::STATE_PLAN);
    return !is_array($plan) || ($plan['search_page'] ?? 'drupal') === 'drupal';
  }

  /**
   * Records that the platform only allows the hosted page.
   */
  public function rememberHostedOnly(): void {
    $plan = $this->state->get(SyncManager::STATE_PLAN);
    $this->state->set(SyncManager::STATE_PLAN, ['search_page' => 'hosted'] + (is_array($plan) ? $plan : ['plan' => '', 'time' => 0]));
    Cache::invalidateTags(['config:wahosearch_connector.settings']);
  }

  /**
   * Returns the address of the hosted search page.
   */
  public function hostedPageUrl(array $parameters): string {
    $config = $this->configFactory->get('wahosearch_connector.settings');
    return rtrim((string) $config->get('endpoint'), '/') . '/embed/' . rawurlencode((string) $config->get('site_id')) . '/search?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
  }

  /**
   * Returns the platform's origin, such as https://app.wahosearch.ca.
   */
  public function platformOrigin(): string {
    $parts = parse_url((string) $this->configFactory->get('wahosearch_connector.settings')->get('endpoint'));
    if (!isset($parts['scheme'], $parts['host'])) {
      return '';
    }
    return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
  }

  /**
   * Whether the chatbot should load on public pages.
   */
  public function isChatbotEnabled(): bool {
    return $this->isReady() && $this->configFactory->get('wahosearch_connector.settings')->get('chatbot');
  }

  /**
   * Returns the html_head entry that loads a widget.
   */
  public function scriptTag(string $widget): array {
    $config = $this->configFactory->get('wahosearch_connector.settings');
    return [
      [
        '#type' => 'html_tag',
        '#tag' => 'script',
        '#value' => '',
        '#attributes' => [
          'src' => rtrim((string) $config->get('endpoint'), '/') . '/widget/' . $widget . '.js',
          'defer' => 'defer',
          'data-site-id' => (string) $config->get('site_id'),
          'data-language' => $this->language(),
        ],
      ],
      'wahosearch_connector_' . $widget,
    ];
  }

  /**
   * Builds the search box shown by the block.
   */
  public function searchBox(): array {
    return $this->searchForm('') + [
      '#attached' => ['library' => ['wahosearch_connector/search_page']],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['config:wahosearch_connector.settings'],
      ],
    ];
  }

  /**
   * Builds a plain search form, which also works without JavaScript.
   */
  public function searchForm(string $query): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<form role="search" method="get" action="{{ action }}" class="wahosearch-box" data-wahosearch-widget="box"><label for="{{ id }}">{{ label }}</label> <input type="search" id="{{ id }}" name="q" value="{{ query }}" maxlength="200" autocomplete="off"> <button type="submit">{{ button }}</button></form>',
      '#context' => [
        'action' => Url::fromRoute('wahosearch_connector.search_page')->toString(),
        'id' => Html::getUniqueId('wahosearch-query'),
        'label' => $this->t('Search this site'),
        'query' => $query,
        'button' => $this->t('Search'),
      ],
    ];
  }

  /**
   * Returns the language of the current page.
   */
  protected function language(): string {
    return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
  }

}
