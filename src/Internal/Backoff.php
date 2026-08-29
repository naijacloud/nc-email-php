<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Internal;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * How long to wait before the next attempt.
 *
 * @internal
 */
final class Backoff
{
    public const BASE_SECONDS = 0.5;
    public const CAP_SECONDS = 8.0;

    /**
     * A server that says "come back in an hour" must not be allowed to park a
     * queue worker for an hour. 60s is long enough to clear a real burst limit
     * and short enough that a mis-set header is a delay, not an outage.
     */
    public const RETRY_AFTER_MAX_SECONDS = 60;

    private function __construct()
    {
    }

    /**
     * Full jitter: sleep = random(0, min(cap, base * 2^attempt)).
     *
     * Random across the *whole* window, not a jittered fraction of it. Equal
     * backoff plus a small wobble still leaves every client that was throttled
     * in the same second retrying in the same second; spreading uniformly is
     * what actually breaks the lockstep that keeps a recovering API down.
     *
     * @param int      $attempt    0 for the wait after the first failure.
     * @param int|null $retryAfter The server's own advice, already clamped, which wins.
     */
    public static function delay(int $attempt, ?int $retryAfter = null): float
    {
        if ($retryAfter !== null) {
            return (float) max(0, $retryAfter);
        }

        $ceiling = min(self::CAP_SECONDS, self::BASE_SECONDS * (2 ** max(0, $attempt)));

        // random_int rather than mt_rand: the CSPRNG costs nothing at this rate
        // and removes any question about a predictable retry schedule.
        return random_int(0, (int) round($ceiling * 1000)) / 1000;
    }

    /**
     * Parse `Retry-After`, which RFC 9110 allows in two forms: a delay in
     * seconds, or an HTTP date. Both appear in the wild — a CDN in front of the
     * API may rewrite one into the other — so both are handled, and anything
     * else is treated as absent rather than as an error.
     *
     * @param int|null $now Injected by the tests; the date form is otherwise untestable.
     */
    public static function parseRetryAfter(?string $header, ?int $now = null): ?int
    {
        if ($header === null) {
            return null;
        }

        $value = trim($header);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return min((int) $value, self::RETRY_AFTER_MAX_SECONDS);
        }

        $timestamp = self::parseHttpDate($value);
        if ($timestamp === null) {
            return null;
        }

        $seconds = $timestamp - ($now ?? time());

        // A date already in the past means "retry now", not "retry never".
        return max(0, min($seconds, self::RETRY_AFTER_MAX_SECONDS));
    }

    private static function parseHttpDate(string $value): ?int
    {
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $value);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed->getTimestamp();
        }

        // The two obsolete formats RFC 9110 still requires recipients to
        // accept (RFC 850 and asctime) are left to strtotime rather than
        // spelled out here.
        try {
            $timestamp = strtotime($value);
        } catch (Throwable) {
            return null;
        }

        return $timestamp === false ? null : $timestamp;
    }
}
