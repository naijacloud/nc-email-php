<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Internal;

use NaijaCloud\Email\Exception\ValidationException;

/**
 * The checks that run before anything is put on a socket.
 *
 * @internal
 */
final class Guard
{
    private function __construct()
    {
    }

    /**
     * Refuse CR, LF and NUL in anything that becomes a MIME header.
     *
     * This is header injection: a newline in `subject` or in an address ends
     * the header and starts one the caller chose — a second `Bcc:`, a forged
     * `Reply-To:`, or a whole extra message body. The control plane's
     * MimeBuilder refuses the same characters, but a caller deserves to be told
     * which of their fields is at fault, in their own stack trace, rather than
     * decoding a 400 from a machine they cannot see.
     *
     * NUL is included because it truncates in C string handling, so a value the
     * SDK checks in full can be a shorter, different value further down.
     */
    public static function noHeaderBreaks(string $value, string $field): string
    {
        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new ValidationException(
                sprintf('%s must not contain a carriage return, newline or NUL byte', $field),
            );
        }

        return $value;
    }

    /**
     * Coerce the `string|string[]` shape the API accepts everywhere for
     * addresses, rejecting anything else with the field named.
     *
     * @return list<string>
     */
    public static function addressList(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? array_values($value) : [$value];
        $addresses = [];

        foreach ($items as $index => $item) {
            if (!is_string($item)) {
                throw new ValidationException(
                    sprintf('%s must be a string or an array of strings', $field),
                );
            }

            $label = is_array($value) ? sprintf('%s[%d]', $field, $index) : $field;
            $trimmed = trim($item);

            if ($trimmed === '') {
                throw new ValidationException(sprintf('%s must not be empty', $label));
            }

            $addresses[] = self::noHeaderBreaks($trimmed, $label);
        }

        return $addresses;
    }

    /** A required string field: present, a string, and not just whitespace. */
    public static function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException(sprintf('"%s" is required', $field));
        }

        return $value;
    }

    /** An optional string field, rejected outright if present as another type. */
    public static function optionalString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new ValidationException(sprintf('%s must be a string', $field));
        }

        return $value;
    }

    /**
     * Reject option and parameter names we do not know.
     *
     * A silently ignored `'timout' => 5` is a client that waits 30 seconds in
     * production and a developer who has no way to see why. Strictness here
     * costs a caller one clear exception; leniency costs them an outage.
     *
     * @param array<string,mixed> $given
     * @param list<string>        $allowed
     */
    public static function onlyKnownKeys(array $given, array $allowed, string $what): void
    {
        $unknown = array_diff(array_keys($given), $allowed);
        if ($unknown === []) {
            return;
        }

        sort($unknown);
        throw new ValidationException(sprintf(
            'unknown %s: %s. Known keys: %s',
            $what,
            implode(', ', $unknown),
            implode(', ', $allowed),
        ));
    }
}
