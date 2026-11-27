<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Plugin\Block;

use Drupal\wahosearch_connector\Service\WidgetEmbed;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search box that sends visitors to the search page.
 */
#[Block(
  id: 'wahosearch_connector_search_box',
  admin_label: new TranslatableMarkup('WahoSearch box'),
  category: new TranslatableMarkup('Search'),
)]
final class SearchBoxBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The widget markup builder.
   */
  private WidgetEmbed $embed;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->embed = $container->get('wahosearch_connector.widget_embed');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return $this->embed->searchBox();
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($this->embed->isSearchPageEnabled())
      ->addCacheTags(['config:wahosearch_connector.settings']);
  }

}
