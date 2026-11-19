<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Decides which content types, fields and items are sent to the platform.
 */
class ContentSelection {

  /**
   * Field roles an administrator can pick.
   */
  public const ROLES = ['content', 'summary', 'metadata', 'taxonomy'];

  /**
   * Base fields never offered for mapping (handled separately or internal).
   */
  protected const SKIPPED_BASE_FIELDS = [
    'nid', 'uuid', 'vid', 'langcode', 'type', 'revision_timestamp',
    'revision_uid', 'revision_log', 'status', 'title', 'created',
    'changed', 'promote', 'sticky', 'default_langcode',
    'revision_default', 'revision_translation_affected', 'path',
    'content_translation_source', 'content_translation_outdated',
    'menu_link', 'comment', 'moderation_state',
  ];

  /**
   * Field types the normalizer knows how to export.
   */
  public const SUPPORTED_FIELD_TYPES = [
    'text', 'text_long', 'text_with_summary', 'string', 'string_long',
    'list_string', 'list_integer', 'list_float', 'integer', 'decimal',
    'float', 'boolean', 'email', 'telephone', 'datetime', 'daterange',
    'timestamp', 'link', 'entity_reference', 'image', 'file',
    'entity_reference_revisions',
  ];

  public const EXCLUDED_TABLE = 'wahosearch_connector_excluded';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected Connection $database,
    protected TimeInterface $time,
    protected PathMatcherInterface $pathMatcher,
    protected AliasManagerInterface $aliasManager,
  ) {}

  /**
   * Lists every content type on the site with its label.
   *
   * @return array<string, string>
   *   Labels keyed by machine name.
   */
  public function getAllBundles(): array {
    $bundles = [];
    foreach ($this->bundleInfo->getBundleInfo('node') as $bundle => $info) {
      $bundles[$bundle] = (string) $info['label'];
    }
    asort($bundles, SORT_NATURAL | SORT_FLAG_CASE);
    return $bundles;
  }

  /**
   * Returns the machine names of the indexed content types.
   */
  public function getEnabledBundles(): array {
    $existing = $this->bundleInfo->getBundleInfo('node');
    $enabled = [];
    foreach ($this->getBundleSettings() as $bundle => $settings) {
      if (!empty($settings['enabled']) && isset($existing[$bundle])) {
        $enabled[] = (string) $bundle;
      }
    }
    return $enabled;
  }

  /**
   * Whether a content type is indexed.
   */
  public function isBundleEnabled(string $bundle): bool {
    return in_array($bundle, $this->getEnabledBundles(), TRUE);
  }

  /**
   * Whether content hidden from anonymous visitors is sent for a bundle.
   */
  public function includesPrivate(string $bundle): bool {
    return !empty($this->getBundleSettings()[$bundle]['include_private']);
  }

  /**
   * Whether PDF files attached to a content type are sent.
   */
  public function includesPdfs(string $bundle): bool {
    return !empty($this->getBundleSettings()[$bundle]['include_pdfs']);
  }

  /**
   * Returns the internal path and the alias of a translation.
   */
  public function getNodePaths(NodeInterface $node): array {
    $internal = '/node/' . $node->id();
    return [$internal, $this->aliasManager->getAliasByPath($internal, $node->language()->getId())];
  }

  /**
   * Whether the URL path rules allow a translation.
   */
  public function isPathAllowed(NodeInterface $node): bool {
    $config = $this->configFactory->get('wahosearch_connector.settings');
    return $this->pathPassesRules($this->getNodePaths($node), (string) $config->get('include_paths'), (string) $config->get('exclude_paths'));
  }

  /**
   * Applies include and exclude patterns. Exclusions win.
   */
  public function pathPassesRules(array $paths, string $include, string $exclude): bool {
    if (trim($exclude) !== '' && $this->anyPathMatches($paths, $exclude)) {
      return FALSE;
    }
    return trim($include) === '' || $this->anyPathMatches($paths, $include);
  }

  /**
   * Returns the priority section of a translation, or NULL.
   *
   * @return array{pattern: string, rank: int}|null
   *   The first matching section. Rank 1 is the most important.
   */
  public function getPrioritySection(NodeInterface $node): ?array {
    $sections = (string) $this->configFactory->get('wahosearch_connector.settings')->get('priority_sections');
    if (trim($sections) === '') {
      return NULL;
    }
    $paths = $this->getNodePaths($node);
    foreach (array_values(array_filter(explode("\n", $sections))) as $index => $pattern) {
      if ($this->anyPathMatches($paths, $pattern)) {
        return ['pattern' => $pattern, 'rank' => $index + 1];
      }
    }
    return NULL;
  }

  /**
   * Counts the published translations new path rules would add or remove.
   *
   * @return array{add: int, remove: int, add_samples: string[], remove_samples: string[]}
   *   Totals with up to five titles each.
   */
  public function previewPathRules(string $include, string $exclude): array {
    $result = ['add' => 0, 'remove' => 0, 'add_samples' => [], 'remove_samples' => []];
    $enabled = $this->getEnabledBundles();
    if (!$enabled) {
      return $result;
    }
    $config = $this->configFactory->get('wahosearch_connector.settings');
    $currentInclude = (string) $config->get('include_paths');
    $currentExclude = (string) $config->get('exclude_paths');
    $rows = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid', 'langcode', 'title'])
      ->condition('status', 1)
      ->condition('type', $enabled, 'IN')
      ->orderBy('nid')
      ->execute();
    foreach ($rows as $row) {
      $internal = '/node/' . $row->nid;
      $paths = [$internal, $this->aliasManager->getAliasByPath($internal, $row->langcode)];
      $before = $this->pathPassesRules($paths, $currentInclude, $currentExclude);
      $after = $this->pathPassesRules($paths, $include, $exclude);
      if ($before === $after) {
        continue;
      }
      $key = $after ? 'add' : 'remove';
      $result[$key]++;
      if (count($result[$key . '_samples']) < 5) {
        $result[$key . '_samples'][] = $row->title;
      }
    }
    return $result;
  }

  /**
   * Trims a list of patterns and removes empty lines.
   */
  public static function cleanPatterns(string $text): string {
    return implode("\n", array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), 'strlen'));
  }

  /**
   * Returns the lines that are not usable patterns.
   *
   * @param string $text
   *   One pattern per line.
   * @param bool $sectionsOnly
   *   Only accept patterns covering a whole section, such as /admissions/*.
   */
  public static function invalidPatterns(string $text, bool $sectionsOnly = FALSE): array {
    $invalid = [];
    foreach (array_filter(explode("\n", self::cleanPatterns($text))) as $line) {
      $valid = $sectionsOnly ? preg_match('@^/.*/\*$@', $line) : ($line === '<front>' || str_starts_with($line, '/'));
      if (!$valid) {
        $invalid[] = $line;
      }
    }
    return $invalid;
  }

  /**
   * Returns all bundle settings from config.
   */
  public function getBundleSettings(): array {
    return $this->configFactory->get('wahosearch_connector.settings')->get('bundles') ?: [];
  }

  /**
   * Fields an administrator may map for a bundle.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   Field definitions keyed by field name.
   */
  public function getCandidateFields(string $bundle): array {
    $candidates = [];
    foreach ($this->entityFieldManager->getFieldDefinitions('node', $bundle) as $name => $definition) {
      if (in_array($name, self::SKIPPED_BASE_FIELDS, TRUE)) {
        continue;
      }
      if (!in_array($definition->getType(), self::SUPPORTED_FIELD_TYPES, TRUE)) {
        continue;
      }
      // Only Paragraphs are read through revision references.
      if ($definition->getType() === 'entity_reference_revisions' && !self::isParagraphField($definition)) {
        continue;
      }
      $candidates[$name] = $definition;
    }
    uasort($candidates, fn (FieldDefinitionInterface $a, FieldDefinitionInterface $b) => strnatcasecmp((string) $a->getLabel(), (string) $b->getLabel()));
    return $candidates;
  }

  /**
   * Whether a field holds Paragraphs, whose text is part of the page.
   */
  public static function isParagraphField(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'entity_reference_revisions'
      && $definition->getFieldStorageDefinition()->getSetting('target_type') === 'paragraph';
  }

  /**
   * Whether a field references taxonomy terms.
   */
  public function isTaxonomyField(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'entity_reference'
      && $definition->getFieldStorageDefinition()->getSetting('target_type') === 'taxonomy_term';
  }

  /**
   * Suggests a mapping when the administrator has not saved one yet.
   */
  public function getDefaultMapping(string $bundle): array {
    $mapping = [];
    foreach ($this->getCandidateFields($bundle) as $name => $definition) {
      if ($this->isTaxonomyField($definition)) {
        $mapping[$name] = ['target' => self::defaultTarget($name), 'role' => 'taxonomy'];
      }
      elseif ($name === 'body' || in_array($definition->getType(), ['text_with_summary', 'text_long'], TRUE) || self::isParagraphField($definition)) {
        $mapping[$name] = ['target' => self::defaultTarget($name), 'role' => 'content'];
      }
    }
    return $mapping;
  }

  /**
   * Returns the field mapping for a bundle (saved or default).
   *
   * @return array<string, array{target: string, role: string}>
   *   Mapping keyed by field name, only fields that still exist.
   */
  public function getFieldMapping(string $bundle): array {
    $settings = $this->getBundleSettings()[$bundle] ?? [];
    $mapping = isset($settings['fields']) && is_array($settings['fields']) ? $settings['fields'] : $this->getDefaultMapping($bundle);
    $candidates = $this->getCandidateFields($bundle);
    return array_intersect_key($mapping, $candidates);
  }

  /**
   * Builds the default target name for a field.
   */
  public static function defaultTarget(string $fieldName): string {
    $target = preg_replace('/^field_/', '', $fieldName);
    return $target === '' ? $fieldName : $target;
  }

  /**
   * Taxonomy vocabularies used by mapped fields of indexed content types.
   */
  public function getReferencedVocabularies(): array {
    $vocabularies = [];
    foreach ($this->getEnabledBundles() as $bundle) {
      $definitions = $this->getCandidateFields($bundle);
      foreach (array_keys($this->getFieldMapping($bundle)) as $fieldName) {
        $definition = $definitions[$fieldName] ?? NULL;
        if (!$definition || !$this->isTaxonomyField($definition)) {
          continue;
        }
        $targets = $definition->getSetting('handler_settings')['target_bundles'] ?? NULL;
        if (empty($targets)) {
          // No restriction means every vocabulary.
          $targets = array_keys($this->bundleInfo->getBundleInfo('taxonomy_term'));
        }
        foreach ($targets as $vid) {
          $vocabularies[$vid] = $vid;
        }
      }
    }
    return array_values($vocabularies);
  }

  /**
   * Taxonomy fields mapped on any indexed bundle, keyed by bundle.
   *
   * @return array<string, string[]>
   *   Field names keyed by bundle.
   */
  public function getMappedTaxonomyFields(): array {
    $result = [];
    foreach ($this->getEnabledBundles() as $bundle) {
      $definitions = $this->getCandidateFields($bundle);
      foreach (array_keys($this->getFieldMapping($bundle)) as $fieldName) {
        if (isset($definitions[$fieldName]) && $this->isTaxonomyField($definitions[$fieldName])) {
          $result[$bundle][] = $fieldName;
        }
      }
    }
    return $result;
  }

  /**
   * Whether any of the paths matches the patterns.
   */
  protected function anyPathMatches(array $paths, string $patterns): bool {
    foreach ($paths as $path) {
      if ($this->pathMatcher->matchPath($path, $patterns)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a node is marked as excluded.
   */
  public function isExcluded(string $entityType, int|string $entityId): bool {
    return (bool) $this->database->select(self::EXCLUDED_TABLE, 'e')
      ->fields('e', ['entity_id'])
      ->condition('entity_type', $entityType)
      ->condition('entity_id', (int) $entityId)
      ->execute()
      ->fetchField();
  }

  /**
   * Marks or unmarks an item as excluded.
   */
  public function setExcluded(string $entityType, int|string $entityId, bool $excluded, int $uid = 0): void {
    if ($excluded) {
      $this->database->merge(self::EXCLUDED_TABLE)
        ->keys(['entity_type' => $entityType, 'entity_id' => (int) $entityId])
        ->fields(['uid' => $uid, 'created' => $this->time->getRequestTime()])
        ->execute();
    }
    else {
      $this->database->delete(self::EXCLUDED_TABLE)
        ->condition('entity_type', $entityType)
        ->condition('entity_id', (int) $entityId)
        ->execute();
    }
  }

  /**
   * Returns excluded items.
   *
   * @return array
   *   Rows with entity_type, entity_id, uid, created.
   */
  public function getExcluded(): array {
    return $this->database->select(self::EXCLUDED_TABLE, 'e')
      ->fields('e')
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Checks whether the node type and node itself may be indexed at all.
   *
   * Translation level checks (published, access) happen in the sync manager.
   */
  public function isNodeEligible(NodeInterface $node): bool {
    return $this->isBundleEnabled($node->bundle()) && !$this->isExcluded('node', $node->id());
  }

}
