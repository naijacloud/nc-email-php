<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

/**
 * The key is missing, malformed, unknown or revoked (401).
 *
 * The server answers all four cases identically so a probe cannot learn
 * which one it hit. Never retried: a key does not become valid by asking
 * again.
 */
final class AuthenticationException extends NaijamailException
{
}
