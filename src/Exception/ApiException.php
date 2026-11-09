<?php

declare(strict_types=1);

namespace Drupal\wahosearch_connector\Exception;

/**
 * Thrown when the SaaS API cannot be reached or rejects a request.
 */
final class ApiException extends \RuntimeException {

  /**
   * Constructs an ApiException.
   *
   * @param string $message
   *   Human readable message (safe to log, never contains the secret).
   * @param int $statusCode
   *   HTTP status code, 0 when the server could not be reached.
   * @param bool $retryable
   *   TRUE when the same request may succeed later (network, 429, 5xx).
   * @param \Throwable|null $previous
   *   The original exception.
   */
  public function __construct(
    string $message,
    private readonly int $statusCode = 0,
    private readonly bool $retryable = FALSE,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $statusCode, $previous);
  }

  /**
   * Returns the HTTP status code, or 0 for network failures.
   */
  public function getStatusCode(): int {
    return $this->statusCode;
  }

  /**
   * Whether the request can be retried later.
   */
  public function isRetryable(): bool {
    return $this->retryable;
  }

}
