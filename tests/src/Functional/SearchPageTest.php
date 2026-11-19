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
    $this->assertSame([['query' => 'mice housing', 'langcode' => 'en', 'roles' => [], 'page' => 1]], $this->requests());

    $this->clickLink('More results');
    $session->linkExists('Previous');
    $this->assertSame(2, $this->requests()[1]['page']);
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
