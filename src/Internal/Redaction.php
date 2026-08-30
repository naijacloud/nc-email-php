<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Internal;

/**
 * @internal
 */
final class Redaction
{
    private function __construct()
    {
    }

    /**
     * `nmail_live_abc123…` becomes `nmail_live_***`.
     *
     * The prefix is kept because it is the one thing an operator actually needs
     * from a dump — "this box is holding a live key", and which of the two
     * credential families it came from — and it reveals nothing: it is the same
     * handful of characters for every key of that kind. No part of the secret half is shown, not even the last four
     * characters, because a key hint plus a leaked log is a shorter search than
     * a key hint alone.
     */
    public static function key(string $apiKey): string
    {
        if (preg_match('/^(nmail_(?:live|test)_|nc_live_)/', $apiKey, $matches) === 1) {
            return $matches[1] . '***';
        }

        return '***';
    }
}
