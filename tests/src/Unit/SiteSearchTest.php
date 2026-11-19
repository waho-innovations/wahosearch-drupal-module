<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\wahosearch_connector\Service\ApiClient;
use Drupal\wahosearch_connector\Service\SiteSearch;

/**
 * @coversDefaultClass \Drupal\wahosearch_connector\Service\SiteSearch
 * @group wahosearch_connector
 */
class SiteSearchTest extends UnitTestCase {

  /**
   * Requests sent to the platform.
   */
  private array $sent = [];

  /**
   * Builds the service for a page in a language, seen by a visitor.
   */
  private function service(string $langcode, array $roles, mixed $answer, bool $configured = TRUE): SiteSearch {
    $this->sent = [];
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $api = $this->createMock(ApiClient::class);
    $api->method('isConfigured')->willReturn($configured);
    $api->method('search')->willReturnCallback(function (array $request) use ($answer) {
      $this->sent[] = $request;
      if ($answer instanceof \Throwable) {
        throw $answer;
      }
      return $answer;
    });

    $languages = $this->createMock(LanguageManagerInterface::class);
    $languages->method('getCurrentLanguage')->willReturn(new Language(['id' => $langcode]));

    $user = $this->createMock(AccountInterface::class);
    $user->method('isAnonymous')->willReturn($roles === []);
    $user->method('getRoles')->willReturn($roles === [] ? [AccountInterface::ANONYMOUS_ROLE] : $roles);

    return new SiteSearch($api, $languages, $user, $this->createMock(LoggerChannelInterface::class));
  }

  /**
   * The language of the page and the visitor's roles are sent.
   */
  public function testSendsLanguageAndRoles(): void {
    $search = $this->service('fr', ['authenticated', 'member'], ['results' => [], 'has_more' => FALSE]);

    $outcome = $search->search("  logement   des souris ", 2);

    $this->assertSame([['query' => 'logement des souris', 'langcode' => 'fr', 'roles' => ['authenticated', 'member'], 'page' => 2]], $this->sent);
    $this->assertSame('fr', $outcome['langcode']);
    $this->assertSame(2, $outcome['page']);
    $this->assertNull($outcome['error']);
  }

  /**
   * Anonymous visitors send no roles, so they only see public content.
   */
  public function testAnonymousVisitorsSendNoRoles(): void {
    $search = $this->service('en', [], ['results' => []]);

    $search->search('mice');

    $this->assertSame([], $this->sent[0]['roles']);
  }

  /**
   * Nothing is sent for an empty question or another language.
   */
  public function testNothingSentWithoutQuestionOrForOtherLanguages(): void {
    $search = $this->service('en', [], ['results' => []]);
    $this->assertNull($search->search('   ')['error']);
    $this->assertSame([], $this->sent);

    $search = $this->service('es', [], ['results' => []]);
    $this->assertSame('Search is available in English and French only.', $search->search('ratones')['error']);
    $this->assertSame([], $this->sent);

    $search = $this->service('en', [], ['results' => []], FALSE);
    $this->assertSame('Search is not available right now. Please try again later.', $search->search('mice')['error']);
    $this->assertSame([], $this->sent);
  }

  /**
   * Results keep what templates need, and unsafe links are dropped.
   */
  public function testResults(): void {
    $search = $this->service('en', [], [
      'has_more' => TRUE,
      'results' => [
        [
          'id' => 'node:4:en:file:9',
          'type' => 'file',
          'title' => 'Mice guidelines',
          'url' => 'https://www.example.ca/files/Mice.PDF',
          'snippet' => 'Mice should be housed in groups.',
          'heading' => ['Housing', 7],
          'content_type' => 'Document',
          'tags' => ['Mice'],
          'parent' => ['id' => 'node:4:en', 'title' => 'Guidelines', 'url' => 'https://www.example.ca/guidelines'],
        ],
        ['id' => 'bad', 'type' => 'page', 'title' => 'Bad', 'url' => 'javascript:alert(1)'],
        ['id' => 'bad-parent', 'type' => 'file', 'title' => '', 'url' => '/files/notes.pdf', 'parent' => ['url' => 'data:text/html,x']],
      ],
    ]);

    $outcome = $search->search('mice');

    $this->assertTrue($outcome['has_more']);
    $this->assertCount(2, $outcome['results']);
    $this->assertSame([
      'id' => 'node:4:en:file:9',
      'type' => 'file',
      'title' => 'Mice guidelines',
      'url' => 'https://www.example.ca/files/Mice.PDF',
      'snippet' => 'Mice should be housed in groups.',
      'heading' => ['Housing'],
      'content_type' => 'Document',
      'tags' => ['Mice'],
      'extension' => 'pdf',
      'parent' => ['title' => 'Guidelines', 'url' => 'https://www.example.ca/guidelines'],
    ], $outcome['results'][0]);
    $this->assertSame('notes.pdf', $outcome['results'][1]['title'], 'A missing title falls back to the file name.');
    $this->assertNull($outcome['results'][1]['parent']);
  }

  /**
   * The last page offered never says there is more.
   */
  public function testPagesStopAtTheLimit(): void {
    $search = $this->service('en', [], ['results' => [], 'has_more' => TRUE]);

    $outcome = $search->search('mice', 99);

    $this->assertSame(SiteSearch::MAX_PAGE, $this->sent[0]['page']);
    $this->assertFalse($outcome['has_more']);
  }

  /**
   * The platform's messages for visitors are shown as they are.
   */
  public function testPlatformMessagesAreShown(): void {
    $error = new ApiException('HTTP 429', 429, TRUE, NULL, 'rate_limited', 'Trop de recherches. Réessayez dans une minute.');
    $outcome = $this->service('fr', [], $error)->search('souris');

    $this->assertSame('Trop de recherches. Réessayez dans une minute.', $outcome['error']);
    $this->assertSame([], $outcome['results']);
  }

  /**
   * Other failures show a general message without technical details.
   */
  public function testOtherFailuresShowAGeneralMessage(): void {
    $outcome = $this->service('en', [], new ApiException('Could not reach the platform: cURL error 28', 0, TRUE))->search('mice');
    $this->assertSame('Search is not available right now. Please try again later.', $outcome['error']);

    $outcome = $this->service('en', [], new ApiException('HTTP 401: Invalid signature', 401, FALSE, NULL, NULL, 'Invalid signature'))->search('mice');
    $this->assertSame('Search is not available right now. Please try again later.', $outcome['error']);

    $outcome = $this->service('en', [], new ApiException('HTTP 503', 503, TRUE, NULL, 'search_busy'))->search('mice');
    $this->assertSame('Search is busy right now. Please try again in a moment.', $outcome['error']);
  }

  /**
   * A plan that only allows the hosted page is reported, not shown as an error.
   */
  public function testHostedOnlyPlansAreReported(): void {
    $error = new ApiException('HTTP 403', 403, FALSE, NULL, 'hosted_page_only', 'This plan uses the hosted search page.');
    $outcome = $this->service('en', [], $error)->search('mice');

    $this->assertTrue($outcome['hosted_only']);
    $this->assertNull($outcome['error']);
  }

}
