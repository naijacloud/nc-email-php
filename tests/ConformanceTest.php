<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests;

use NaijaCloud\Email\Exception\NaijamailException;
use NaijaCloud\Email\Exception\RateLimitException;
use NaijaCloud\Email\Exception\ServerException;
use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Exception\WebhookVerificationException;
use NaijaCloud\Email\Http\HttpResponse;
use NaijaCloud\Email\Naijamail;
use NaijaCloud\Email\Tests\Support\FakeTransport;
use NaijaCloud\Email\Webhooks;
use PHPUnit\Framework\TestCase;

/**
 * The behaviours the TGL-741 conformance pass settled across all five SDKs.
 * Each test names the contract rule it pins.
 */
final class ConformanceTest extends TestCase
{
    private const KEY = 'nmail_live_test0000000000000000';

    private const SECRET = 'nmail_whsec_test0000000000000000';

    /** @var list<float> */
    private array $sleeps = [];

    protected function tearDown(): void
    {
        putenv('NAIJAMAIL_BASE_URL');
    }

    /**
     * @param array<string,mixed> $options
     */
    private function client(FakeTransport $transport, array $options = []): Naijamail
    {
        return new Naijamail(self::KEY, $options + [
            'transport' => $transport,
            'sleeper' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function message(array $overrides = []): array
    {
        return array_merge([
            'from' => 'Acme <hello@acme.com>',
            'to' => 'customer@example.com',
            'subject' => 'Your receipt',
            'html' => '<p>Thanks.</p>',
        ], $overrides);
    }

    private static function accepted(): HttpResponse
    {
        return FakeTransport::json(202, ['id' => '5b1e', 'status' => 'queued']);
    }

    // §1 / §5.8 — keys and base URL

    public function testAPersonalAccessTokenGetsTheContractsSpecificMessage(): void
    {
        try {
            new Naijamail('nc_pat_test0000000000000000');
            self::fail('an nc_pat_ key must be refused');
        } catch (ValidationException $e) {
            self::assertSame(Naijamail::PAT_MESSAGE, $e->getMessage());
            self::assertStringContainsString('personal access token', $e->getMessage());
            self::assertStringNotContainsString('test0000000000000000', $e->getMessage());
        }
    }

    public function testABlankBaseUrlEnvironmentVariableMeansUnset(): void
    {
        putenv('NAIJAMAIL_BASE_URL=');
        self::assertSame(Naijamail::DEFAULT_BASE_URL, (new Naijamail(self::KEY))->getBaseUrl());

        putenv('NAIJAMAIL_BASE_URL=   ');
        self::assertSame(Naijamail::DEFAULT_BASE_URL, (new Naijamail(self::KEY))->getBaseUrl());
    }

    public function testABaseUrlWithAQueryStringFromTheEnvironmentIsRefused(): void
    {
        putenv('NAIJAMAIL_BASE_URL=https://api.naijacloud.com/?x=1');

        $this->expectException(ValidationException::class);
        new Naijamail(self::KEY);
    }

    public function testMaxRetriesIsCappedAtTen(): void
    {
        self::assertInstanceOf(Naijamail::class, new Naijamail(self::KEY, ['max_retries' => 10]));

        $this->expectException(ValidationException::class);
        new Naijamail(self::KEY, ['max_retries' => 11]);
    }

    // §3 — errors

    /**
     * @return array<string,array{int}>
     */
    public static function unlistedClientErrors(): array
    {
        return ['405' => [405], '415' => [415], '451' => [451]];
    }

    /**
     * @dataProvider unlistedClientErrors
     */
    public function testAnyUnlistedFourHundredIsAValidationErrorAndNotRetried(int $status): void
    {
        $transport = new FakeTransport([FakeTransport::json($status, ['message' => 'nope'])]);

        try {
            $this->client($transport)->emails->send(self::message());
            self::fail('should have raised');
        } catch (ValidationException $e) {
            self::assertSame($status, $e->statusCode);
        }
        self::assertCount(1, $transport->sends);
    }

    public function testAnErrorCarriesBothTheRawAndTheParsedBody(): void
    {
        $transport = new FakeTransport([
            FakeTransport::json(403, ['statusCode' => 403, 'message' => 'no', 'error' => 'Forbidden']),
        ]);

        try {
            $this->client($transport)->emails->send(self::message());
            self::fail('should have raised');
        } catch (NaijamailException $e) {
            self::assertSame('{"statusCode":403,"message":"no","error":"Forbidden"}', $e->getRawBody());
            self::assertSame($e->getRawBody(), $e->getBody());
            self::assertSame(['statusCode' => 403, 'message' => 'no', 'error' => 'Forbidden'], $e->getParsedBody());
        }
    }

    public function testANonJsonErrorBodyHasARawBodyAndNoParsedBody(): void
    {
        $transport = new FakeTransport([new HttpResponse(502, [], '<html>bad gateway</html>')]);

        try {
            $this->client($transport, ['max_retries' => 0])->emails->send(self::message());
            self::fail('should have raised');
        } catch (NaijamailException $e) {
            self::assertSame('<html>bad gateway</html>', $e->getRawBody());
            self::assertNull($e->getParsedBody());
        }
    }

    public function testASendResponseWithoutAnIdIsAServerErrorAndNotRetried(): void
    {
        $transport = new FakeTransport([FakeTransport::json(202, ['status' => 'queued'])]);

        try {
            $this->client($transport)->emails->send(self::message());
            self::fail('should have raised');
        } catch (ServerException $e) {
            self::assertStringContainsString('malformed response', $e->getMessage());
            self::assertSame('{"status":"queued"}', $e->getRawBody());
        }
        self::assertCount(1, $transport->sends);
    }

    public function testAJsonArraySuccessBodyIsAServerErrorAndNotRetried(): void
    {
        $transport = new FakeTransport([new HttpResponse(200, [], '[{"id":"5b1e"}]')]);

        $this->expectException(ServerException::class);
        try {
            $this->client($transport)->emails->send(self::message());
        } finally {
            self::assertCount(1, $transport->sends);
        }
    }

    public function testANonJsonSuccessIsNotRetried(): void
    {
        $transport = new FakeTransport([new HttpResponse(200, [], 'OK')]);

        try {
            $this->client($transport)->emails->send(self::message());
            self::fail('should have raised');
        } catch (ServerException) {
        }
        self::assertCount(1, $transport->sends);
    }

    // §4 — Retry-After

    public function testRetryAfterIsHonouredOnA503(): void
    {
        $transport = new FakeTransport([
            FakeTransport::json(503, ['message' => 'draining'], ['Retry-After' => '7']),
            self::accepted(),
        ]);

        $this->client($transport)->emails->send(self::message());

        self::assertSame([7.0], $this->sleeps);
    }

    public function testRateLimitRetryAfterIsClampedToSixtySeconds(): void
    {
        $transport = new FakeTransport([
            FakeTransport::json(429, ['message' => 'slow down'], ['Retry-After' => '3600']),
        ]);

        try {
            $this->client($transport, ['max_retries' => 0])->emails->send(self::message());
            self::fail('should have raised');
        } catch (RateLimitException $e) {
            self::assertSame(60, $e->retryAfter);
        }
    }

    public function testTheTimeoutIsHandedToTheTransportPerAttempt(): void
    {
        $transport = new FakeTransport([FakeTransport::json(500, []), self::accepted()]);

        $this->client($transport, ['timeout' => 2.5])->emails->send(self::message());

        self::assertCount(2, $transport->sends);
        foreach ($transport->sends as $send) {
            self::assertSame(2.5, $send['timeout']);
        }
    }

    // §5.4 — idempotency

    /**
     * @return array<string,array{string}>
     */
    public static function blankKeys(): array
    {
        return ['empty' => [''], 'whitespace' => ['   ']];
    }

    /**
     * @dataProvider blankKeys
     */
    public function testABlankIdempotencyKeyMeansGenerateOne(string $blank): void
    {
        $transport = new FakeTransport([self::accepted()]);

        $this->client($transport)->emails->send(self::message(['idempotency_key' => $blank]));

        $key = $transport->lastHeader('Idempotency-Key');
        self::assertIsString($key);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $key);
        self::assertStringNotContainsString('idempotency_key', (string) $transport->sends[0]['body']);
    }

    public function testTheIdempotencyKeyLimitIsCountedInUtf8Bytes(): void
    {
        $transport = new FakeTransport([self::accepted()]);
        $client = $this->client($transport);

        // 255 bytes: accepted.
        $client->emails->send(self::message(['idempotency_key' => str_repeat('a', 255)]));
        self::assertCount(1, $transport->sends);

        // 128 characters, 256 bytes: refused, though it is well under 255 characters.
        try {
            $client->emails->send(self::message(['idempotency_key' => str_repeat('é', 128)]));
            self::fail('a 256-byte key must be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('bytes', $e->getMessage());
        }
        self::assertCount(1, $transport->sends);
    }

    public function testTheIdempotencyKeyTravelsInTheHeaderOnly(): void
    {
        $transport = new FakeTransport([self::accepted()]);

        $this->client($transport)->emails->send(self::message(['idempotency_key' => 'order-1024']));

        self::assertSame('order-1024', $transport->lastHeader('Idempotency-Key'));
        self::assertStringNotContainsString('idempotency', (string) $transport->sends[0]['body']);
    }

    // §5.5 / §5.6 / §5.10 — headers and attachments

    /**
     * @return array<string,array{string}>
     */
    public static function paddedForbiddenNames(): array
    {
        return [
            'leading space' => [' From'],
            'trailing tab' => ["Bcc\t"],
            'both' => [' DKIM-Signature '],
        ];
    }

    /**
     * @dataProvider paddedForbiddenNames
     */
    public function testTheForbiddenHeaderCheckRunsOnTheTrimmedName(string $name): void
    {
        $transport = new FakeTransport([self::accepted()]);

        try {
            $this->client($transport)->emails->send(self::message(['headers' => [$name => 'x@evil.com']]));
            self::fail('a padded forbidden header must be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('cannot be overridden', $e->getMessage());
        }
        self::assertSame([], $transport->sends);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function attachmentHeaderFields(): array
    {
        return ['content_type' => ['content_type'], 'content_id' => ['content_id']];
    }

    /**
     * @dataProvider attachmentHeaderFields
     */
    public function testAttachmentContentTypeAndIdAreCheckedForLineBreaks(string $field): void
    {
        $transport = new FakeTransport([self::accepted()]);

        $this->expectException(ValidationException::class);
        $this->client($transport)->emails->send(self::message([
            'attachments' => [['filename' => 'a.txt', 'content' => 'hi', $field => "x\r\nBcc: y@z.com"]],
        ]));
    }

    public function testAnEmptyAttachmentIsRefusedLocally(): void
    {
        $transport = new FakeTransport([self::accepted()]);

        try {
            $this->client($transport)->emails->send(self::message([
                'attachments' => [['filename' => 'empty.txt', 'content' => '']],
            ]));
            self::fail('an empty attachment must be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('empty', $e->getMessage());
        }
        self::assertSame([], $transport->sends);
    }

    /** PHP's string is the byte type, so a string is raw content and is base64-encoded once. */
    public function testAStringAttachmentIsRawBytes(): void
    {
        $transport = new FakeTransport([self::accepted()]);

        $this->client($transport)->emails->send(self::message([
            'attachments' => [['filename' => 'a.txt', 'content' => 'aGk=']],
        ]));

        $body = json_decode((string) $transport->sends[0]['body'], true);
        self::assertSame(base64_encode('aGk='), $body['attachments'][0]['content']);
    }

    // §6 — webhooks

    private static function signed(int $t, string $payload): string
    {
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, self::SECRET);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function badTimestamps(): array
    {
        return [
            'thirteen digits' => ['1756468800000'],
            'plus sign' => ['+1756468800'],
            'underscore' => ['1_756_468_800'],
            'minus sign' => ['-1756468800'],
            'exponent' => ['1.7e9'],
            'int64 overflow' => ['99999999999999999999'],
        ];
    }

    /**
     * @dataProvider badTimestamps
     */
    public function testTheTimestampMustBeOneToTwelveDigits(string $t): void
    {
        $payload = '{"id":"evt_1"}';
        $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, self::SECRET);

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verify($payload, $header, self::SECRET, 300, 1756468800);
    }

    public function testAZeroToleranceIsStrictNotTheDefault(): void
    {
        $payload = '{"id":"evt_1"}';
        $now = 1756468800;

        self::assertSame('evt_1', Webhooks::verify($payload, self::signed($now, $payload), self::SECRET, 0, $now)->id);

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verify($payload, self::signed($now - 1, $payload), self::SECRET, 0, $now);
    }

    public function testANegativeToleranceIsRefused(): void
    {
        $payload = '{"id":"evt_1"}';
        $now = 1756468800;

        $this->expectException(ValidationException::class);
        Webhooks::verify($payload, self::signed($now, $payload), self::SECRET, -1, $now);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function nonObjectPayloads(): array
    {
        return ['array' => ['[{"id":"evt_1"}]'], 'empty array' => ['[]'], 'string' => ['"hi"'], 'number' => ['42']];
    }

    /**
     * @dataProvider nonObjectPayloads
     */
    public function testAVerifiedPayloadThatIsNotAJsonObjectIsRejected(string $payload): void
    {
        $now = 1756468800;

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verify($payload, self::signed($now, $payload), self::SECRET, 300, $now);
    }

    public function testAnEmptyJsonObjectPayloadIsAccepted(): void
    {
        $now = 1756468800;

        self::assertSame('', Webhooks::verify('{}', self::signed($now, '{}'), self::SECRET, 300, $now)->id);
    }
}
