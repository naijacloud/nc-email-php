<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

use Throwable;

/**
 * Rate limited (429).
 *
 * Retryable, and the SDK already did so up to `max_retries` before this reached
 * the caller — so seeing this means the limit outlasted the retry budget.
 * `getRetryAfter()` is the server's own advice in seconds, already normalised
 * from either the integer or the HTTP-date form of `Retry-After` and clamped to
 * 60s so a mis-set header cannot park a worker for an hour.
 */
final class RateLimitException extends NaijamailException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        int $statusCode = 429,
        ?string $errorLabel = null,
        ?string $requestId = null,
        ?string $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $errorLabel, $requestId, $body, $previous);
    }

    /** Seconds the server asked us to wait, when it said. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
