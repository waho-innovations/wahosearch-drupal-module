<?php

declare(strict_types=1);

namespace Drupal\Tests\wahosearch_connector\Kernel;

use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\wahosearch_connector\Service\ItemTracker;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\RoleInterface;

/**
 * Tests indexing of attached PDF files.
 *
 * @group wahosearch_connector
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class PdfDocumentsTest extends ConnectorKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $directory = 'public://';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

    FieldStorageConfig::create([
      'field_name' => 'field_documents',
      'entity_type' => 'node',
      'type' => 'file',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_documents',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Documents',
      'settings' => ['file_extensions' => 'pdf txt', 'description_field' => TRUE],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->config('wahosearch_connector.settings')
      ->set('bundles', ['article' => ['enabled' => TRUE, 'include_private' => FALSE, 'include_pdfs' => TRUE]])
      ->save();
  }

  /**
   * Creates a stored file.
   */
  private function createFile(string $name, string $mime = 'application/pdf', string $content = '%PDF-1.4 handbook'): FileInterface {
    file_put_contents('public://' . $name, $content);
    $file = File::create(['uri' => 'public://' . $name, 'filename' => $name, 'filemime' => $mime, 'status' => 1]);
    $file->save();
    return $file;
  }

  /**
   * Creates an article with attached files.
   */
  private function articleWith(array $files): NodeInterface {
    return $this->createArticle(['field_documents' => array_map(fn ($item) => is_array($item) ? $item : ['target_id' => $item->id()], $files)]);
  }

  /**
   * Returns every document sent so far, keyed by ID.
   */
  private function sentDocuments(): array {
    $bodies = RecordingApiClient::bodies('content/bulk');
    return $bodies ? array_column(array_merge(...array_column($bodies, 'documents')), NULL, 'id') : [];
  }

  /**
   * A PDF is its own document, linked to the page it belongs to.
   */
  public function testPdfDocument(): void {
    $file = $this->createFile('handbook-2027.pdf');
    $node = $this->articleWith([['target_id' => $file->id(), 'display' => 1, 'description' => 'Admissions handbook']]);
    $this->drainQueue();

    $pageId = 'node:' . $node->id() . ':en';
    $id = $pageId . ':file:' . $file->id();
    $documents = $this->sentDocuments();
    $this->assertArrayHasKey($pageId, $documents);
    $this->assertArrayNotHasKey('document_type', $documents[$pageId]);
    $document = $documents[$id];
    $this->assertSame('pdf', $document['document_type']);
    $this->assertSame('Admissions handbook', $document['title']);
    $this->assertSame($pageId, $document['parent']['id']);
    $this->assertSame(hash('sha256', '%PDF-1.4 handbook'), $document['file']['sha256']);
    $this->assertStringEndsWith('handbook-2027.pdf', $document['url']);
    $this->assertTrue($document['access']['public']);
    $this->assertArrayNotHasKey('content_base64', $document['file']);
    $this->assertSame([], RecordingApiClient::bodies('content/file'), 'The file is not sent unless asked for.');
  }

  /**
   * Only files the platform asks for are uploaded, once.
   */
  public function testUploadOnlyWhenAsked(): void {
    $file = $this->createFile('guide.pdf');
    $node = $this->articleWith([$file]);
    $id = 'node:' . $node->id() . ':en:file:' . $file->id();
    RecordingApiClient::$bulkResponse = ['need_files' => [$id]];
    $this->drainQueue();

    $uploads = RecordingApiClient::bodies('content/file');
    $this->assertCount(1, $uploads);
    $this->assertSame($id, $uploads[0]['id']);
    $this->assertSame('%PDF-1.4 handbook', base64_decode($uploads[0]['content_base64']));
    $this->assertSame(hash('sha256', '%PDF-1.4 handbook'), $uploads[0]['sha256']);
    $this->assertNotNull($this->container->get('wahosearch_connector.tracker')->getHash($id));

    Node::load($node->id())->save();
    $this->drainQueue();
    $this->assertCount(1, RecordingApiClient::bodies('content/file'), 'An unchanged file is not sent again.');
  }

  /**
   * A changed file gets a new hash and is sent again.
   */
  public function testChangedFileIsResent(): void {
    $file = $this->createFile('guide.pdf');
    $node = $this->articleWith([$file]);
    $this->drainQueue();
    file_put_contents('public://guide.pdf', '%PDF-1.4 second edition');
    $this->syncManager()->queueNode((int) $node->id());
    $this->drainQueue();
    $this->assertCount(2, RecordingApiClient::bodies('content/bulk'));
  }

  /**
   * Removing the attachment removes only the PDF from the index.
   */
  public function testDetachedFileIsRemoved(): void {
    $file = $this->createFile('guide.pdf');
    $node = $this->articleWith([$file]);
    $this->drainQueue();

    $node = Node::load($node->id());
    $node->set('field_documents', [])->save();
    $this->drainQueue();
    $this->assertSame(['node:' . $node->id() . ':en:file:' . $file->id()], $this->deletedIds());
  }

  /**
   * Other formats are never presented as searchable documents.
   */
  public function testOnlyPdfs(): void {
    $this->articleWith([
      $this->createFile('notes.txt', 'text/plain'),
      $this->createFile('old-form.doc', 'application/msword', 'old word'),
    ]);
    $this->drainQueue();
    $this->assertCount(1, $this->sentDocuments());
  }

  /**
   * Word, Excel and PowerPoint files are sent like PDFs, with their own type.
   */
  public function testOfficeFiles(): void {
    $files = [
      'docx' => $this->createFile('form.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'PK word'),
      'xlsx' => $this->createFile('fees.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'PK sheet'),
      'pptx' => $this->createFile('module.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'PK slides'),
    ];
    $node = $this->articleWith(array_values($files));
    $this->drainQueue();

    $documents = $this->sentDocuments();
    $this->assertCount(4, $documents);
    foreach ($files as $type => $file) {
      $document = $documents['node:' . $node->id() . ':en:file:' . $file->id()];
      $this->assertSame($type, $document['document_type']);
      $this->assertSame($file->getMimeType(), $document['file']['mime']);
      $this->assertSame(hash_file('sha256', $file->getFileUri()), $document['file']['sha256']);
    }
  }

  /**
   * Nothing is sent unless PDFs are switched on for the content type.
   */
  public function testContentTypeSetting(): void {
    $this->config('wahosearch_connector.settings')
      ->set('bundles', ['article' => ['enabled' => TRUE, 'include_private' => FALSE, 'include_pdfs' => FALSE]])
      ->save();
    $this->articleWith([$this->createFile('guide.pdf')]);
    $this->drainQueue();
    $this->assertCount(1, $this->sentDocuments());
  }

  /**
   * A PDF over the limit is skipped and logged without failing the page.
   */
  public function testOversizedFile(): void {
    $this->config('wahosearch_connector.settings')->set('pdf_max_mb', 1)->save();
    $node = $this->articleWith([$this->createFile('huge.pdf', 'application/pdf', str_repeat('x', 1048577))]);
    $this->assertSame(0, $this->syncManager()->syncNodes([(int) $node->id()]));
    $this->assertCount(1, $this->sentDocuments());
    $this->assertStringContainsString('huge.pdf', (string) $this->container->get('database')->select('wahosearch_connector_log', 'l')->fields('l', ['message'])->condition('operation', 'pdf')->execute()->fetchField());
  }

  /**
   * A PDF on restricted content stays out unless restricted content is on.
   */
  public function testRestrictedParent(): void {
    user_role_revoke_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->articleWith([$this->createFile('private.pdf')]);
    $this->drainQueue();
    $this->assertSame([], $this->sentDocuments());
  }

  /**
   * A refused upload is a failure and the document can be retried.
   */
  public function testPermanentUploadFailure(): void {
    $file = $this->createFile('guide.pdf');
    $node = $this->articleWith([$file]);
    $id = 'node:' . $node->id() . ':en:file:' . $file->id();
    RecordingApiClient::$bulkResponse = ['need_files' => [$id]];
    RecordingApiClient::$failPath = 'content/file';
    RecordingApiClient::$failWith = new ApiException('Refused', 422, FALSE);

    $this->assertSame(1, $this->syncManager()->syncNodes([(int) $node->id()]));
    $tracker = $this->container->get('wahosearch_connector.tracker');
    $this->assertSame(1, $tracker->countByStatus()[ItemTracker::STATUS_ERROR]);
    $this->assertNull($tracker->getHash($id));
    $this->assertNotNull($tracker->getHash('node:' . $node->id() . ':en'), 'The page itself still went through.');
  }

  /**
   * An outage during upload keeps the item queued.
   */
  public function testRetryableUploadFailure(): void {
    $file = $this->createFile('guide.pdf');
    $node = $this->articleWith([$file]);
    RecordingApiClient::$bulkResponse = ['need_files' => ['node:' . $node->id() . ':en:file:' . $file->id()]];
    RecordingApiClient::$failPath = 'content/file';
    RecordingApiClient::$failWith = new ApiException('Busy', 503, TRUE);
    $this->drainQueue();
    $this->assertSame(1, $this->syncManager()->getQueueSize());
  }

  /**
   * Files inside media items are found, and media access is respected.
   */
  public function testMediaDocuments(): void {
    $this->enableModules(['image', 'media']);
    $this->installEntitySchema('media');
    $this->installConfig(['media']);
    $type = MediaType::create(['id' => 'document', 'label' => 'Document', 'source' => 'file']);
    $type->save();
    $source = $type->getSource()->createSourceField($type);
    $source->getFieldStorageDefinition()->save();
    $source->save();
    $type->set('source_configuration', ['source_field' => $source->getName()])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Media',
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $file = $this->createFile('calendar.pdf');
    $media = Media::create([
      'bundle' => 'document',
      'name' => 'Academic calendar',
      $source->getName() => ['target_id' => $file->id()],
      'status' => 1,
    ]);
    $media->save();

    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID, 'member'] as $role) {
      user_role_grant_permissions($role, ['view media']);
    }
    $node = $this->createArticle(['field_media' => [['target_id' => $media->id()]]]);
    $this->drainQueue();
    $id = 'node:' . $node->id() . ':en:file:' . $file->id();
    $this->assertSame('Academic calendar', $this->sentDocuments()[$id]['title']);

    // A file anonymous visitors can see is public, even when logged-in roles
    // were not given "View media".
    RecordingApiClient::reset();
    user_role_revoke_permissions(RoleInterface::AUTHENTICATED_ID, ['view media']);
    user_role_revoke_permissions('member', ['view media']);
    $this->container->get('entity_type.manager')->getAccessControlHandler('media')->resetCache();
    $node = Node::load($node->id());
    $node->setTitle('Calendar page')->save();
    $this->drainQueue();
    $this->assertArrayHasKey($id, $this->sentDocuments());
    $this->assertSame([], $this->deletedIds());

    RecordingApiClient::reset();
    user_role_revoke_permissions(RoleInterface::ANONYMOUS_ID, ['view media']);
    $this->container->get('entity_type.manager')->getAccessControlHandler('media')->resetCache();
    $this->syncManager()->queueNode((int) $node->id());
    $this->drainQueue();
    $this->assertSame([$id], $this->deletedIds(), 'A role losing media access removes the PDF.');
  }

  /**
   * A field holding a copy of the file's text is only sent without a PDF.
   */
  public function testTextCopyOnlyWithoutPdf(): void {
    $this->config('wahosearch_connector.settings')
      ->set('bundles', ['article' => [
        'enabled' => TRUE,
        'include_private' => FALSE,
        'include_pdfs' => TRUE,
        'fields' => ['body' => ['target' => 'body', 'role' => 'content', 'pdf_fallback' => TRUE]],
      ]])
      ->save();
    $broken = "Date de r\u{C3}\u{A9}vision : Ao\u{C3}\u{BB}t 2022";

    $withPdf = $this->articleWith([$this->createFile('guide.pdf')]);
    $withPdf->set('body', $broken)->save();
    $withoutPdf = $this->createArticle(['body' => $broken, 'title' => "Ann\u{C3}\u{A9}e 2022"]);
    $this->drainQueue();

    $documents = $this->sentDocuments();
    $this->assertArrayNotHasKey('body', $documents['node:' . $withPdf->id() . ':en']['fields'], 'The PDF carries the text.');
    $page = $documents['node:' . $withoutPdf->id() . ':en'];
    $this->assertSame('Année 2022', $page['title'], 'Broken accents are repaired.');
    $this->assertStringContainsString('Date de révision : Août 2022', json_encode($page['fields']['body']['values'], JSON_UNESCAPED_UNICODE));
  }

}
