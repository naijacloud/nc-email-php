<?php

declare(strict_types=1);

namespace NaijaCloud\Email;

use Closure;
use LogicException;
use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Http\CurlTransport;
use NaijaCloud\Email\Http\Transport;
use NaijaCloud\Email\Internal\ApiClient;
use NaijaCloud\Email\Internal\Guard;
use NaijaCloud\Email\Internal\Redaction;
use SensitiveParameter;

/**
 * The Naijamail client.
 *
 * ```php
 * $nm = new Naijamail();                       // reads NAIJAMAIL_API_KEY
 * $sent = $nm->emails->send([
 *     'from' => 'Acme <hello@acme.com>',
 *     'to' => 'customer@example.com',
 *     'subject' => 'Your receipt',
 *     'html' => '<p>Thanks.</p>',
 * ]);
 * ```
 *
 * A client instance carries its own key, base URL and transport, and touches no
 * global state: two clients with two different keys in one process — a platform
 * key and a customer's — cannot interfere with each other.
 */
final class Naijamail
{
    public const VERSION = '0.1.0';

    public const DEFAULT_BASE_URL = 'https://api.naijacloud.com';

    /** Per attempt, not per call. Three attempts of 30s is the documented worst case. */
    public const DEFAULT_TIMEOUT = 30.0;

    /** Retries after the first attempt, so 2 means three attempts in total. */
    public const DEFAULT_MAX_RETRIES = 2;

    /**
     * The key must look like one before we spend an hour finding out it is not.
     *
     * Two families, because the API accepts two: `nmail_live_`/`nmail_test_` is
     * a Naijamail-only key from the dashboard's Email screen, and `nc_live_` is
     * a workspace API key carrying the Email send scope, from Settings -> API
     * keys. It stays an allowlist rather than relaxing to "any non-empty
     * string": the check exists to catch the truncated paste and the
     * wrong-variable-name deploy, and a pattern that accepts anything catches
     * neither.
     */
    private const KEY_PATTERN = '/^(?:nmail_(?:live|test)|nc_live)_[A-Za-z0-9_-]{8,}$/';

    /**
     * `transport` and `sleeper` are test seams, not configuration. They are
     * accepted here because the alternative is an untestable retry loop or a
     * suite that opens real sockets, and both are worse.
     *
     * @var list<string>
     */
    private const ALLOWED_OPTIONS = [
        'base_url', 'timeout', 'max_retries', 'user_agent_suffix', 'transport', 'sleeper',
    ];

    /** Send and retrieve messages. */
    public readonly Emails $emails;

    /**
     * The key is held inside a closure rather than in a property, and this is
     * not decoration. `__debugInfo()` below covers `var_dump()` and `print_r()`,
     * but `var_export()` ignores it entirely and prints the raw property table
     * — so a key stored as a plain property lands in any code that exports an
     * object graph for a debug page or a bug report. A closure's bound
     * variables are not part of that table: `var_export()` prints
     * `\Closure::__set_state(array())` and the key stays out of the dump.
     *
     * @var Closure(): string
     */
    private readonly Closure $apiKey;

    private readonly ApiClient $api;

    private readonly string $baseUrl;

    private readonly string $userAgent;

    private readonly float $timeout;

    private readonly int $maxRetries;

    /**
     * @param string|null         $apiKey  Defaults to the `NAIJAMAIL_API_KEY` environment variable.
     * @param array<string,mixed> $options `base_url`, `timeout`, `max_retries`, `user_agent_suffix`.
     *
     * @throws ValidationException The key is missing or malformed, an option is
     *         unknown or out of range, or the base URL is not HTTPS.
     */
    public function __construct(
        #[SensitiveParameter]
        ?string $apiKey = null,
        array $options = [],
    ) {
        Guard::onlyKnownKeys($options, self::ALLOWED_OPTIONS, 'client option');

        $key = self::resolveKey($apiKey);
        $this->apiKey = static fn (): string => $key;

        $this->baseUrl = self::resolveBaseUrl($options['base_url'] ?? null);
        $this->timeout = self::resolveTimeout($options['timeout'] ?? null);
        $this->maxRetries = self::resolveMaxRetries($options['max_retries'] ?? null);
        $this->userAgent = self::buildUserAgent($options['user_agent_suffix'] ?? null);

        $transport = $options['transport'] ?? new CurlTransport(self::isLoopback($this->baseUrl));
        if (!$transport instanceof Transport) {
            throw new ValidationException('transport must implement ' . Transport::class);
        }

        $sleeper = $options['sleeper'] ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        if (!$sleeper instanceof Closure) {
            throw new ValidationException('sleeper must be a Closure taking a float');
        }

        $this->api = new ApiClient(
            $this->apiKey,
            $this->baseUrl,
            $this->userAgent,
            $this->timeout,
            $this->maxRetries,
            $transport,
            $sleeper,
        );

        $this->emails = new Emails($this->api);
    }

