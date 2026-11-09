<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Service;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Turns Drupal content into the documents the platform expects.
 *
 * One document is produced per published translation. Access is worked out
 * per role, so the platform knows who may see each document.
 */
class ContentNormalizer {

  /**
   * First user ID used for the throwaway per-role accounts.
   *
   * The entity access cache is keyed by user ID, so each role needs its own.
   */
  protected const ROLE_ACCOUNT_BASE_UID = 2147480000;

  /**
   * Per-role accounts, built once per request.
   *
   * @var \Drupal\Core\Session\AccountInterface[]|null
   */
  protected ?array $roleAccounts = NULL;

  public function __construct(
    protected ContentSelection $selection,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Builds the documents of every eligible translation of a node.
   *
   * @return array<string, array>
   *   Documents keyed by document ID. Empty when nothing may be indexed.
   */
  public function buildDocuments(NodeInterface $node): array {
    if (!$this->selection->isNodeEligible($node)) {
      return [];
    }
    $documents = [];
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $translation = $node->getTranslation($langcode);
      if (!$translation->isPublished()) {
        continue;
      }
      $accounts = $this->getAllowedAccounts($translation);
      if (!$accounts) {
        continue;
      }
      if (!isset($accounts[AccountInterface::ANONYMOUS_ROLE]) && !$this->selection->includesPrivate($node->bundle())) {
        continue;
      }
      $document = $this->normalize($translation, $accounts);
      $documents[$document['id']] = $document;
    }
    return $documents;
  }

  /**
   * Returns the document ID of a node translation.
   */
  public static function documentId(int|string $nodeId, string $langcode): string {
    return 'node:' . $nodeId . ':' . $langcode;
  }

  /**
   * Returns the document ID of a term.
   */
  public static function termDocumentId(int|string $termId): string {
    return 'taxonomy_term:' . $termId;
  }

  /**
   * Builds the document of a term with its name in every language.
   */
  public function normalizeTerm(TermInterface $term): array {
    $names = [];
    foreach (array_keys($term->getTranslationLanguages()) as $langcode) {
      $translation = $term->getTranslation($langcode);
      if ($translation->isPublished()) {
        $names[$langcode] = $translation->getName();
      }
    }
    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($term->bundle());
    $parents = [];
    foreach ($term->get('parent') as $item) {
      if ((int) $item->target_id > 0) {
        $parents[] = (int) $item->target_id;
      }
    }
    return [
      'id' => self::termDocumentId($term->id()),
      'tid' => (int) $term->id(),
      'uuid' => $term->uuid(),
      'vocabulary' => $term->bundle(),
      'vocabulary_label' => $vocabulary ? (string) $vocabulary->label() : $term->bundle(),
      'names' => $names,
      'parents' => $parents,
      'weight' => (int) $term->getWeight(),
    ];
  }

