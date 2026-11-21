<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Service;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\wahosearch_connector\Exception\ApiException;

/**
 * Runs visitor searches through the platform.
 *
 * The search is sent by this server, signed with the site's secret, so the
 * secret never reaches the browser. The platform gets the language of the page
 * and the visitor's roles, and only returns results in that language that the
 * visitor may see.
 */
class SiteSearch {

  use StringTranslationTrait;

  /**
   * Languages the platform searches.
   */
  public const LANGUAGES = ['en', 'fr'];

  /**
   * Longest question sent, in characters.
   */
  public const MAX_QUERY = 200;

  /**
   * Highest page number offered.
   */
  public const MAX_PAGE = 20;

  /**
   * Orders the visitor can choose. The first is the default.
   */
  public const SORTS = ['relevance', 'newest', 'title'];

  /**
   * Kinds of results the visitor can keep.
   */
  public const TYPES = ['page', 'file'];

  /**
   * Smart search (AI) or the exact words (keyword search), default first.
   */
  public const MODES = ['ai', 'basic'];

  /**
   * Shortest text that gets suggestions, in characters.
   */
  public const SUGGEST_MIN = 2;

  /**
   * Longest text that gets suggestions, in characters.
   */
  public const SUGGEST_MAX = 100;

  /**
   * Seconds the values to filter by are kept. The platform keeps them as long.
   */
  protected const FACETS_LIFETIME = 600;

  public function __construct(
    protected ApiClient $apiClient,
    protected LanguageManagerInterface $languageManager,
    protected AccountInterface $currentUser,
    protected LoggerChannelInterface $logger,
    protected CacheBackendInterface $cache,
    protected TransliterationInterface $transliteration,
  ) {}

  /**
   * Keeps the filter values the platform accepts, from the page address.
   *
   * @return array
   *   Keys: type, content_type and tag (string or NULL), sort and mode.
   */
  public static function cleanFilters(array $input): array {
    $text = static function (mixed $value, int $max): ?string {
      if (!is_string($value)) {
        return NULL;
      }
      $value = trim($value);
      return $value !== '' && mb_strlen($value) <= $max ? $value : NULL;
    };
    $type = $input['type'] ?? NULL;
    $sort = $input['sort'] ?? NULL;
    $mode = $input['mode'] ?? NULL;
    return [
      'type' => in_array($type, self::TYPES, TRUE) ? $type : NULL,
      'content_type' => $text($input['content_type'] ?? NULL, 128),
      'tag' => $text($input['tag'] ?? NULL, 255),
      'sort' => in_array($sort, self::SORTS, TRUE) ? $sort : self::SORTS[0],
      'mode' => in_array($mode, self::MODES, TRUE) ? $mode : self::MODES[0],
    ];
  }

