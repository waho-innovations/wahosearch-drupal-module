<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Functional;

use Drupal\wahosearch_connector\Service\ApiClient;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * Tests indexing rules screens and the public search embedding.
 *
 * @group wahosearch_connector
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class SearchDisplayTest extends BrowserTestBase {

  private const BASE = '/admin/config/search/wahosearch-connector';

  private const SECRET = 'very-secret-value';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'taxonomy', 'block', 'wahosearch_connector'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The published article used by the tests.
   */
  private Node $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->node = Node::create(['type' => 'article', 'title' => 'Scholarship news', 'status' => 1]);
    $this->node->save();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $this->config('wahosearch_connector.settings')
      ->set('endpoint', 'https://platform.example')
      ->set('site_id', 'site-1')
      ->set('immediate', FALSE)
      ->set('bundles', ['article' => ['enabled' => TRUE, 'include_private' => FALSE]])
      ->save();
    \Drupal::state()->set(ApiClient::SECRET_STATE_KEY, self::SECRET);
    $this->drupalLogin($this->drupalCreateUser([
      'administer wahosearch connector',
      'access content',
      'access administration pages',
    ]));
  }

  /**
   * Rules are validated, previewed and need confirmation to remove content.
   */
  public function testIndexingRules(): void {
    $path = self::BASE . '/indexing-rules';
    $this->drupalGet($path);
    $this->submitForm(['include_paths' => 'news'], 'Save rules');
    $this->assertSession()->pageTextContains('Paths must start with /');

    $this->submitForm(['include_paths' => '', 'priority_sections' => '/news/one'], 'Save rules');
    $this->assertSession()->pageTextContains('Priority sections must start with / and end with /*');

    $exclude = '/node/' . $this->node->id();
    $this->submitForm(['priority_sections' => '', 'exclude_paths' => $exclude], 'Preview changes');
    $this->assertSession()->pageTextContains('Will be removed');
    $this->assertSession()->pageTextContains('Scholarship news');
    $this->assertSame('', $this->config('wahosearch_connector.settings')->get('exclude_paths'), 'A preview saves nothing.');

    $this->submitForm(['exclude_paths' => $exclude], 'Save rules');
    $this->assertSession()->pageTextContains('These rules remove about 1 items from search');
    $this->assertSame('', $this->config('wahosearch_connector.settings')->get('exclude_paths'));

    $this->submitForm(['exclude_paths' => $exclude, 'confirm_removal' => TRUE], 'Save rules');
    $this->assertSession()->pageTextContains('The rules were saved');
    $this->assertSame($exclude, $this->config('wahosearch_connector.settings')->get('exclude_paths'));

    $this->drupalGet(self::BASE . '/logs');
    $this->assertSession()->pageTextContains('Indexing rules changed by user');
  }

  /**
   * Unticking a content type with indexed content asks for confirmation.
   */
  public function testContentTypeRemovalWarning(): void {
    \Drupal::database()->insert('wahosearch_connector_item')->fields([
      'doc_id' => 'node:' . $this->node->id() . ':en',
      'entity_type' => 'node',
      'entity_id' => $this->node->id(),
      'langcode' => 'en',
      'bundle' => 'article',
      'status' => 'indexed',
      'hash' => 'abc',
      'last_synced' => 1,
    ])->execute();

    $this->drupalGet(self::BASE . '/content-types');
    $this->assertSession()->fieldExists('bundles[article][include_pdfs]');
    $this->submitForm(['bundles[article][enabled]' => FALSE], 'Save content types');
    $this->assertSession()->pageTextContains('This removes indexed documents from search: Article (1)');
    $this->assertTrue($this->config('wahosearch_connector.settings')->get('bundles')['article']['enabled'], 'Nothing changes without the confirmation.');

    $this->submitForm(['bundles[article][enabled]' => FALSE, 'confirm_removal' => TRUE], 'Save content types');
    $this->assertSession()->pageTextContains('The content types were saved.');
    $this->assertFalse($this->config('wahosearch_connector.settings')->get('bundles')['article']['enabled']);
  }

  /**
   * The search page and chatbot appear without leaking the secret.
   */
  public function testPublicEmbedding(): void {
    $this->drupalGet('/site-search');
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet(self::BASE . '/search-display');
    $this->submitForm(['search_page' => TRUE, 'chatbot' => TRUE], 'Save configuration');
    $this->drupalPlaceBlock('wahosearch_connector_search_box');

    $this->drupalGet('/admin/config/search/wahosearch-connector');
    $this->assertSession()->responseNotContains('widget/chatbot.js');

    $this->drupalLogout();
    $this->drupalGet('/site-search', ['query' => ['q' => '<script>alert(1)</script>']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('widget/search.js');
    $this->assertSession()->responseContains('https://platform.example/widget/chatbot.js');
    $this->assertSession()->responseContains('data-site-id="site-1"');
    $this->assertSession()->responseContains('data-language="en"');
    $this->assertSession()->elementExists('css', '.wahosearch-search');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
    $this->assertSession()->responseNotContains(self::SECRET);
    $this->assertSession()->fieldExists('q');

    $this->drupalGet('/node/' . $this->node->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('widget/chatbot.js');
    $this->assertSession()->responseNotContains('widget/search.js');
    $this->assertSession()->responseContains('action="' . base_path() . 'site-search"');
    $this->assertSession()->responseNotContains(self::SECRET);
  }

  /**
   * The connection page shows status and records key changes.
   */
  public function testConnectionStatus(): void {
    $this->drupalGet(self::BASE);
    $this->assertSession()->pageTextContains('Not tested yet.');
    $this->assertSession()->pageTextContains('Last successful synchronization');

    $this->submitForm(['endpoint' => 'http://127.0.0.1:9', 'site_id' => 'site-1', 'secret' => 'rotated-value'], 'Save and test connection');
    $this->assertSession()->pageTextContains('the connection failed');
    $this->assertSession()->pageTextContains('Not connected, checked');
    $this->assertSession()->responseNotContains('rotated-value');
    $this->assertSame('rotated-value', \Drupal::state()->get(ApiClient::SECRET_STATE_KEY));

    $this->drupalGet(self::BASE . '/logs');
    $this->assertSession()->pageTextContains('Secret key replaced.');
    $this->assertSession()->pageTextContains('Connection test failed');
  }

}
