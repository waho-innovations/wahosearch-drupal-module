<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\wahosearch_connector\Service\SiteSearch;
use Drupal\wahosearch_connector\Service\WidgetEmbed;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Search page inside the normal Drupal theme, and its suggestions.
 *
 * Results are fetched by this server and rendered with the module's Twig
 * templates, which a theme can override.
 */
final class SearchPageController extends ControllerBase {

  /**
   * Suggestion requests one visitor may make per minute.
   */
  private const SUGGEST_PER_MINUTE = 60;

  /**
   * Seconds the same suggestions are reused.
   */
  private const SUGGEST_LIFETIME = 300;

  public function __construct(
    private readonly WidgetEmbed $embed,
    private readonly SiteSearch $search,
    private readonly KillSwitch $pageCacheKillSwitch,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wahosearch_connector.widget_embed'),
      $container->get('wahosearch_connector.site_search'),
      $container->get('page_cache_kill_switch'),
      $container->get('flood'),
    );
  }

  /**
   * Shows the search page.
   */
  public function page(Request $request): array {
    if (!$this->embed->isSearchPageEnabled()) {
      throw new NotFoundHttpException();
    }
    $input = $request->query->all();
    $raw = $input['q'] ?? '';
    $query = SiteSearch::cleanQuery(is_string($raw) ? $raw : '');
    $page = $input['page'] ?? '1';
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

    $filtersOn = $this->embed->isFiltersEnabled();
    $filters = SiteSearch::cleanFilters($filtersOn ? $input : []);
    $outcome = $this->search->search($query, $page, $filters);
    if ($outcome['hosted_only']) {
      $this->embed->rememberHostedOnly();
      return $this->hostedPage($query);
    }
    $build['#error'] = $outcome['error'];
    $build['#notice'] = $outcome['notice'];
    $build['#page'] = $outcome['page'];
    foreach ($outcome['results'] as $result) {
      // Escaped by SiteSearch::highlight(); only <mark> is added.
      $result['snippet_html'] = Markup::create($result['snippet_html']);
      $build['#results'][] = [
        '#theme' => 'wahosearch_search_result',
        '#result' => $result,
      ];
    }
    if ($filtersOn && $outcome['error'] === NULL) {
      $active = SiteSearch::filterQuery($filters);
      $build['#filters'] = [
        '#theme' => 'wahosearch_search_filters',
        '#action' => Url::fromRoute('wahosearch_connector.search_page')->toString(),
        '#query' => $query,
        '#values' => $filters,
        '#facets' => $this->filterOptions($outcome['facets'] ?? NULL, $filters),
        '#clear_url' => $active ? $this->pageUrl($query, 1, []) : NULL,
      ];
    }
    if ($outcome['page'] > 1) {
      $build['#previous_url'] = $this->pageUrl($query, $outcome['page'] - 1, $filters);
    }
    if ($outcome['has_more']) {
      $build['#next_url'] = $this->pageUrl($query, $outcome['page'] + 1, $filters);
    }
    return $build;
  }

  /**
   * Returns suggestions for the words typed in the search box, as JSON.
   *
   * The request to the platform is signed by this server. Answers are reused
   * for a few minutes for visitors with the same roles.
   */
  public function suggest(Request $request): CacheableJsonResponse {
    $raw = $request->query->all()['q'] ?? '';
    $text = SiteSearch::cleanQuery(is_string($raw) ? $raw : '');
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['url.query_args:q', 'languages:language_content', 'user.roles'])
      ->addCacheTags(['config:wahosearch_connector.settings']);

    if (!$this->embed->isSuggestionsEnabled()) {
      throw new NotFoundHttpException();
    }
    $suggestions = [];
    $limited = FALSE;
    if (mb_strlen($text) >= SiteSearch::SUGGEST_MIN) {
      if ($this->flood->isAllowed('wahosearch_connector.suggest', self::SUGGEST_PER_MINUTE, 60)) {
        $this->flood->register('wahosearch_connector.suggest', 60);
        $suggestions = $this->search->suggest($text);
      }
      else {
        $limited = TRUE;
      }
    }

    $response = new CacheableJsonResponse(['suggestions' => $suggestions]);
    if ($limited) {
      // Not kept: the next visitor gets real suggestions.
      $this->pageCacheKillSwitch->trigger();
      $cacheability->setCacheMaxAge(0);
    }
    else {
      $cacheability->setCacheMaxAge(self::SUGGEST_LIFETIME);
      // The page cache for anonymous visitors keeps the answer until then.
      $response->setExpires(new \DateTimeImmutable('+' . self::SUGGEST_LIFETIME . ' seconds'));
    }
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Builds the choices of the filter bar.
   *
   * The values come from the platform (the site's own content types and
   * topics, with counts). A value the visitor chose stays listed even when
   * the platform did not send it again.
   *
   * @return array
   *   Keys types, content_types and tags, each a list of options with value,
   *   text (with the count when known) and selected.
   */
  private function filterOptions(?array $facets, array $filters): array {
    $option = fn (string $value, string $label, ?int $count, ?string $current): array => [
      'value' => $value,
      'text' => $count !== NULL ? (string) $this->t('@label (@count)', ['@label' => $label, '@count' => $count]) : $label,
      'selected' => $value === $current,
    ];
    $list = function (array $items, ?string $current) use ($option): array {
      $options = [];
      foreach ($items as $item) {
        $options[] = $option($item['value'], $item['label'] ?? $item['value'], $item['count'] ?? NULL, $current);
      }
      if ($current !== NULL && !in_array($current, array_column($items, 'value'), TRUE)) {
        $options[] = $option($current, $current, NULL, $current);
      }
      return $options;
    };
    $types = $facets['types'] ?? [];
    return [
      'types' => [
        $option('page', (string) $this->t('Pages'), $types['page'] ?? NULL, $filters['type']),
        $option('file', (string) $this->t('Files'), $types['file'] ?? NULL, $filters['type']),
      ],
      'content_types' => $list($facets['content_types'] ?? [], $filters['content_type']),
      'tags' => $list($facets['tags'] ?? [], $filters['tag']),
    ];
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
  private function pageUrl(string $query, int $page, array $filters): string {
    $options = ['query' => ['q' => $query] + SiteSearch::filterQuery($filters)];
    if ($page > 1) {
      $options['query']['page'] = $page;
    }
    return Url::fromRoute('wahosearch_connector.search_page', [], $options)->toString();
  }

}
