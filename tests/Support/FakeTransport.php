<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests\Support;

use NaijaCloud\Email\Http\HttpResponse;
use NaijaCloud\Email\Http\Transport;
use RuntimeException;
use Throwable;

/**
 * A scripted transport, for the cases a socket cannot produce on demand: a DNS
 * failure, a client-side timeout at an exact attempt, or a validation test that
 * should never reach the wire at all.
 *
 * `sends` is the record of what the client tried to do, which is how the
 * "never left the process" assertions are made.
 */
final class FakeTransport implements Transport
{
    /** @var list<HttpResponse|Throwable> */
    private array $script;

    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string,timeout:float}> */
    public array $sends = [];

    /**
     * @param list<HttpResponse|Throwable> $script Consumed one per attempt; the last
     *                                             entry repeats once exhausted.
     */
    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    public static function json(int $status, array $body, array $headers = []): HttpResponse
    {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = is_array($value) ? array_values($value) : [$value];
        }

        return new HttpResponse($status, $normalised, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse {
        $this->sends[] = compact('method', 'url', 'headers', 'body', 'timeout');

        if ($this->script === []) {
            throw new RuntimeException('FakeTransport was called with nothing scripted');
        }

        $next = count($this->script) > 1 ? array_shift($this->script) : $this->script[0];

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastHeader(string $name): ?string
    {
        $last = $this->sends[array_key_last($this->sends)] ?? null;

        return $last === null ? null : ($last['headers'][$name] ?? null);
    }
}
