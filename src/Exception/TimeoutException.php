<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

/**
 * A deadline expired: our own per-attempt one, or the server's 408.
 *
 * Retryable, and safe to retry only because every send carries an
 * idempotency key. Without that key this is precisely the case that
 * double-mails a customer: the caller cannot tell "never arrived" from
 * "arrived, response lost".
 */
final class TimeoutException extends NaijamailException
{
}
