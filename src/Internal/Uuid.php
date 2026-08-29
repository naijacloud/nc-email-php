<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Internal;

/**
 * A UUIDv4, without a dependency on ramsey/uuid.
 *
 * The contract keeps this package's runtime dependencies at zero because it
 * holds a live sending credential, and a UUID is sixteen random bytes with six
 * of them fixed.
 *
 * @internal
 */
final class Uuid
{
    private function __construct()
    {
    }

    public static function v4(): string
    {
        // random_bytes, not mt_rand: two workers that started in the same
        // second must not be able to generate the same idempotency key, or one
        // customer's message silently dedupes away another's.
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
