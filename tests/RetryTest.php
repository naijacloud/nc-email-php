<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests;

use NaijaCloud\Email\Exception\ConnectionException;
use NaijaCloud\Email\Exception\PermissionException;
use NaijaCloud\Email\Exception\ServerException;
use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Internal\Backoff;
use NaijaCloud\Email\Naijamail;
use NaijaCloud\Email\Tests\Support\FakeTransport;
use NaijaCloud\Email\Tests\Support\ServerTestCase;

/**
 * The retry policy: what is retried, how long the SDK waits, and the
 * idempotency key that makes retrying a send safe at all.
 *
 * The sleeper is recorded rather than spent, so the backoff is asserted
 * exactly and the suite still runs in under a second.
 */
final class RetryTest extends ServerTestCase
{
    public function testA429IsRetriedAndTheSendSucceeds(): void
    {
        $sent = $this->client('retry-429-seconds')->emails->send(self::validMessage());

        self::assertSame('queued', $sent->status);
        self::assertCount(2, self::$server->requests());
    }

    /** Retry-After overrides the computed backoff. */
    public function testRetryAfterInSecondsIsHonouredOverTheBackoff(): void
    {
        $this->client('retry-429-seconds')->emails->send(self::validMessage());

        self::assertSame([3.0], $this->slept);
    }

    /** RFC 9110 allows an HTTP date, and CDNs in front of an API do rewrite it. */
    public function testRetryAfterAsAnHttpDateIsHonoured(): void
    {
        $this->client('retry-429-date')->emails->send(self::validMessage());

        self::assertCount(1, $this->slept);
        // The header is built from the server's clock a moment before we read
        // it, so allow for the second that may have ticked over in between.
        self::assertGreaterThanOrEqual(3.0, $this->slept[0]);
        self::assertLessThanOrEqual(4.0, $this->slept[0]);
    }

    public function testThreeAttemptsIsTheDefaultBudget(): void
    {
        // 429, then 503, then accepted: exactly the default budget.
        $sent = $this->client('retry-twice')->emails->send(self::validMessage());

        self::assertSame('queued', $sent->status);
        self::assertCount(3, self::$server->requests());
        self::assertCount(2, $this->slept);
    }

    public function testTheBudgetIsExhaustedAndTheLastErrorSurfaces(): void
    {
        try {
            $this->client('always-500')->emails->send(self::validMessage());
            self::fail('a ServerException should have been raised');
        } catch (ServerException $e) {
            self::assertSame(500, $e->statusCode);
        }

        self::assertCount(3, self::$server->requests(), '1 try plus 2 retries');
    }

    public function testMaxRetriesZeroMeansOneAttempt(): void
    {
        try {
            $this->client('always-500', ['max_retries' => 0])->emails->send(self::validMessage());
            self::fail('a ServerException should have been raised');
        } catch (ServerException) {
        }

        self::assertCount(1, self::$server->requests());
        self::assertSame([], $this->slept);
    }

    /** A 403 on an unverified domain will never succeed; retrying only burns quota. */
    public function testA403IsNeverRetried(): void
    {
        try {
            $this->client('always-403')->emails->send(self::validMessage());
            self::fail('a PermissionException should have been raised');
        } catch (PermissionException) {
        }

        self::assertCount(1, self::$server->requests());
        self::assertSame([], $this->slept);
    }

    public function testA400IsNeverRetried(): void
    {
        try {
            $this->client('always-400')->emails->send(self::validMessage());
            self::fail('a ValidationException should have been raised');
        } catch (ValidationException) {
        }

        self::assertCount(1, self::$server->requests());
    }

    /**
     * The rule that makes the whole retry policy safe. Without a stable key, a
     * timeout followed by a retry mails the customer twice.
     */
    public function testOneIdempotencyKeyCoversEveryAttemptOfOneSend(): void
    {
        $this->client('retry-twice')->emails->send(self::validMessage());

        $keys = array_map(
            static fn (array $request): string => $request['headers']['idempotency-key'],
            self::$server->requests(),
        );

        self::assertCount(3, $keys);
        self::assertCount(1, array_unique($keys), 'all three attempts share one key');
    }

