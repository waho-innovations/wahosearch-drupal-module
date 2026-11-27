<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Kernel;

use Drupal\wahosearch_connector\Service\ContentNormalizer;
use Drupal\user\RoleInterface;

/**
 * Tests the documents built from Drupal content.
 *
 * @group wahosearch_connector
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ContentNormalizerTest extends ConnectorKernelTestBase {

  /**
   * Builds the documents of a node.
   */
  private function documents($node): array {
    return $this->container->get('wahosearch_connector.normalizer')->buildDocuments($node);
  }

  /**
   * One document per language, with fields, taxonomy and access.
   */
  public function testBilingualDocument(): void {
    $parent = $this->createTerm('Education');
    $child = $this->createTerm('Scholarships', ['parent' => $parent->id()]);
    $parent->addTranslation('fr', ['name' => 'Enseignement'])->save();
    $child->addTranslation('fr', ['name' => 'Bourses'])->save();

    $node = $this->createArticle(['field_topics' => [$child->id()]]);
    $node->addTranslation('fr', [
      'title' => 'Bourses en ingenierie',
      'body' => ['value' => "Texte fran\u{E7}ais.", 'format' => 'plain_text'],
      'field_topics' => [$child->id()],
      'status' => 1,
    ])->save();

    $documents = $this->documents($node);
    $this->assertSame(['node:' . $node->id() . ':en', 'node:' . $node->id() . ':fr'], array_keys($documents));

    $en = $documents['node:' . $node->id() . ':en'];
    $fr = $documents['node:' . $node->id() . ':fr'];

    $this->assertSame('Engineering scholarships', $en['title']);
    $this->assertSame('article', $en['bundle']);
    $this->assertSame('Article', $en['bundle_label']);
    $this->assertTrue($en['is_default_translation']);
    $this->assertFalse($fr['is_default_translation']);
    $this->assertStringContainsString('/node/' . $node->id(), $en['url']);
    $this->assertStringStartsWith('http', $en['url']);
    $this->assertSame(['en' => $en['id'], 'fr' => $fr['id']], $en['translations']);

    $this->assertSame('content', $en['fields']['body']['role']);
    $this->assertStringContainsString("Texte fran\u{E7}ais.", $fr['fields']['body']['values'][0]['text']);

    $this->assertSame('Scholarships', $en['taxonomy'][0]['name']);
    $this->assertSame('topics', $en['taxonomy'][0]['field']);
    $this->assertSame('Education', $en['taxonomy'][0]['ancestors'][0]['name']);
    $this->assertSame('Bourses', $fr['taxonomy'][0]['name']);
    $this->assertSame('Enseignement', $fr['taxonomy'][0]['ancestors'][0]['name']);

    $this->assertTrue($en['access']['public']);
    $this->assertContains(RoleInterface::ANONYMOUS_ID, $en['access']['roles']);
    $this->assertContains('member', $en['access']['roles']);
  }

  /**
   * Restricted content is left out unless the administrator includes it.
   */
  public function testRestrictedContent(): void {
    user_role_revoke_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $node = $this->createArticle();

    $this->assertSame([], $this->documents($node));

    $this->config('wahosearch_connector.settings')
      ->set('bundles', ['article' => ['enabled' => TRUE, 'include_private' => TRUE]])
      ->save();
    $document = $this->documents($node)['node:' . $node->id() . ':en'];

    $this->assertFalse($document['access']['public']);
    $this->assertNotContains(RoleInterface::ANONYMOUS_ID, $document['access']['roles']);
    $this->assertContains('member', $document['access']['roles']);
  }

  /**
   * Access results must not leak from one role to the next.
   *
   * The test module allows this node for the member role only, which is
   * decided after the permission checks, where results get cached per user.
   */
  public function testAccessIsWorkedOutPerRole(): void {
    $this->container->get('module_installer')->install(['wahosearch_connector_test']);
    $this->config('wahosearch_connector.settings')
      ->set('bundles', ['article' => ['enabled' => TRUE, 'include_private' => TRUE]])
      ->save();

    $node = $this->createArticle(['title' => 'Members only']);
    $document = $this->container->get('wahosearch_connector.normalizer')->buildDocuments($node)['node:' . $node->id() . ':en'];

    $this->assertSame(['member'], $document['access']['roles']);
    $this->assertFalse($document['access']['public']);
  }

  /**
   * Unpublished translations are skipped, published ones stay.
   */
  public function testUnpublishedTranslationSkipped(): void {
    // A role that can see unpublished content must not cause it to be sent.
    user_role_grant_permissions('member', ['bypass node access']);
    $this->config('wahosearch_connector.settings')
      ->set('bundles', ['article' => ['enabled' => TRUE, 'include_private' => TRUE]])
      ->save();
    $node = $this->createArticle();
    $node->addTranslation('fr', ['title' => 'Brouillon', 'status' => 0])->save();

    $documents = $this->documents($node);

    $this->assertArrayHasKey('node:' . $node->id() . ':en', $documents);
    $this->assertArrayNotHasKey('node:' . $node->id() . ':fr', $documents);
    $this->assertSame(['en'], array_keys($documents['node:' . $node->id() . ':en']['translations']));
  }

  /**
   * Excluded items and content types that are switched off produce nothing.
   */
  public function testExcludedAndDisabled(): void {
    $node = $this->createArticle();
    $this->container->get('wahosearch_connector.content_selection')->setExcluded('node', $node->id(), TRUE);
    $this->assertSame([], $this->documents($node));

    $this->container->get('wahosearch_connector.content_selection')->setExcluded('node', $node->id(), FALSE);
    $this->assertNotSame([], $this->documents($node));

    $this->config('wahosearch_connector.settings')
      ->set('bundles', ['article' => ['enabled' => FALSE, 'include_private' => FALSE]])
      ->save();
    $this->assertSame([], $this->documents($node));
  }

  /**
   * Only the mapped fields leave the site, under their configured names.
   */
  public function testFieldMappingIsRespected(): void {
    $term = $this->createTerm('Education');
    $node = $this->createArticle(['field_topics' => [$term->id()]]);
    $this->config('wahosearch_connector.settings')->set('bundles', [
      'article' => [
        'enabled' => TRUE,
        'include_private' => FALSE,
        'fields' => ['body' => ['target' => 'main_text', 'role' => 'summary']],
      ],
    ])->save();

    $document = $this->documents($node)['node:' . $node->id() . ':en'];

    $this->assertSame(['main_text'], array_keys($document['fields']));
    $this->assertSame('summary', $document['fields']['main_text']['role']);
    $this->assertSame([], $document['taxonomy']);
  }

  /**
   * Saving without edits keeps the same hash.
   */
  public function testHashIgnoresChangedDate(): void {
    $node = $this->createArticle();
    $before = $this->documents($node)['node:' . $node->id() . ':en']['hash'];

    $node->setChangedTime($node->getChangedTime() + 500);
    $this->assertSame($before, $this->documents($node)['node:' . $node->id() . ':en']['hash']);

    $node->setTitle('Another title');
    $this->assertNotSame($before, $this->documents($node)['node:' . $node->id() . ':en']['hash']);
  }

  /**
   * Blocks of HTML do not glue words together.
   */
  public function testHtmlToText(): void {
    $this->assertSame('One Two & three', ContentNormalizer::htmlToText('<p>One</p><p>Two &amp; three</p>'));
    $this->assertSame('Safe', ContentNormalizer::htmlToText('<script>alert(1)</script><style>a{}</style>Safe'));
  }

}
