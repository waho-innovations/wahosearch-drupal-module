<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;
use Drupal\wahosearch_connector\Service\ApiClient;
use Drupal\wahosearch_connector\Service\SyncManager;

/**
 * Tests the search page rendered inside Drupal.
 *
 * @group wahosearch_connector
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class SearchPageTest extends BrowserTestBase {

  private const SECRET = 'very-secret-value';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'taxonomy', 'block', 'language', 'path_alias', 'wahosearch_connector', 'wahosearch_connector_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->drupalCreateRole(['access content'], 'member');
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $this->config('wahosearch_connector.settings')
      ->set('endpoint', 'https://platform.example')
      ->set('site_id', 'site-1')
      ->set('immediate', FALSE)
      ->save();
    \Drupal::state()->set(ApiClient::SECRET_STATE_KEY, self::SECRET);
    $this->rebuildContainer();

    $this->drupalLogin($this->drupalCreateUser(['administer wahosearch connector', 'access content']));
    $this->drupalGet('/admin/config/search/wahosearch-connector/search-display');
    $this->submitForm(['search_page' => TRUE, 'address_en' => '/search', 'address_fr' => '/recherche'], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->drupalLogout();
  }

  /**
   * Returns the requests the fake platform received.
   */
  private function requests(): array {
    \Drupal::state()->resetCache();
    return \Drupal::state()->get('wahosearch_connector_test.requests', []);
  }

  /**
   * Sets what the fake platform answers.
   */
  private function answer(array $answer): void {
    \Drupal::state()->set('wahosearch_connector_test.answer', $answer);
  }

  /**
   * Results are rendered with the module templates, safely.
   */
  public function testResults(): void {
    $this->answer([
      'has_more' => TRUE,
      'results' => [
        [
          'id' => 'node:4:en:file:9',
          'type' => 'file',
          'title' => 'Mice <b>guidelines</b>',
          'url' => 'https://www.example.ca/files/mice.pdf',
          'snippet' => 'Mice should be housed <script>alert(1)</script> in groups.',
          'heading' => ['Housing', 'Cages'],
          'content_type' => 'Document',
          'parent' => ['title' => 'Guidelines', 'url' => 'https://www.example.ca/guidelines'],
        ],
        ['id' => 'node:5:en', 'type' => 'page', 'title' => 'Housing news', 'url' => 'https://www.example.ca/news/housing', 'snippet' => 'New housing rules.'],
        ['id' => 'bad', 'type' => 'page', 'title' => 'Bad link', 'url' => 'javascript:alert(1)'],
      ],
    ]);

    $this->drupalGet('/search', ['query' => ['q' => 'mice housing']]);

    $session = $this->assertSession();
    $session->statusCodeEquals(200);
    $session->pageTextContains('Results for “mice housing”');
    $session->linkByHrefExists('https://www.example.ca/files/mice.pdf');
    $session->pageTextContains('Mice <b>guidelines</b>');
    $session->responseNotContains('<script>alert(1)</script>');
    $session->elementTextEquals('css', '.wahosearch-result__badge', 'PDF');
    $session->pageTextContains('Housing › Cages');
    $session->linkExists('Guidelines');
    $session->linkExists('Housing news');
    $session->linkNotExists('Bad link');
    $session->responseNotContains('javascript:alert');
    $session->responseNotContains(self::SECRET);
    $session->fieldValueEquals('q', 'mice housing');
    $session->linkExists('More results');
    $session->linkNotExists('Previous');
    $this->assertSame([['query' => 'mice housing', 'langcode' => 'en', 'roles' => [], 'page' => 1, 'facets' => TRUE]], $this->requests());
    $session->responseContains('<mark>Mice</mark> should be housed &lt;script&gt;');
    $session->responseContains('New <mark>housing</mark> rules.');

    $this->clickLink('More results');
    $session->linkExists('Previous');
    $this->assertSame(2, $this->requests()[1]['page']);
  }

  /**
   * Clicks on results reach the platform, also from later pages.
   */
  public function testClicks(): void {
    $this->answer([
      'has_more' => TRUE,
      'per_page' => 10,
      'click_ref' => 's42.0123456789abcdef01234567',
      'results' => [['id' => 'node:5:en', 'type' => 'page', 'title' => 'Housing news', 'url' => 'https://www.example.ca/news/housing']],
    ]);
    $this->drupalGet('/search', ['query' => ['q' => 'housing']]);
    $session = $this->assertSession();
    $session->elementAttributeContains('css', 'ol[data-wahosearch-click]', 'data-wahosearch-click', 's42.0123456789abcdef01234567');
    $session->elementAttributeContains('css', 'ol[data-wahosearch-click]', 'data-wahosearch-click-url', '/wahosearch/click');
    $session->elementAttributeContains('css', '.wahosearch-result__title a', 'data-wahosearch-position', '1');
    $session->responseContains('search-click.js');

    // The next page has no reference of its own and keeps the first one.
    $this->answer(['has_more' => FALSE, 'per_page' => 10, 'results' => [['id' => 'node:6:en', 'type' => 'page', 'title' => 'Older news', 'url' => 'https://www.example.ca/news/older']]]);
    $this->clickLink('More results');
    $session->elementAttributeContains('css', 'ol[data-wahosearch-click]', 'data-wahosearch-click', 's42.0123456789abcdef01234567');
    $session->elementAttributeContains('css', '.wahosearch-result__title a', 'data-wahosearch-position', '11');

    $post = function (string $body): int {
      $client = $this->getHttpClient();
      return $client->request('POST', $this->buildUrl('/wahosearch/click'), ['body' => $body, 'http_errors' => FALSE])->getStatusCode();
    };
    $this->assertSame(204, $post(json_encode(['ref' => 's42.0123456789abcdef01234567', 'url' => 'https://www.example.ca/news/older', 'position' => 11])));
    $this->assertSame(204, $post(json_encode(['ref' => 'forged', 'url' => 'https://www.example.ca/news/older'])));
    $this->assertSame(204, $post(json_encode(['ref' => 's42.0123456789abcdef01234567', 'url' => 'javascript:alert(1)'])));
    \Drupal::state()->resetCache();
    $this->assertSame([['click_ref' => 's42.0123456789abcdef01234567', 'url' => 'https://www.example.ca/news/older', 'position' => 11]], \Drupal::state()->get('wahosearch_connector_test.clicks', []));
  }

  /**
   * Visitors filter and sort with the values of the site's own content.
   */
  public function testFiltersAndSort(): void {
    $this->answer([
      'has_more' => TRUE,
      'mode' => 'ai',
      'results' => [['id' => 'node:5:en', 'type' => 'page', 'title' => 'Housing news', 'url' => 'https://www.example.ca/news/housing']],
      'facets' => [
        'types' => ['page' => 190, 'file' => 137],
        'content_types' => [['value' => 'guideline', 'label' => 'Guideline', 'count' => 42]],
        'tags' => [['value' => 'Mice', 'count' => 12], ['value' => '2024', 'count' => 3]],
      ],
    ]);

    $this->drupalGet('/search', ['query' => ['q' => 'mice']]);
    $session = $this->assertSession();
    $session->optionExists('type', 'Files (137)');
    $session->optionExists('content_type', 'Guideline (42)');
    $session->optionExists('tag', 'Mice (12)');
    $session->optionExists('tag', '2024 (3)');
    $session->linkNotExists('Clear filters');

    $this->submitForm(['type' => 'file', 'content_type' => 'guideline', 'tag' => '2024', 'sort' => 'newest', 'mode' => 'basic'], 'Apply');
    $this->assertSame(
      ['query' => 'mice', 'langcode' => 'en', 'roles' => [], 'page' => 1, 'type' => 'file', 'content_type' => 'guideline', 'tag' => '2024', 'sort' => 'newest', 'mode' => 'basic'],
      $this->requests()[1],
      'The values to filter by are not asked for again.',
    );
    $session->fieldValueEquals('type', 'file');
    $session->fieldValueEquals('tag', '2024');
    $session->fieldValueEquals('sort', 'newest');
    $session->fieldValueEquals('mode', 'basic');

    // The next page keeps the choices.
    $this->clickLink('More results');
    $request = $this->requests()[2];
    $this->assertSame([2, 'file', '2024', 'newest', 'basic'], [$request['page'], $request['type'], $request['tag'], $request['sort'], $request['mode']]);
    $session->optionExists('content_type', 'Guideline (42)');

    $this->clickLink('Clear filters');
    $this->assertSame(['query' => 'mice', 'langcode' => 'en', 'roles' => [], 'page' => 1], $this->requests()[3]);

    // Values that do not exist are ignored.
    $this->drupalGet('/search', ['query' => ['q' => 'mice', 'type' => 'video', 'sort' => 'random', 'mode' => 'x']]);
    $this->assertSame(['query' => 'mice', 'langcode' => 'en', 'roles' => [], 'page' => 1], $this->requests()[4]);

    // Turned off: no bar, and the address cannot filter.
    $this->config('wahosearch_connector.settings')->set('search_filters', FALSE)->save();
    $this->drupalGet('/search', ['query' => ['q' => 'mice', 'type' => 'file']]);
    $session->fieldNotExists('sort');
    $this->assertArrayNotHasKey('type', $this->requests()[5]);
  }

  /**
   * Results from the exact words come with the reason.
   */
  public function testNotice(): void {
    $this->answer([
      'mode' => 'basic',
      'notice' => ['code' => 'ai_limit_reached', 'message' => 'Smart search is used up for this month. These results come from a simple word search.'],
      'results' => [['id' => 'node:5:en', 'type' => 'page', 'title' => 'Housing news', 'url' => 'https://www.example.ca/news/housing']],
    ]);

    $this->drupalGet('/search', ['query' => ['q' => 'mice']]);

    $this->assertSession()->elementTextEquals('css', '.wahosearch-search__notice', 'Smart search is used up for this month. These results come from a simple word search.');
    $this->assertSession()->linkExists('Housing news');
  }

  /**
   * Suggestions come from this server, for the visitor's language and roles.
   */
  public function testSuggestions(): void {
    \Drupal::state()->set('wahosearch_connector_test.suggestions', [
      ['text' => 'mice housing', 'url' => NULL],
      ['text' => 'Mice guidelines', 'url' => 'https://www.example.ca/files/mice.pdf'],
      ['text' => 'Bad', 'url' => 'javascript:alert(1)'],
    ]);

    $this->drupalGet('/search');
    $form = $this->assertSession()->elementExists('css', 'form[data-wahosearch-suggest]');
    $this->assertStringEndsWith('/wahosearch/suggest', $form->getAttribute('data-wahosearch-suggest'));
    $this->assertSession()->responseContains('search-suggest.js');

    $json = $this->drupalGet('/fr/wahosearch/suggest', ['query' => ['q' => 'sou']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([
      'suggestions' => [
        ['text' => 'mice housing', 'url' => NULL],
        ['text' => 'Mice guidelines', 'url' => 'https://www.example.ca/files/mice.pdf'],
        ['text' => 'Bad', 'url' => NULL],
      ],
    ], json_decode($json, TRUE));
    $this->assertSession()->responseNotContains(self::SECRET);

    \Drupal::state()->resetCache();
    $sent = \Drupal::state()->get('wahosearch_connector_test.suggest_requests', []);
    $this->assertSame([['query' => 'sou', 'langcode' => 'fr', 'roles' => []]], $sent);

    // Too short: nothing is asked.
    $this->drupalGet('/wahosearch/suggest', ['query' => ['q' => 'm']]);
    $this->assertSame('{"suggestions":[]}', $this->getSession()->getPage()->getContent());

    // Turned off: no route, no script.
    $this->config('wahosearch_connector.settings')->set('search_suggestions', FALSE)->save();
    $this->drupalGet('/wahosearch/suggest', ['query' => ['q' => 'mice']]);
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('/search');
    $this->assertSession()->elementNotExists('css', 'form[data-wahosearch-suggest]');
  }

  /**
   * The French page searches French content, with the visitor's roles.
   */
  public function testFrenchPageAndRoles(): void {
    $member = $this->drupalCreateUser();
    $member->addRole('member');
    $member->save();
    $this->drupalLogin($member);

    $this->drupalGet('/fr/recherche', ['query' => ['q' => 'souris']]);

    $this->assertSession()->statusCodeEquals(200);
    $request = $this->requests()[0];
    $this->assertSame('fr', $request['langcode']);
    $this->assertEqualsCanonicalizing(['authenticated', 'member'], $request['roles']);
    $this->assertSession()->pageTextContains('No results for “souris”.');
  }

  /**
   * Visitors see the platform's message, never technical details.
   */
  public function testErrors(): void {
    $this->answer(['error' => ['status' => 429, 'code' => 'rate_limited', 'message' => 'Too many searches. Try again in a minute.']]);
    $this->drupalGet('/search', ['query' => ['q' => 'mice']]);
    $this->assertSession()->pageTextContains('Too many searches. Try again in a minute.');

    $this->answer(['error' => ['status' => 401, 'message' => 'Invalid signature']]);
    $this->drupalGet('/search', ['query' => ['q' => 'mice']]);
    $this->assertSession()->pageTextContains('Search is not available right now.');
    $this->assertSession()->pageTextNotContains('Invalid signature');
  }

  /**
   * An empty question shows only the box, and each search reaches the platform.
   */
  public function testEmptyQuestionAndNoCaching(): void {
    $this->drupalGet('/search');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('q');
    $this->assertSame([], $this->requests());

    $this->drupalGet('/search', ['query' => ['q' => 'mice']]);
    $this->drupalGet('/search', ['query' => ['q' => 'mice']]);
    $this->assertCount(2, $this->requests(), 'Repeated searches are not served from the page cache.');
  }

  /**
   * Free plan sites show the hosted page in a frame, in the page language.
   */
  public function testHostedMode(): void {
    $this->config('wahosearch_connector.settings')->set('search_mode', 'hosted')->save();

    $this->drupalGet('/fr/recherche', ['query' => ['q' => 'logement des souris']]);

    $this->assertSession()->statusCodeEquals(200);
    $frame = $this->assertSession()->elementExists('css', 'iframe.wahosearch-frame');
    $this->assertSame('https://platform.example/embed/site-1/search?lang=fr&q=logement%20des%20souris', $frame->getAttribute('src'));
    $this->assertSame('https://platform.example', $frame->getAttribute('data-wahosearch-origin'));
    $this->assertSession()->responseContains('search-frame.js');
    $this->assertSession()->responseNotContains(self::SECRET);
    $this->assertSame([], $this->requests(), 'Drupal does not search itself in this mode.');
  }

  /**
   * Free plan sites only get the hosted page, and cannot choose addresses.
   */
  public function testFreePlan(): void {
    \Drupal::state()->set(SyncManager::STATE_PLAN, ['plan' => 'free', 'search_page' => 'hosted', 'time' => time()]);
    $this->config('wahosearch_connector.settings')->set('search_mode', 'drupal')->save();

    $this->drupalGet('/site-search', ['query' => ['q' => 'mice']]);
    $this->assertSession()->elementExists('css', 'iframe.wahosearch-frame');
    $this->assertSame([], $this->requests());

    $this->drupalLogin($this->drupalCreateUser(['administer wahosearch connector', 'access content']));
    $this->drupalGet('/admin/config/search/wahosearch-connector/search-display');
    $this->assertSession()->pageTextContains('Your plan uses the page hosted by WahoSearch.');
    $this->assertSession()->elementAttributeExists('css', 'input[name="search_mode"][value="drupal"]', 'disabled');
    $this->assertSession()->elementAttributeExists('css', 'input[name="address_en"]', 'disabled');
    $this->submitForm([], 'Save configuration');
    $this->assertSame('hosted', $this->config('wahosearch_connector.settings')->get('search_mode'));
    $this->drupalGet('/search');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * When the platform refuses because of the plan, the hosted page is shown.
   */
  public function testRefusedSearchFallsBackToTheHostedPage(): void {
    $this->answer(['error' => ['status' => 403, 'code' => 'hosted_page_only', 'message' => 'This plan uses the hosted search page.']]);

    $this->drupalGet('/search', ['query' => ['q' => 'mice']]);

    $this->assertSession()->elementExists('css', 'iframe.wahosearch-frame');
    $this->assertSession()->pageTextNotContains('This plan uses the hosted search page.');
    \Drupal::state()->resetCache();
    $this->assertSame('hosted', \Drupal::state()->get(SyncManager::STATE_PLAN)['search_page']);
  }

  /**
   * Addresses are checked, and the page can be switched off.
   */
  public function testSettings(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer wahosearch connector', 'access content']));
    $path = '/admin/config/search/wahosearch-connector/search-display';
    $this->drupalGet($path);
    $this->assertSession()->fieldValueEquals('address_en', '/search');
    $this->assertSession()->fieldValueEquals('address_fr', '/recherche');

    $this->submitForm(['address_en' => 'no slash'], 'Save configuration');
    $this->assertSession()->pageTextContains('The address must start with /');

    $this->submitForm(['address_en' => '', 'search_page' => FALSE], 'Save configuration');
    $this->drupalLogout();
    $this->drupalGet('/site-search');
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('/search');
    $this->assertSession()->statusCodeEquals(404);
  }

}
