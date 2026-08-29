<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Internal;

use JsonException;
use NaijaCloud\Email\Exception\ValidationException;

/**
 * JSON with the failure modes turned into exceptions we can explain.
 *
 * @internal
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $value
     */
    public static function encode(array $value): string
    {
        try {
            // Unescaped slashes and unicode keep an HTML body readable on the
            // wire and shave a few percent off a large message; neither changes
            // what the server decodes.
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            // Nearly always invalid UTF-8 reaching us from a database column or
            // a file read as latin-1. Say so, because "Malformed UTF-8
            // characters" alone sends people looking at the wrong layer.
            throw new ValidationException(
                'the request body could not be encoded as JSON (' . $e->getMessage() . '). '
                . 'Every string field must be valid UTF-8; binary belongs in an attachment.',
                0,
                null,
                null,
                null,
                $e,
            );
        }
    }

    /**
     * Decode, returning null rather than throwing when the body is not a JSON
     * object. Error bodies arrive as proxy HTML or as nothing at all often
     * enough that the parser must treat it as ordinary.
     *
     * @return array<string,mixed>|null
     */
    public static function decodeObject(string $raw): ?array
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
