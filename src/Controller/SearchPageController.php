<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Url;
use Drupal\wahosearch_connector\Service\SiteSearch;
use Drupal\wahosearch_connector\Service\WidgetEmbed;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Search page inside the normal Drupal theme.
 *
 * Results are fetched by this server and rendered with the module's Twig
 * templates, which a theme can override.
 */
final class SearchPageController extends ControllerBase {

  public function __construct(
    private readonly WidgetEmbed $embed,
    private readonly SiteSearch $search,
    private readonly KillSwitch $pageCacheKillSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wahosearch_connector.widget_embed'),
      $container->get('wahosearch_connector.site_search'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * Shows the search page.
   */
  public function page(Request $request): array {
    if (!$this->embed->isSearchPageEnabled()) {
      throw new NotFoundHttpException();
    }
    $raw = $request->query->all()['q'] ?? '';
    $query = SiteSearch::cleanQuery(is_string($raw) ? $raw : '');
    $page = $request->query->all()['page'] ?? '1';
    $page = is_string($page) && ctype_digit($page) ? (int) $page : 1;

    if ($this->embed->isHostedMode()) {
      return $this->hostedPage($query);
    }

    // Every search is counted and filtered for the visitor, so the page is
    // never served from a cache.
    $this->pageCacheKillSwitch->trigger();
    $build = [
      '#theme' => 'wahosearch_search_page',
      '#form' => $this->embed->searchForm($query),
      '#query' => $query,
      '#langcode' => $this->search->language(),
      '#attached' => ['library' => ['wahosearch_connector/search_page']],
      '#cache' => ['max-age' => 0],
    ];
    if ($query === '') {
      return $build;
    }

    $outcome = $this->search->search($query, $page);
    if ($outcome['hosted_only']) {
      $this->embed->rememberHostedOnly();
      return $this->hostedPage($query);
    }
    $build['#error'] = $outcome['error'];
    $build['#page'] = $outcome['page'];
    foreach ($outcome['results'] as $result) {
      $build['#results'][] = [
        '#theme' => 'wahosearch_search_result',
        '#result' => $result,
      ];
    }
    if ($outcome['page'] > 1) {
      $build['#previous_url'] = $this->pageUrl($query, $outcome['page'] - 1);
    }
    if ($outcome['has_more']) {
      $build['#next_url'] = $this->pageUrl($query, $outcome['page'] + 1);
    }
    return $build;
  }

  /**
   * Shows the page hosted by the platform in a frame (Free plan).
   *
   * The platform only shows public content there, since it cannot know who
   * the visitor is.
   */
  private function hostedPage(string $query): array {
    $langcode = $this->search->language();
    $parameters = ['lang' => in_array($langcode, SiteSearch::LANGUAGES, TRUE) ? $langcode : 'en'];
    if ($query !== '') {
      $parameters['q'] = $query;
    }
    return [
      '#theme' => 'wahosearch_search_frame',
      '#src' => $this->embed->hostedPageUrl($parameters),
      '#origin' => $this->embed->platformOrigin(),
      '#title' => $this->t('Search results'),
      '#attached' => ['library' => ['wahosearch_connector/search_frame']],
      '#cache' => [
        'contexts' => ['url.query_args:q', 'languages:language_content'],
        'tags' => ['config:wahosearch_connector.settings'],
      ],
    ];
  }

  /**
   * Returns the address of another page of the same search.
   */
  private function pageUrl(string $query, int $page): string {
    $options = ['query' => ['q' => $query]];
    if ($page > 1) {
      $options['query']['page'] = $page;
    }
    return Url::fromRoute('wahosearch_connector.search_page', [], $options)->toString();
  }

}