    /** The key as it is safe to print: `nmail_live_***`. */
    public function redactedApiKey(): string
    {
        return Redaction::key(($this->apiKey)());
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Covers `var_dump()` and `print_r()`. `var_export()` does not consult this
     * — see the note on {@see self::$apiKey} for why that is handled by where
     * the key is stored rather than here.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => $this->redactedApiKey(),
            'baseUrl' => $this->baseUrl,
            'userAgent' => $this->userAgent,
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
        ];
    }

    /**
     * A serialized client is a live sending credential written into a session
     * file, a cache entry or a queue payload — storage that outlives the
     * process, gets backed up, and is read by things that have no business
     * holding a key. Build a new client from configuration instead.
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
     * @throws ValidationException
     */
    private static function resolveKey(#[SensitiveParameter] ?string $apiKey): string
    {
        $key = $apiKey ?? self::env('NAIJAMAIL_API_KEY');

        if ($key === null || trim($key) === '') {
            throw new ValidationException(
                'no API key. Pass one to the constructor or set the NAIJAMAIL_API_KEY'
                . ' environment variable.',
            );
        }

        $key = trim($key);

        // An obviously-wrong key is a local error now, rather than a 401 at
        // three in the morning from a deploy nobody has looked at since.
        // The message must never quote the value: an exception message ends up
        // in a log, and a truncated key is still most of a key.
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ValidationException(
                'the API key is not shaped like a Naijamail key. It should look like'
                . ' "nmail_live_...", "nmail_test_..." or "nc_live_...". Check for a copied'
                . ' newline or a truncated value; the key itself is not shown here on purpose.',
            );
        }

        return $key;
    }

    /**
     * @throws ValidationException
     */
    private static function resolveBaseUrl(mixed $option): string
    {
        $raw = $option ?? self::env('NAIJAMAIL_BASE_URL') ?? self::DEFAULT_BASE_URL;

        if (!is_string($raw) || trim($raw) === '') {
            throw new ValidationException('base_url must be a non-empty string');
        }

        $url = rtrim(trim($raw), '/');
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ValidationException(sprintf('base_url "%s" is not a valid URL', $url));
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new ValidationException('base_url must not carry a query string or fragment');
        }

        // A base URL of the form https://user:pass@host puts credentials in
        // every request line and in every log that records the URL.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidationException('base_url must not contain a username or password');
        }

        $scheme = strtolower($parts['scheme']);

        // The whole point of this check: a plaintext base URL would put a live
        // sending credential on the wire in clear, in the Authorization header
        // of every request. Loopback is exempted so a developer can run against
        // a local control plane without disabling anything.
        if ($scheme !== 'https' && !self::isLoopbackHost($parts['host'])) {
            throw new ValidationException(sprintf(
                'base_url must be https (got "%s"). Only localhost, 127.0.0.1 and ::1 may use'
                . ' http, for local development.',
                $scheme,
            ));
        }

        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new ValidationException(sprintf('base_url scheme "%s" is not supported', $scheme));
        }

        return $url;
    }

    private static function resolveTimeout(mixed $option): float
    {
        if ($option === null) {
            return self::DEFAULT_TIMEOUT;
        }

        if (!is_int($option) && !is_float($option)) {
            throw new ValidationException('timeout must be a number of seconds');
        }

        $timeout = (float) $option;
        if ($timeout <= 0) {
            throw new ValidationException('timeout must be greater than zero');
        }

        return $timeout;
    }

    private static function resolveMaxRetries(mixed $option): int
    {
        if ($option === null) {
            return self::DEFAULT_MAX_RETRIES;
        }

        if (!is_int($option) || $option < 0) {
            throw new ValidationException('max_retries must be an integer of 0 or more');
        }

        // A budget beyond this is not resilience, it is a worker parked on a
        // dead endpoint while the queue behind it grows.
        if ($option > 10) {
            throw new ValidationException('max_retries above 10 is never the right answer');
        }

        return $option;
    }

    /**
     * `nc-email-php/0.1.0 (PHP/8.3.0)`, plus the caller's suffix.
     *
     * The suffix is checked for header breaks like any other header value: it
     * usually comes from an application's own config, and a newline in it would
     * let that config append headers to every request the SDK makes.
     */
    private static function buildUserAgent(mixed $suffix): string
    {
        $agent = sprintf('nc-email-php/%s (PHP/%s)', self::VERSION, PHP_VERSION);

        if ($suffix === null) {
            return $agent;
        }

        if (!is_string($suffix)) {
            throw new ValidationException('user_agent_suffix must be a string');
        }

        $suffix = trim($suffix);
        if ($suffix === '') {
            return $agent;
        }

        return $agent . ' ' . Guard::noHeaderBreaks($suffix, 'user_agent_suffix');
    }

    private static function isLoopback(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && self::isLoopbackHost($host);
    }

    private static function isLoopbackHost(string $host): bool
    {
        // parse_url leaves the brackets on an IPv6 literal.
        $host = strtolower(trim($host, '[]'));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * getenv() only: $_ENV and $_SERVER are populated according to
     * `variables_order`, which is not the same on every host, and a key that
     * silently is not read looks exactly like a key that is wrong.
     */
    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
