<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Controller;

use Drupal\wahosearch_connector\Service\SyncLog;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shows synchronization logs and errors.
 */
final class SyncLogController extends ControllerBase {

  public function __construct(
    private readonly SyncLog $log,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('wahosearch_connector.sync_log'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders the log page.
   */
  public function list(Request $request): array {
    $level = $request->query->get('level');
    $level = in_array($level, SyncLog::LEVELS, TRUE) ? $level : NULL;

    $route = 'wahosearch_connector.logs';
    $links = [
      Link::fromTextAndUrl($this->t('All'), Url::fromRoute($route)),
      Link::fromTextAndUrl($this->t('Errors'), Url::fromRoute($route, [], ['query' => ['level' => 'error']])),
      Link::fromTextAndUrl($this->t('Warnings'), Url::fromRoute($route, [], ['query' => ['level' => 'warning']])),
    ];

    $rows = [];
    foreach ($this->log->getPage($level) as $entry) {
      $rows[] = [
        $this->dateFormatter->format((int) $entry->created, 'short'),
        $entry->level,
        $entry->operation,
        $entry->message,
        (string) $entry->reference,
      ];
    }

    return [
      '#cache' => ['max-age' => 0],
      'filters' => ['#theme' => 'item_list', '#items' => $links, '#title' => $this->t('Show')],
      'table' => [
        '#type' => 'table',
        '#caption' => $this->t('Synchronization log'),
        '#header' => [
          $this->t('Date'),
          $this->t('Level'),
          $this->t('Operation'),
          $this->t('Message'),
          $this->t('Item'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No log entries yet.'),
      ],
      'pager' => ['#type' => 'pager'],
    ];
  }

}