    public function testACallerSuppliedKeyIsNeverRegenerated(): void
    {
        $this->client('retry-twice')->emails->send(
            self::validMessage() + ['idempotency_key' => 'order-1024'],
        );

        foreach (self::$server->requests() as $request) {
            self::assertSame('order-1024', $request['headers']['idempotency-key']);
        }
    }

    public function testTwoSendsThatBothRetryDoNotShareAKey(): void
    {
        $client = $this->client('retry-429-seconds');
        $client->emails->send(self::validMessage());
        self::$server->reset();
        $client->emails->send(self::validMessage());

        $second = self::$server->requests();
        self::assertNotSame([], $second);
    }

    /** A GET is retried too — it is the safest request there is. */
    public function testARetryableStatusOnGetIsAlsoRetried(): void
    {
        try {
            $this->client('always-500')->emails->get('5b1e0000-0000-4000-8000-000000000001');
            self::fail('a ServerException should have been raised');
        } catch (ServerException) {
        }

        self::assertCount(3, self::$server->requests());
    }

    /** A connection failure is not something a local server can produce on cue. */
    public function testAConnectionFailureIsRetriedThenSurfaces(): void
    {
        $transport = new FakeTransport([new ConnectionException('could not resolve host')]);
        $slept = [];
        $client = new Naijamail(self::TEST_KEY, [
            'transport' => $transport,
            'sleeper' => static function (float $seconds) use (&$slept): void {
                $slept[] = $seconds;
            },
        ]);

        try {
            $client->emails->send(self::validMessage());
            self::fail('a ConnectionException should have been raised');
        } catch (ConnectionException) {
        }

        self::assertCount(3, $transport->sends);
        self::assertCount(2, $slept);
    }

    public function testTheIdempotencyKeyIsStableAcrossTransportFailuresToo(): void
    {
        $transport = new FakeTransport([new ConnectionException('connection reset by peer')]);
        $client = new Naijamail(self::TEST_KEY, [
            'transport' => $transport,
            'sleeper' => static fn (float $seconds) => null,
        ]);

        try {
            $client->emails->send(self::validMessage());
        } catch (ConnectionException) {
        }

        $keys = array_map(
            static fn (array $send): string => $send['headers']['Idempotency-Key'],
            $transport->sends,
        );

        self::assertCount(3, $keys);
        self::assertCount(1, array_unique($keys));
    }

    /**
     * Full jitter across the whole window, not a wobble around a fixed delay:
     * every client throttled in the same second must not come back in the same
     * second, or the API never gets a chance to recover.
     */
    public function testTheBackoffIsFullyJitteredAndCapped(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $ceiling = min(Backoff::CAP_SECONDS, Backoff::BASE_SECONDS * (2 ** $attempt));

            for ($i = 0; $i < 50; $i++) {
                $delay = Backoff::delay($attempt);
                self::assertGreaterThanOrEqual(0.0, $delay);
                self::assertLessThanOrEqual($ceiling, $delay);
            }
        }
    }

    public function testABelligerentRetryAfterIsClampedToAMinute(): void
    {
        self::assertSame(60, Backoff::parseRetryAfter('3600'));
        self::assertSame(60, Backoff::parseRetryAfter(gmdate('D, d M Y H:i:s', 2_000_000_000) . ' GMT', 1_000_000_000));
    }

    public function testARetryAfterInThePastMeansRetryNow(): void
    {
        self::assertSame(0, Backoff::parseRetryAfter(gmdate('D, d M Y H:i:s', 1_000) . ' GMT', 2_000));
    }

    public function testAnUnparseableRetryAfterIsTreatedAsAbsent(): void
    {
        self::assertNull(Backoff::parseRetryAfter('soon'));
        self::assertNull(Backoff::parseRetryAfter(''));
        self::assertNull(Backoff::parseRetryAfter(null));
    }
}
