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
   * @param string|null $errorCode
   *   The platform's error code, such as rate_limited, when it sent one.
   * @param string|null $platformMessage
   *   The platform's message, already in the language of the request.
   * @param int|null $retryAfter
   *   Seconds to wait before trying again, from the Retry-After header.
   */
  public function __construct(
    string $message,
    private readonly int $statusCode = 0,
    private readonly bool $retryable = FALSE,
    ?\Throwable $previous = NULL,
    private readonly ?string $errorCode = NULL,
    private readonly ?string $platformMessage = NULL,
    private readonly ?int $retryAfter = NULL,
  ) {
    parent::__construct($message, $statusCode, $previous);
  }

  /**
   * Returns the platform's error code, if it sent one.
   */
  public function getErrorCode(): ?string {
    return $this->errorCode;
  }

  /**
   * Returns the platform's message for visitors, if it sent one.
   */
  public function getPlatformMessage(): ?string {
    return $this->platformMessage;
  }

  /**
   * Returns the seconds to wait before trying again, if known.
   */
  public function getRetryAfter(): ?int {
    return $this->retryAfter;
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
