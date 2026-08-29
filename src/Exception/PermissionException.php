<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

/**
 * Authenticated, but not allowed (403).
 *
 * A test key on the live send path, an unverified From domain, a domain
 * paused for reputation, or the daily quota. Never retried — an unverified
 * domain will not verify itself between attempts.
 */
final class PermissionException extends NaijamailException
{
}
