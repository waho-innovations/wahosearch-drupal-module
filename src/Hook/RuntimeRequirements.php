<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Url;

/**
 * Status report entry for the connector.
 *
 * Drupal 11.3 and later call the hook below. Older versions reach build()
 * through wahosearch_connector_requirements().
 */
final class RuntimeRequirements {

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    return self::build();
  }

  /**
   * Builds the status report entry.
   */
  public static function build(): array {
    $configured = \Drupal::service('wahosearch_connector.api_client')->isConfigured();
    $hasEnum = enum_exists(RequirementSeverity::class);
    return [
      'wahosearch_connector' => [
        'title' => t('WahoSearch Connector'),
        'value' => $configured ? t('Configured') : t('Not configured'),
        'description' => $configured ? '' : t('Enter the platform details on the <a href=":url">connection page</a>.', [
          ':url' => Url::fromRoute('wahosearch_connector.settings')->toString(),
        ]),
        'severity' => $configured
          ? ($hasEnum ? RequirementSeverity::OK : 0)
          : ($hasEnum ? RequirementSeverity::Warning : 1),
      ],
    ];
  }

}
