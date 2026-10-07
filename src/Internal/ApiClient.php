<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Internal;

use Closure;
use LogicException;
use NaijaCloud\Email\Exception\ConnectionException;
use NaijaCloud\Email\Exception\NaijamailException;
use NaijaCloud\Email\Exception\RateLimitException;
use NaijaCloud\Email\Exception\ServerException;
use NaijaCloud\Email\Exception\TimeoutException;
use NaijaCloud\Email\Http\HttpResponse;
use NaijaCloud\Email\Http\Transport;

/**
 * One request, retried according to the contract's policy.
 *
 * Split out of {@see \NaijaCloud\Email\Naijamail} so that the credential lives
 * in exactly one object, and so that the client and its `emails` resource can
 * both hold this without holding each other — a reference cycle between them
 * would make `var_export($client)` emit a circular-reference warning, which is
 * a poor way to find out how your objects are wired.
 *
 * @internal
 */
final class ApiClient
{
    /**
     * @param Closure(): string      $apiKey  The key, reachable only by calling this.
     * @param Closure(float): void   $sleeper Injected so the tests can assert the
     *                                        backoff without spending it.
     */
    public function __construct(
        private readonly Closure $apiKey,
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly Transport $transport,
        private readonly Closure $sleeper,
    ) {
    }

    /**
     * @param array<string,string> $extraHeaders
     * @param HttpResponse|null    $response     Set to the final 2xx response, for a caller
     *                                           that needs the raw body to report a
     *                                           malformed answer.
     *
     * @return array<string,mixed> The decoded response body.
     *
     * @throws NaijamailException
     */
    public function request(
        string $method,
        string $path,
        ?string $json = null,
        array $extraHeaders = [],
        ?HttpResponse &$response = null,
    ): array {
        $url = $this->baseUrl . $path;
        // Union, not array_merge: on a key collision the left side wins, so a
        // caller-supplied header can never displace Authorization or the
        // User-Agent no matter what a resource passes in.
        $headers = $this->headers($json !== null) + $extraHeaders;

        $attempt = 0;
        while (true) {
            try {
                $response = $this->transport->send($method, $url, $headers, $json, $this->timeout);
            } catch (ConnectionException | TimeoutException $e) {
                // The request may or may not have been processed. Retrying is
                // safe anyway, because every send carries an idempotency key.
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }
                $this->pause(Backoff::delay($attempt));
                $attempt++;
                continue;
            }

            if ($response->status >= 200 && $response->status < 300) {
                return $this->decode($response);
            }

            $error = ErrorFactory::fromResponse($response);

            if (!self::isRetryable($response->status) || $attempt >= $this->maxRetries) {
                throw $error;
            }

            $retryAfter = $error instanceof RateLimitException
                ? $error->retryAfter
                // 503 carries Retry-After during a planned drain as often as
                // 429 does under a burst limit; honour it wherever it appears.
                : Backoff::parseRetryAfter($response->header('retry-after'));

            $this->pause(Backoff::delay($attempt, $retryAfter));
            $attempt++;
        }
    }

    /**
     * Never expose the key, and never expose the built headers either — the
     * Authorization header is assembled per request and is not held anywhere.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => Redaction::key(($this->apiKey)()),
            'baseUrl' => $this->baseUrl,
            'userAgent' => $this->userAgent,
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
            'transport' => $this->transport,
        ];
    }

    /**
     * Serializing this would write a live sending credential into a session
     * file, a cache entry or a queue payload — places that outlive the process
     * and are backed up. Fail loudly instead; rebuild the client from
     * configuration on the other side.
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'a Naijamail client cannot be serialized: it holds an API key, and serializing it'
            . ' would write that key wherever the serialized value goes. Construct a new client'
            . ' from configuration instead.',
        );
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        $this->__serialize();
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('a Naijamail client cannot be unserialized.');
    }

    public function __wakeup(): void
    {
        throw new LogicException('a Naijamail client cannot be unserialized.');
    }

    /**
     * Retryable exactly where the contract says: a throttle, a server-side
     * deadline, and anything 5xx. Never another 4xx — a 403 on an unverified
     * domain will not verify itself between attempts, and retrying it only
     * burns the caller's rate limit on a request that cannot succeed.
     */
    private static function isRetryable(int $status): bool
    {
        return $status === 429 || $status === 408 || ($status >= 500 && $status < 600);
    }

    /**
     * @return array<string,string>
     */
    private function headers(bool $hasBody): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . ($this->apiKey)(),
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(HttpResponse $response): array
    {
        $decoded = Json::decodeObject($response->body);

        if ($decoded === null) {
            // A 2xx that is not JSON is a proxy or a load balancer answering in
            // the API's place. Treat it as a server fault rather than handing
            // the caller an empty object that looks like a successful send.
            throw new ServerException(
                'malformed response: the API returned a ' . $response->status
                . ' with a body that is not a JSON object.'
                . ' Something between this process and the API is answering for it.',
                $response->status,
                null,
                $response->header('x-request-id'),
                $response->body,
            );
        }

        return $decoded;
    }

    /**
     * Always called, even for a zero delay — a `Retry-After: 0` is a real
     * answer, and skipping the call would make the number of waits differ from
     * the number of retries for no benefit worth the confusion.
     */
    private function pause(float $seconds): void
    {
        ($this->sleeper)(max(0.0, $seconds));
    }
}
