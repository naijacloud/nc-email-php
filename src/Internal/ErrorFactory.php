<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Internal;

use NaijaCloud\Email\Exception\AuthenticationException;
use NaijaCloud\Email\Exception\ConflictException;
use NaijaCloud\Email\Exception\NaijamailException;
use NaijaCloud\Email\Exception\NotFoundException;
use NaijaCloud\Email\Exception\PermissionException;
use NaijaCloud\Email\Exception\RateLimitException;
use NaijaCloud\Email\Exception\ServerException;
use NaijaCloud\Email\Exception\TimeoutException;
use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Http\HttpResponse;

/**
 * Turns a non-2xx response into the one exception type the contract names for
 * that status, so the same failure raises the same class in every language SDK.
 *
 * @internal
 */
final class ErrorFactory
{
    /**
     * The retrieve endpoint answers 400 with this message for an id that does
     * not exist, instead of 404. Matched exactly, and only on a 400, so that
     * the day the server is fixed to send 404 this mapping simply stops firing
     * rather than needing to be removed in lockstep.
     */
    private const NOT_FOUND_ON_400 = 'message not found';

    /** Server text is not trusted into a log line unbounded. */
    private const MAX_MESSAGE_CHARS = 2000;

    private function __construct()
    {
    }

    public static function fromResponse(HttpResponse $response): NaijamailException
    {
        $status = $response->status;
        $decoded = Json::decodeObject($response->body);
        $message = self::message($decoded, $status);
        $label = self::label($decoded);
        $requestId = $response->header('x-request-id');
        $body = $response->body;

        // Handled before the status table because the table has no row for 3xx:
        // we never follow a redirect, so one is a server misconfiguration to be
        // reported, not a location to chase with the key attached.
        if ($status >= 300 && $status < 400) {
            $location = $response->header('location');

            return new ServerException(
                'unexpected redirect'
                . ($location !== null ? ' to ' . self::clean($location) : '')
                . ' (HTTP ' . $status . '). The SDK never follows redirects, because a followed'
                . ' redirect would re-send your API key to another host.',
                $status,
                $label,
                $requestId,
                $body,
            );
        }

        return match (true) {
            $status === 400 && self::isNotFound($message)
                => new NotFoundException($message, $status, $label, $requestId, $body),
            $status === 400, $status === 422 => new ValidationException($message, $status, $label, $requestId, $body),
            $status === 401 => new AuthenticationException($message, $status, $label, $requestId, $body),
            $status === 403 => new PermissionException($message, $status, $label, $requestId, $body),
            $status === 404 => new NotFoundException($message, $status, $label, $requestId, $body),
            $status === 408 => new TimeoutException($message, $status, $label, $requestId, $body),
            $status === 409 => new ConflictException($message, $status, $label, $requestId, $body),
            $status === 429 => new RateLimitException(
                $message,
                Backoff::parseRetryAfter($response->header('retry-after')),
                $status,
                $label,
                $requestId,
                $body,
            ),
            $status >= 500 => new ServerException($message, $status, $label, $requestId, $body),

            // Any other 4xx — 405, 413, 415. The request was refused for
            // something about the request, which is what ValidationException
            // means to a caller, and it keeps "one catch for bad input" true.
            $status >= 400 => new ValidationException($message, $status, $label, $requestId, $body),

            default => new ServerException($message, $status, $label, $requestId, $body),
        };
    }

    /**
     * NestJS sends `message` as a string or as an array of strings (one per
     * failed field). Both shapes reach callers as one readable line.
     *
     * @param array<string,mixed>|null $decoded
     */
    private static function message(?array $decoded, int $status): string
    {
        $raw = $decoded['message'] ?? null;

        if (is_string($raw) && trim($raw) !== '') {
            return self::clean($raw);
        }

        if (is_array($raw)) {
            $parts = array_filter(
                array_map(
                    static fn ($part) => is_scalar($part) ? self::clean((string) $part) : null,
                    $raw,
                ),
                static fn (?string $part) => $part !== null && $part !== '',
            );

            if ($parts !== []) {
                return implode('; ', $parts);
            }
        }

        // Proxy HTML, an empty body, a gateway that answered before the API
        // did. Fall back to the status line rather than crashing the parser.
        return sprintf('HTTP %d from the Naijamail API', $status);
    }

    /**
     * @param array<string,mixed>|null $decoded
     */
    private static function label(?array $decoded): ?string
    {
        $label = $decoded['error'] ?? null;

        return is_string($label) && $label !== '' ? self::clean($label) : null;
    }

    private static function isNotFound(string $message): bool
    {
        return strcasecmp(trim($message), self::NOT_FOUND_ON_400) === 0;
    }

    /**
     * Server-supplied text ends up in an exception message and therefore in
     * someone's log file. Strip control characters so a hostile or broken
     * upstream cannot forge log lines with an embedded newline, and bound the
     * length so an HTML error page does not become the message.
     */
    private static function clean(string $value): string
    {
        $flattened = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        if (!is_string($flattened)) {
            // preg_replace returns null on invalid UTF-8; there is nothing
            // safe to show from a body like that.
            return '(unreadable error message)';
        }

        $trimmed = trim($flattened);

        return mb_strlen($trimmed) > self::MAX_MESSAGE_CHARS
            ? mb_substr($trimmed, 0, self::MAX_MESSAGE_CHARS) . '...'
            : $trimmed;
    }
}