  /**
   * Returns the filters that differ from the defaults, for page addresses.
   */
  public static function filterQuery(array $filters): array {
    $defaults = ['sort' => self::SORTS[0], 'mode' => self::MODES[0]];
    return array_filter($filters, static fn ($value, $key) => $value !== NULL && $value !== ($defaults[$key] ?? NULL), ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Searches in the language of the current page.
   *
   * @param string $query
   *   The visitor's question.
   * @param int $page
   *   The page of results, starting at 1.
   * @param array $filters
   *   The visitor's choices: type, content_type, tag, sort and mode.
   *
   * @return array
   *   Keys: results (list of results, see ::result()), has_more (bool),
   *   page (int), langcode (string), error (string or NULL, ready to show),
   *   hosted_only (TRUE when the plan only allows the hosted page), mode
   *   ("ai" or "basic": how the results were found), notice (string or NULL,
   *   ready to show: why the exact words were searched instead of smart search)
   *   and facets (the values to filter by, see ::facets(), or NULL).
   */
  public function search(string $query, int $page = 1, array $filters = []): array {
    $langcode = $this->language();
    $page = max(1, min(self::MAX_PAGE, $page));
    $filters = self::cleanFilters($filters);
    $outcome = [
      'results' => [],
      'has_more' => FALSE,
      'page' => $page,
      'langcode' => $langcode,
      'error' => NULL,
      'hosted_only' => FALSE,
      'mode' => $filters['mode'],
      'notice' => NULL,
      'facets' => NULL,
    ];
    $query = self::cleanQuery($query);
    if ($query === '') {
      return $outcome;
    }
    if (!in_array($langcode, self::LANGUAGES, TRUE)) {
      $outcome['error'] = (string) $this->t('Search is available in English and French only.');
      return $outcome;
    }
    if (!$this->apiClient->isConfigured()) {
      $outcome['error'] = (string) $this->t('Search is not available right now. Please try again later.');
      return $outcome;
    }

    $roles = $this->roles();
    $cachedFacets = $this->cache->get($this->facetsCacheId($langcode, $roles));
    $request = [
      'query' => $query,
      'langcode' => $langcode,
      'roles' => $roles,
      'page' => $page,
    ] + self::filterQuery($filters);
    // The platform sends the values to filter by with the first page only.
    if ($page === 1 && !$cachedFacets) {
      $request['facets'] = TRUE;
    }

    try {
      $response = $this->apiClient->search($request);
    }
    catch (ApiException $e) {
      if ($e->getErrorCode() === 'hosted_page_only') {
        // The plan only allows the hosted page: the caller shows it instead.
        $outcome['hosted_only'] = TRUE;
        return $outcome;
      }
      $outcome['error'] = $this->errorMessage($e);
      return $outcome;
    }

    foreach ($response['results'] ?? [] as $item) {
      if (is_array($item) && ($result = $this->result($item, $query))) {
        $outcome['results'][] = $result;
      }
    }
    $outcome['has_more'] = !empty($response['has_more']) && $page < self::MAX_PAGE;
    $outcome['mode'] = ($response['mode'] ?? NULL) === 'basic' ? 'basic' : 'ai';
    $outcome['notice'] = $this->notice($response['notice'] ?? NULL);

    if (is_array($response['facets'] ?? NULL)) {
      $outcome['facets'] = $this->facets($response['facets']);
      $this->cache->set($this->facetsCacheId($langcode, $roles), $outcome['facets'], time() + self::FACETS_LIFETIME);
    }
    elseif ($cachedFacets) {
      $outcome['facets'] = $cachedFacets->data;
    }
    return $outcome;
  }

  /**
   * Returns suggestions for the words a visitor has typed so far.
   *
   * Failures return no suggestions: the visitor can always search.
   *
   * @return array
   *   A list of suggestions, each with text and url (a page address, or NULL
   *   when the suggestion is a search).
   */
  public function suggest(string $text): array {
    $text = mb_substr(self::cleanQuery($text), 0, self::SUGGEST_MAX);
    $langcode = $this->language();
    if (mb_strlen($text) < self::SUGGEST_MIN || !in_array($langcode, self::LANGUAGES, TRUE) || !$this->apiClient->isConfigured()) {
      return [];
    }
    try {
      $response = $this->apiClient->suggest(['query' => $text, 'langcode' => $langcode, 'roles' => $this->roles()]);
    }
    catch (ApiException $e) {
      $this->logger->notice('Suggestions failed: @message', ['@message' => $e->getMessage()]);
      return [];
    }
    $suggestions = [];
    foreach ($response['suggestions'] ?? [] as $item) {
      $value = is_array($item) && is_string($item['text'] ?? NULL) ? trim($item['text']) : '';
      if ($value === '') {
        continue;
      }
      $suggestions[] = [
        'text' => mb_substr($value, 0, 200),
        'url' => isset($item['url']) ? $this->safeUrl($item['url']) : NULL,
      ];
    }
    return array_slice($suggestions, 0, 10);
  }

  /**
   * Trims a question to what the platform accepts.
   */
  public static function cleanQuery(string $query): string {
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    return mb_substr($query, 0, self::MAX_QUERY);
  }

  /**
   * Returns the language of the current page.
   */
  public function language(): string {
    return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
  }

  /**
   * Returns the snippet as HTML: escaped, with the matching words in <mark>.
   *
   * Words match when they start with a word of the question, ignoring case
   * and accents, as the platform's keyword search does: "etude" marks
   * "Études".
   */
  public function highlight(string $snippet, string $query): string {
    $terms = array_filter(explode(' ', $this->fold($query)), static fn (string $term) => mb_strlen($term) >= 2);
    $terms = array_slice(array_values(array_unique($terms)), 0, 10);
    $html = '';
    foreach (preg_split('/(\s+)/u', $snippet, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $word) {
      $safe = Html::escape($word);
      $folded = $this->fold($word);
      $match = FALSE;
      foreach ($terms as $term) {
        if ($folded !== '' && str_starts_with($folded, $term)) {
          $match = TRUE;
          break;
        }
      }
      $html .= $match ? '<mark>' . $safe . '</mark>' : $safe;
    }
    return $html;
  }

  /**
   * Returns text in lower case, without accents, words only.
   */
  protected function fold(string $text): string {
    $text = mb_strtolower($this->transliteration->transliterate($text, 'en'), 'UTF-8');
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '');
  }

  /**
   * Returns the visitor's roles as the platform knows them.
   *
   * Anonymous visitors send none and only see public content.
   */
  protected function roles(): array {
    if ($this->currentUser->isAnonymous()) {
      return [];
    }
    return array_values(array_diff($this->currentUser->getRoles(), [AccountInterface::ANONYMOUS_ROLE]));
  }

  /**
   * Keeps the fields the templates use, and only safe links.
   */
  protected function result(array $item, string $query): ?array {
    $url = $this->safeUrl($item['url'] ?? NULL);
    $title = is_string($item['title'] ?? NULL) ? trim($item['title']) : '';
    if ($url === NULL) {
      return NULL;
    }
    $parent = NULL;
    if (is_array($item['parent'] ?? NULL) && ($parentUrl = $this->safeUrl($item['parent']['url'] ?? NULL))) {
      $parent = [
        'title' => is_string($item['parent']['title'] ?? NULL) ? $item['parent']['title'] : $parentUrl,
        'url' => $parentUrl,
      ];
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    $snippet = is_string($item['snippet'] ?? NULL) ? $item['snippet'] : '';
    return [
      'id' => is_string($item['id'] ?? NULL) ? $item['id'] : '',
      'type' => ($item['type'] ?? '') === 'file' ? 'file' : 'page',
      'title' => $title !== '' ? $title : basename($path),
      'url' => $url,
      'snippet' => $snippet,
      'snippet_html' => $this->highlight($snippet, $query),
      'heading' => array_values(array_filter((array) ($item['heading'] ?? []), 'is_string')),
      'content_type' => is_string($item['content_type'] ?? NULL) ? $item['content_type'] : '',
      'tags' => array_values(array_filter((array) ($item['tags'] ?? []), 'is_string')),
      'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
      'parent' => $parent,
    ];
  }

  /**
   * Keeps the values to filter by that the page can show.
   *
   * @return array
   *   Keys: types (counts by "page" and "file"), content_types (list of value,
   *   label and count) and tags (list of value and count).
   */
  protected function facets(array $facets): array {
    $count = static fn (mixed $value): int => is_int($value) && $value > 0 ? $value : 0;
    $types = [];
    foreach (self::TYPES as $type) {
      $types[$type] = $count($facets['types'][$type] ?? 0);
    }
    $contentTypes = [];
    foreach ((array) ($facets['content_types'] ?? []) as $item) {
      if (is_array($item) && is_string($item['value'] ?? NULL) && $item['value'] !== '' && mb_strlen($item['value']) <= 128) {
        $contentTypes[] = [
          'value' => $item['value'],
          'label' => is_string($item['label'] ?? NULL) && $item['label'] !== '' ? $item['label'] : $item['value'],
          'count' => $count($item['count'] ?? 0),
        ];
      }
    }
    $tags = [];
    foreach ((array) ($facets['tags'] ?? []) as $item) {
      if (is_array($item) && is_string($item['value'] ?? NULL) && $item['value'] !== '' && mb_strlen($item['value']) <= 255) {
        $tags[] = ['value' => $item['value'], 'count' => $count($item['count'] ?? 0)];
      }
    }
    return ['types' => $types, 'content_types' => $contentTypes, 'tags' => $tags];
  }

  /**
   * Returns the cache ID of the values to filter by for these visitors.
   */
  protected function facetsCacheId(string $langcode, array $roles): string {
    sort($roles);
    return 'wahosearch_connector:facets:' . $langcode . ':' . hash('sha256', implode(',', $roles));
  }

  /**
   * Returns the message shown when the exact words were searched instead.
   */
  protected function notice(mixed $notice): ?string {
    if (!is_array($notice) || !is_string($notice['code'] ?? NULL)) {
      return NULL;
    }
    // The platform's message is written for visitors, in the page language.
    if (is_string($notice['message'] ?? NULL) && trim($notice['message']) !== '') {
      return trim($notice['message']);
    }
    return $notice['code'] === 'ai_limit_reached'
      ? (string) $this->t('Smart search is used up for this month. These results come from a simple word search.')
      : (string) $this->t('Smart search is not available right now. These results come from a simple word search.');
  }

  /**
   * Returns the link if it is a web address, NULL otherwise.
   */
  protected function safeUrl(mixed $url): ?string {
    if (!is_string($url) || $url === '') {
      return NULL;
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
      return $url;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], TRUE) && UrlHelper::isValid($url, TRUE) ? $url : NULL;
  }

  /**
   * Turns a failed search into a message for the visitor.
   */
  protected function errorMessage(ApiException $e): string {
    $code = $e->getErrorCode();
    // The platform's own messages are written for visitors and already in the
    // language of the page.
    if (in_array($code, ['rate_limited', 'search_limit_reached', 'search_busy', 'empty_query'], TRUE) && $e->getPlatformMessage()) {
      return $e->getPlatformMessage();
    }
    $this->logger->warning('Search failed: @message', ['@message' => $e->getMessage()]);
    if ($e->getStatusCode() === 429 || $code === 'search_busy') {
      return (string) $this->t('Search is busy right now. Please try again in a moment.');
    }
    return (string) $this->t('Search is not available right now. Please try again later.');
  }

}
