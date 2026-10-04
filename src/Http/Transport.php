<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Http;

/**
 * The seam between the SDK and the wire.
 *
 * It exists for two reasons: the test suite drives the whole client against a
 * scripted transport with no sockets involved, and an application in a locked
 * down environment (a proxy that must be configured centrally, an event loop
 * with its own client) can substitute one without forking the SDK.
 *
 * An implementation MUST NOT follow redirects and MUST verify TLS. Both rules
 * exist because every request carries `Authorization: Bearer <live key>`.
 *
 * @internal The interface is stable enough to implement, but it is not the
 *           package's advertised API and may change in a minor release.
 */
interface Transport
{
    /**
     * Perform one HTTP request. No retries: retry policy lives in the client,
     * where the idempotency key that makes retrying safe is also decided.
     *
     * @param array<string,string> $headers Fully-formed request headers.
     * @param string|null          $body    Raw request body, already encoded.
     * @param float                $timeout Per-attempt deadline in seconds.
     *
     * @throws \NaijaCloud\Email\Exception\TimeoutException    The deadline expired.
     * @throws \NaijaCloud\Email\Exception\ConnectionException DNS, TCP or TLS failed.
     */
    public function send(
        string $method,
        string $url,
        // Carries `Authorization`. Without this attribute every exception
        // thrown from inside send() records the full key in its trace args,
        // which is what Sentry and Monolog serialize (PHP 8.2+; ignored on 8.1).
        #[\SensitiveParameter]
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse;
}
