<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

/**
 * The request conflicts with the current state (409).
 *
 * In practice: an idempotency key replayed with a different body. Not
 * retryable, because the same request would conflict again.
 */
final class ConflictException extends NaijamailException
{
}
