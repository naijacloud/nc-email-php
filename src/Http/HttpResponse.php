<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Http;

/**
 * One HTTP response, reduced to what this SDK needs.
 *
 * Deliberately not a PSR-7 message: PSR-7 would be a runtime dependency on a
 * package that holds a live sending credential, and the contract (section 5.9)
 * keeps that surface at zero.
 *
 * @internal No backwards-compatibility promise. Implement {@see Transport} if
 *           you need to substitute the HTTP layer.
 */
final class HttpResponse
{
    /**
     * @param array<string,list<string>> $headers Header names lowercased; a
     *        repeated header keeps every value, because collapsing them is how
     *        a Set-Cookie or a repeated Retry-After quietly loses information.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /** The last value of a header, case-insensitively, or null. */
    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : $values[array_key_last($values)];
    }
}
