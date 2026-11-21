<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector_test;

use Drupal\wahosearch_connector\Exception\ApiException;
use Drupal\wahosearch_connector\Service\ApiClient;

/**
 * Answers searches from state instead of the platform.
 *
 * State 'wahosearch_connector_test.answer' holds the response, or an array
 * with an 'error' key (status, code, message) to fail. Each request is added
 * to 'wahosearch_connector_test.requests'.
 */
class FakeApiClient extends ApiClient {

  /**
   * {@inheritdoc}
   */
  public function search(array $request): array {
    $requests = $this->state->get('wahosearch_connector_test.requests', []);
    $requests[] = $request;
    $this->state->set('wahosearch_connector_test.requests', $requests);

    $answer = $this->state->get('wahosearch_connector_test.answer', ['results' => [], 'has_more' => FALSE]);
    if (isset($answer['error'])) {
      $error = $answer['error'];
      throw new ApiException('HTTP ' . $error['status'], $error['status'], TRUE, NULL, $error['code'] ?? NULL, $error['message'] ?? NULL);
    }
    return $answer;
  }

  /**
   * {@inheritdoc}
   *
   * Answers from state 'wahosearch_connector_test.suggestions' and adds each
   * request to 'wahosearch_connector_test.suggest_requests'.
   */
  public function suggest(array $request): array {
    $requests = $this->state->get('wahosearch_connector_test.suggest_requests', []);
    $requests[] = $request;
    $this->state->set('wahosearch_connector_test.suggest_requests', $requests);
    return ['suggestions' => $this->state->get('wahosearch_connector_test.suggestions', [])];
  }

}