  /**
   * Builds the document of a single translation.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node, already switched to the translation to export.
   * @param \Drupal\Core\Session\AccountInterface[] $accounts
   *   Accounts allowed to view the node, keyed by role ID.
   */
  protected function normalize(NodeInterface $node, array $accounts): array {
    $langcode = $node->language()->getId();
    $bundles = $this->bundleInfo->getBundleInfo('node');

    $translations = [];
    foreach (array_keys($node->getTranslationLanguages()) as $other) {
      if ($node->getTranslation($other)->isPublished()) {
        $translations[$other] = self::documentId($node->id(), $other);
      }
    }

    $fields = [];
    $taxonomy = [];
    $definitions = $this->selection->getCandidateFields($node->bundle());
    foreach ($this->selection->getFieldMapping($node->bundle()) as $fieldName => $map) {
      $definition = $definitions[$fieldName];
      $items = $node->get($fieldName);
      if ($items->isEmpty() || !$this->allCanView($items, $accounts)) {
        continue;
      }
      if ($this->selection->isTaxonomyField($definition)) {
        foreach ($this->exportTerms($items, $langcode, $accounts) as $term) {
          $taxonomy[] = ['field' => $map['target']] + $term;
        }
        continue;
      }
      $values = $this->exportValues($items, $definition, $accounts);
      if ($values) {
        $fields[$map['target']] = [
          'type' => $definition->getType(),
          'label' => (string) $definition->getLabel(),
          'role' => $map['role'],
          'values' => $values,
        ];
      }
    }

    $document = [
      'id' => self::documentId($node->id(), $langcode),
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'bundle' => $node->bundle(),
      'bundle_label' => (string) ($bundles[$node->bundle()]['label'] ?? $node->bundle()),
      'langcode' => $langcode,
      'is_default_translation' => $node->isDefaultTranslation(),
      'translations' => $translations,
      'title' => $node->getTitle(),
      'url' => $this->nodeUrl($node),
      'created' => gmdate('c', (int) $node->getCreatedTime()),
      'fields' => $fields,
      'taxonomy' => $taxonomy,
      'access' => [
        'public' => isset($accounts[AccountInterface::ANONYMOUS_ROLE]),
        'roles' => array_map('strval', array_keys($accounts)),
      ],
    ];
    // The changed date stays out of the hash, so saving a node without
    // editing anything does not send it again.
    $document['hash'] = hash('sha256', json_encode($document, JSON_THROW_ON_ERROR));
    $document['changed'] = gmdate('c', (int) $node->getChangedTime());
    return $document;
  }

  /**
   * Returns the accounts that may view a translation, keyed by role ID.
   *
   * @return \Drupal\Core\Session\AccountInterface[]
   *   Allowed per-role accounts.
   */
  protected function getAllowedAccounts(NodeInterface $node): array {
    return array_filter($this->getRoleAccounts(), fn (AccountInterface $account) => $node->access('view', $account));
  }

  /**
   * Builds one throwaway account per role.
   *
   * @return \Drupal\Core\Session\AccountInterface[]
   *   Accounts keyed by role ID.
   */
  protected function getRoleAccounts(): array {
    if ($this->roleAccounts === NULL) {
      $this->roleAccounts = [];
      $offset = 0;
      foreach (array_keys($this->entityTypeManager->getStorage('user_role')->loadMultiple()) as $roleId) {
        if ($roleId === AccountInterface::ANONYMOUS_ROLE) {
          $this->roleAccounts[$roleId] = new AnonymousUserSession();
          continue;
        }
        $roles = array_values(array_unique([AccountInterface::AUTHENTICATED_ROLE, $roleId]));
        $this->roleAccounts[$roleId] = new UserSession([
          'uid' => self::ROLE_ACCOUNT_BASE_UID + $offset++,
          'roles' => $roles,
        ]);
      }
    }
    return $this->roleAccounts;
  }

