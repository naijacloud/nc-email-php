<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

use NaijaCloud\Email\Internal\Json;
use RuntimeException;
use Throwable;

/**
 * Base of every error this SDK raises.
 *
 * One base class so a caller can write a single `catch (NaijamailException $e)`
 * around a send and be certain nothing else escapes — including the errors we
 * raise locally, before a request leaves the process, which carry
 * `statusCode === 0`.
 *
 * Extends RuntimeException rather than Exception because every failure here is
 * a runtime condition (the network, the server, the caller's data), not a
 * broken invariant in this library.
 */
class NaijamailException extends RuntimeException
{
    /**
     * @param int         $statusCode HTTP status, or 0 when the error was raised before the request went out.
     * @param string|null $errorLabel The server's short label ("Forbidden"), from the NestJS error body.
     * @param string|null $requestId  The `x-request-id` response header. Quote it in a support ticket.
     * @param string|null $body       The raw response body, kept verbatim for debugging a server we cannot see.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $errorLabel = null,
        public readonly ?string $requestId = null,
        public readonly ?string $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorLabel(): ?string
    {
        return $this->errorLabel;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** The raw response body, exactly as received. Same as {@see self::getRawBody()}. */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /** The raw response body, exactly as received; null for a local error. */
    public function getRawBody(): ?string
    {
        return $this->body;
    }

    /**
     * The response body parsed as a JSON object, or null when there was no body
     * or it was not a JSON object (proxy HTML, an empty 502).
     *
     * @return array<string,mixed>|null
     */
    public function getParsedBody(): ?array
    {
        return $this->body === null ? null : Json::decodeObject($this->body);
    }
}
