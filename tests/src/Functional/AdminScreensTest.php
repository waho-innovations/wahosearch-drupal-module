<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Functional;

use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the administration screens through a real browser session.
 *
 * @group wahosearch_connector
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class AdminScreensTest extends BrowserTestBase {

  private const BASE = '/admin/config/search/wahosearch-connector';

  private const SECRET = 'very-secret-value';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'taxonomy', 'wahosearch_connector'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The screens work from connection to exclusion.
   */
  public function testScreens(): void {
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    $node = Node::create(['type' => 'article', 'title' => 'My article', 'status' => 1]);
    $node->save();

    foreach (['', '/content-types', '/sync', '/excluded', '/logs'] as $path) {
      $this->drupalGet(self::BASE . $path);
      $this->assertSession()->statusCodeEquals(403);
    }

    $this->drupalLogin($this->drupalCreateUser([
      'administer wahosearch connector',
      'exclude content from wahosearch',
      'access content',
      'bypass node access',
      'access administration pages',
    ]));

    $this->drupalGet(self::BASE . '/sync');
    $this->assertSession()->pageTextContains('The connection is not set up yet');
    $this->assertSession()->buttonExists('Run full synchronization');
    $this->assertSession()->elementAttributeExists('css', 'input[value="Run full synchronization"]', 'disabled');

    $this->drupalGet(self::BASE);
    $this->submitForm([
      'endpoint' => 'http://platform.example',
      'site_id' => 'site-1',
      'secret' => self::SECRET,
    ], 'Save and test connection');
    $this->assertSession()->pageTextContains('The platform URL must use HTTPS');

    // Saved, even when the platform cannot be reached, and never shown again.
    $this->submitForm([
      'endpoint' => 'http://127.0.0.1:9',
      'site_id' => 'site-1',
      'secret' => self::SECRET,
    ], 'Save and test connection');
    $this->assertSession()->pageTextContains('the connection failed');
    $this->assertSession()->responseNotContains(self::SECRET);
    $this->assertSession()->pageTextContains('A secret key is saved');
    $this->assertSame('site-1', $this->config('wahosearch_connector.settings')->get('site_id'));

    $this->drupalGet(self::BASE . '/content-types');
    $this->assertSession()->pageTextContains('Article');
    $this->assertSession()->pageTextContains('Basic page');
    $this->submitForm(['bundles[article][enabled]' => TRUE], 'Save content types');
    $this->assertSession()->pageTextContains('The content types were saved.');
    $this->assertSession()->pageTextContains('1 items were queued');
    $this->assertSession()->checkboxChecked('bundles[article][enabled]');
    $this->assertSession()->checkboxNotChecked('bundles[page][enabled]');

    $this->drupalGet(self::BASE . '/content-types/article/fields');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Fields of Article');
    $this->submitForm([
      'fields[body][include]' => TRUE,
      'fields[body][target]' => 'Bad Name',
    ], 'Save fields');
    $this->assertSession()->pageTextContains('Index names can only use');
    $this->submitForm([
      'fields[body][include]' => TRUE,
      'fields[body][target]' => 'main_text',
      'fields[body][role]' => 'summary',
    ], 'Save fields');
    $this->assertSession()->pageTextContains('The field selection was saved.');
    $bundles = $this->config('wahosearch_connector.settings')->get('bundles');
    $this->assertSame(['target' => 'main_text', 'role' => 'summary'], $bundles['article']['fields']['body']);
    $this->assertTrue($bundles['article']['enabled'], 'Saving fields keeps the content type enabled.');

    $this->drupalGet(self::BASE . '/content-types/missing/fields');
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet('/node/' . $node->id() . '/edit');
    $this->assertSession()->checkboxNotChecked('wahosearch_exclude');
    $this->submitForm(['wahosearch_exclude' => TRUE], 'Save');
    $this->drupalGet('/node/' . $node->id() . '/edit');
    $this->assertSession()->checkboxChecked('wahosearch_exclude');

    $this->drupalGet(self::BASE . '/excluded');
    $this->assertSession()->pageTextContains('My article');
    $this->submitForm(['items[' . $node->id() . ']' => TRUE], 'Include selected items again');
    $this->assertSession()->pageTextContains('No individual content is excluded');

    $page = Node::create(['type' => 'page', 'title' => 'Internal', 'status' => 1]);
    $page->save();
    $this->drupalGet('/node/' . $page->id() . '/edit');
    $this->assertSession()->fieldNotExists('wahosearch_exclude');

    $this->drupalGet(self::BASE . '/sync');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Changes waiting to be sent');
    // The buttons run through the batch system and report the outage.
    $this->submitForm([], 'Send pending changes now');
    $this->assertSession()->pageTextContains('changes are still waiting');
    $this->drupalGet(self::BASE . '/sync');
    $this->submitForm([], 'Run full synchronization');
    $this->assertSession()->pageTextContains('The full synchronization stopped');
    $this->assertSession()->pageTextContains('Nothing was removed from the index');

    $this->drupalGet(self::BASE . '/logs');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet(self::BASE . '/logs', ['query' => ['level' => '<script>']]);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/admin/reports/status');
  }

}