  /**
   * Whether every allowed account may view a field.
   *
   * A role that can open the page but not see this field would otherwise
   * get the value through search, so the field is left out instead.
   */
  protected function allCanView(FieldItemListInterface $items, array $accounts): bool {
    foreach ($accounts as $account) {
      if (!$items->access('view', $account)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Returns the absolute URL of a translation.
   */
  protected function nodeUrl(NodeInterface $node): string {
    return Url::fromRoute('entity.node.canonical', ['node' => $node->id()], [
      'absolute' => TRUE,
      'language' => $node->language(),
    ])->toString();
  }

  /**
   * Exports taxonomy references in the language of the document.
   *
   * @return array
   *   Term summaries with their ancestors, root first.
   */
  protected function exportTerms(FieldItemListInterface $items, string $langcode, array $accounts): array {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = [];
    foreach ($items as $item) {
      $term = $item->entity;
      if (!$term instanceof TermInterface) {
        continue;
      }
      $term = $term->hasTranslation($langcode) ? $term->getTranslation($langcode) : $term;
      foreach ($accounts as $account) {
        if (!$term->access('view label', $account)) {
          continue 2;
        }
      }
      $ancestors = [];
      foreach ($termStorage->loadAllParents($term->id()) as $ancestor) {
        if ((int) $ancestor->id() === (int) $term->id()) {
          continue;
        }
        $ancestor = $ancestor->hasTranslation($langcode) ? $ancestor->getTranslation($langcode) : $ancestor;
        array_unshift($ancestors, ['tid' => (int) $ancestor->id(), 'name' => $ancestor->getName()]);
      }
      $terms[] = [
        'tid' => (int) $term->id(),
        'uuid' => $term->uuid(),
        'vocabulary' => $term->bundle(),
        'name' => $term->getName(),
        'ancestors' => $ancestors,
      ];
    }
    return $terms;
  }

  /**
   * Exports the values of a non-taxonomy field.
   */
  protected function exportValues(FieldItemListInterface $items, FieldDefinitionInterface $definition, array $accounts): array {
    $values = [];
    foreach ($items as $item) {
      $value = $this->exportItem($item, $definition, $accounts);
      if ($value !== NULL) {
        $values[] = $value;
      }
    }
    return $values;
  }

  /**
   * Exports one field value, or NULL when there is nothing to send.
   */
  protected function exportItem(FieldItemInterface $item, FieldDefinitionInterface $definition, array $accounts): mixed {
    switch ($definition->getType()) {
      case 'text':
      case 'text_long':
      case 'text_with_summary':
        $html = (string) $item->processed;
        $value = ['html' => $html, 'text' => self::htmlToText($html)];
        if ($definition->getType() === 'text_with_summary' && (string) $item->summary !== '') {
          $value['summary'] = self::htmlToText((string) $item->summary);
        }
        return $value;

      case 'list_string':
      case 'list_integer':
      case 'list_float':
        $allowed = $definition->getFieldStorageDefinition()->getSetting('allowed_values');
        return [
          'value' => $item->value,
          'label' => is_array($allowed) && isset($allowed[$item->value]) ? (string) $allowed[$item->value] : (string) $item->value,
        ];

      case 'integer':
        return (int) $item->value;

      case 'decimal':
      case 'float':
        return (float) $item->value;

      case 'boolean':
        return (bool) $item->value;

      case 'timestamp':
        return $item->value === NULL ? NULL : gmdate('c', (int) $item->value);

      case 'daterange':
        return ['start' => $item->value, 'end' => $item->end_value];

      case 'link':
        try {
          $url = Url::fromUri($item->uri, ['absolute' => TRUE])->toString();
        }
        catch (\InvalidArgumentException) {
          return NULL;
        }
        return ['url' => $url, 'title' => (string) $item->title];

      case 'image':
      case 'file':
        $file = $item->entity;
        if (!$file) {
          return NULL;
        }
        $value = [
          'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
          'filename' => $file->getFilename(),
          'mime' => $file->getMimeType(),
        ];
        if ($definition->getType() === 'image') {
          $value['alt'] = (string) $item->alt;
        }
        return $value;

      case 'entity_reference':
        $target = $item->entity;
        if (!$target) {
          return NULL;
        }
        foreach ($accounts as $account) {
          if (!$target->access('view label', $account)) {
            return NULL;
          }
        }
        return [
          'target_type' => $target->getEntityTypeId(),
          'target_id' => (string) $target->id(),
          'label' => (string) $target->label(),
        ];

      default:
        return $item->value === NULL ? NULL : (string) $item->value;
    }
  }

  /**
   * Converts HTML to plain text without gluing words together.
   */
  public static function htmlToText(string $html): string {
    $text = preg_replace('@<(script|style)\b[^>]*>.*?</\1>@is', ' ', $html) ?? $html;
    $text = preg_replace('@<(/?(?:p|div|br|li|ul|ol|tr|td|th|h[1-6]|blockquote|section|article)\b[^>]*)>@i', ' <$1>', $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
  }

}
