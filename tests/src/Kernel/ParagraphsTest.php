<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Kernel;

use Drupal\Component\FileCache\FileCacheFactory;
use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Tests that the text of Paragraphs is sent as page content.
 *
 * Runs only where the Paragraphs module is available.
 *
 * @group wahosearch_connector
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ParagraphsTest extends ConnectorKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'file',
    'node',
    'taxonomy',
    'language',
    'content_translation',
    'path_alias',
    'entity_reference_revisions',
    'paragraphs',
    'wahosearch_connector',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    FileCacheFactory::setConfiguration([FileCacheFactory::DISABLE_CACHE => TRUE]);
    $modules = (new ExtensionDiscovery($this->root ?? static::getDrupalRoot()))->scan('module');
    if (!isset($modules['paragraphs'], $modules['entity_reference_revisions'])) {
      $this->markTestSkipped('The Paragraphs module is not installed.');
    }
    parent::setUp();
    $this->installEntitySchema('paragraph');

    ParagraphsType::create(['id' => 'text', 'label' => 'Text'])->save();
    ParagraphsType::create(['id' => 'section', 'label' => 'Section'])->save();
    $this->addField('paragraph', 'text', 'field_heading', 'string');
    $this->addField('paragraph', 'text', 'field_text', 'text_long');
    $this->addField('paragraph', 'section', 'field_items', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1);
    // As Paragraphs recommends, the host field is not translatable: the
    // paragraphs themselves are translated.
    $this->addField('node', 'article', 'field_content', 'entity_reference_revisions', ['target_type' => 'paragraph'], -1, FALSE);
    $this->addField('node', 'article', 'field_revisions', 'entity_reference_revisions', ['target_type' => 'node'], 1, FALSE);

    $manager = $this->container->get('content_translation.manager');
    $manager->setEnabled('paragraph', 'text', TRUE);
    $manager->setEnabled('paragraph', 'section', TRUE);
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Adds a field to a bundle.
   */
  private function addField(string $entityType, string $bundle, string $name, string $type, array $settings = [], int $cardinality = 1, bool $translatable = TRUE): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $name,
      'translatable' => $translatable,
    ])->save();
  }

  /**
   * Creates a text paragraph.
   */
  private function textParagraph(string $heading, string $text): ParagraphInterface {
    return Paragraph::create([
      'type' => 'text',
      'langcode' => 'en',
      'field_heading' => $heading,
      'field_text' => ['value' => $text, 'format' => 'plain_text'],
    ]);
  }

  /**
   * Builds an article with a text paragraph and a section holding another.
   */
  private function articleWithParagraphs(): NodeInterface {
    $section = Paragraph::create([
      'type' => 'section',
      'langcode' => 'en',
      'field_items' => [$this->textParagraph('Deadlines', 'Apply before March.')],
    ]);
    return $this->createArticle([
      'body' => [],
      'field_content' => [$this->textParagraph('Admissions', 'Admissions open in May.'), $section],
    ]);
  }

  /**
   * Returns the content field of a node translation's document.
   */
  private function contentText(NodeInterface $node, string $langcode): string {
    $documents = $this->container->get('wahosearch_connector.normalizer')->buildDocuments($node);
    $document = $documents['node:' . $node->id() . ':' . $langcode];
    $this->assertSame('content', $document['fields']['content']['role']);
    return implode("\n", array_column($document['fields']['content']['values'], 'text'));
  }

  /**
   * Paragraphs fields are offered and mapped as content by default.
   */
  public function testParagraphFieldIsOffered(): void {
    $selection = $this->container->get('wahosearch_connector.content_selection');
    $candidates = $selection->getCandidateFields('article');

    $this->assertArrayHasKey('field_content', $candidates);
    // Revision references to anything other than paragraphs are not offered.
    $this->assertArrayNotHasKey('field_revisions', $candidates);
    $this->assertSame(['target' => 'content', 'role' => 'content'], $selection->getFieldMapping('article')['field_content']);
  }

  /**
   * Text of paragraphs, also nested ones, is sent in field order.
   */
  public function testParagraphTextIsSent(): void {
    $text = $this->contentText($this->articleWithParagraphs(), 'en');

    $this->assertStringContainsString('Admissions', $text);
    $this->assertStringContainsString('Admissions open in May.', $text);
    $this->assertStringContainsString('Apply before March.', $text);
    $this->assertLessThan(strpos($text, 'Apply before March.'), strpos($text, 'Admissions open in May.'));
  }

  /**
   * A French page sends the French text of its paragraphs.
   */
  public function testTranslatedParagraphs(): void {
    $node = $this->articleWithParagraphs();
    $node->addTranslation('fr', ['title' => 'Admissions FR', 'status' => 1])->save();
    $first = $node->get('field_content')->entity;
    $first->addTranslation('fr', [
      'field_heading' => 'Inscriptions',
      'field_text' => ['value' => 'Les inscriptions ouvrent en mai.', 'format' => 'plain_text'],
    ])->save();

    $fr = $this->contentText(Node::load($node->id()), 'fr');
    $en = $this->contentText(Node::load($node->id()), 'en');

    $this->assertStringContainsString('Les inscriptions ouvrent en mai.', $fr);
    $this->assertStringNotContainsString('Admissions open in May.', $fr);
    // Untranslated paragraphs fall back to their original text.
    $this->assertStringContainsString('Apply before March.', $fr);
    $this->assertStringContainsString('Admissions open in May.', $en);
    $this->assertStringNotContainsString('Les inscriptions', $en);
  }

  /**
   * Unpublished paragraphs are left out.
   */
  public function testUnpublishedParagraphIsLeftOut(): void {
    $node = $this->articleWithParagraphs();
    $first = $node->get('field_content')->entity;
    $first->setUnpublished()->save();

    $text = $this->contentText(Node::load($node->id()), 'en');

    $this->assertStringNotContainsString('Admissions open in May.', $text);
    $this->assertStringContainsString('Apply before March.', $text);
  }

}
