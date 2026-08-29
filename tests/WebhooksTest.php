<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests;

use NaijaCloud\Email\Exception\WebhookVerificationException;
use NaijaCloud\Email\Webhooks;
use PHPUnit\Framework\TestCase;

/**
 * Webhook signature verification.
 *
 * The scheme is fixed by the contract but the control plane does not emit these
 * yet, so these tests are the only thing holding the implementation to the
 * definition until it does.
 */
final class WebhooksTest extends TestCase
{
    private const SECRET = 'nmail_whsec_test0000000000000000';

    private const PAYLOAD = '{"id":"evt_1","type":"email.delivered",'
        . '"created_at":"2026-08-29T10:00:04.000Z",'
        . '"data":{"email_id":"5b1e","to":"customer@example.com"}}';

    private static function header(
        int $timestamp,
        string $payload = self::PAYLOAD,
        string $secret = self::SECRET,
    ): string {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    public function testAValidSignatureYieldsTheEvent(): void
    {
        $now = 1756468800;

        $event = Webhooks::verify(self::PAYLOAD, self::header($now), self::SECRET, 300, $now);

        self::assertSame('evt_1', $event->id);
        self::assertSame('email.delivered', $event->type);
        self::assertSame('2026-08-29T10:00:04+00:00', $event->createdAt?->format('c'));
        self::assertSame('customer@example.com', $event->data['to']);
    }

    /** During a rotation both the old and the new secret sign the delivery. */
    public function testAnyOfSeveralSignaturesMayMatch(): void
    {
        $now = 1756468800;
        $header = 't=' . $now
            . ',v1=' . str_repeat('a', 64)
            . ',v1=' . hash_hmac('sha256', $now . '.' . self::PAYLOAD, self::SECRET);

        $event = Webhooks::verify(self::PAYLOAD, $header, self::SECRET, 300, $now);

        self::assertSame('evt_1', $event->id);
    }

    public function testAWrongSecretIsRejected(): void
    {
        $now = 1756468800;

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('webhook signature does not match');

        Webhooks::verify(
            self::PAYLOAD,
            self::header($now, self::PAYLOAD, 'nmail_whsec_someoneelses00000000'),
            self::SECRET,
            300,
            $now,
        );
    }

    /** The signature is over the bytes that were sent, not over a re-encoding. */
    public function testATamperedPayloadIsRejected(): void
    {
        $now = 1756468800;
        $header = self::header($now);
        $tampered = str_replace('customer@example.com', 'attacker@example.com', self::PAYLOAD);

        $this->expectException(WebhookVerificationException::class);

        Webhooks::verify($tampered, $header, self::SECRET, 300, $now);
    }

    /**
     * A captured delivery carries a signature that is genuinely ours and would
     * verify forever. The timestamp is the only thing that makes it expire.
     */
    public function testAStaleTimestampIsRejectedEvenWithAValidSignature(): void
    {
        $signedAt = 1756468800;

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/outside the 300s tolerance/');

        Webhooks::verify(self::PAYLOAD, self::header($signedAt), self::SECRET, 300, $signedAt + 301);
    }

    public function testATimestampInsideTheToleranceIsAccepted(): void
    {
        $signedAt = 1756468800;

        $event = Webhooks::verify(self::PAYLOAD, self::header($signedAt), self::SECRET, 300, $signedAt + 299);

        self::assertSame('evt_1', $event->id);
    }

    /** A clock that runs fast on the sender is as suspect as one that runs slow. */
    public function testATimestampFarInTheFutureIsRejected(): void
    {
        $signedAt = 1756468800;

        $this->expectException(WebhookVerificationException::class);

        Webhooks::verify(self::PAYLOAD, self::header($signedAt), self::SECRET, 300, $signedAt - 900);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function malformedHeaders(): array
    {
        return [
            'empty' => [''],
            'no timestamp' => ['v1=' . str_repeat('a', 64)],
            'no signature' => ['t=1756468800'],
            'timestamp not a number' => ['t=yesterday,v1=' . str_repeat('a', 64)],
            'signature not hex' => ['t=1756468800,v1=not-a-signature'],
            'signature wrong length' => ['t=1756468800,v1=abcdef'],
            'nonsense' => ['garbage'],
            'wrong scheme version' => ['t=1756468800,v2=' . str_repeat('a', 64)],
        ];
    }

    /**
     * @dataProvider malformedHeaders
     */
    public function testAMalformedHeaderIsRejected(string $header): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/malformed NC-Signature header/');

        Webhooks::verify(self::PAYLOAD, $header, self::SECRET, 300, 1756468800);
    }

    public function testWhitespaceAroundThePartsIsTolerated(): void
    {
        $now = 1756468800;
        $header = ' t=' . $now . ' , v1=' . hash_hmac('sha256', $now . '.' . self::PAYLOAD, self::SECRET) . ' ';

        self::assertSame('evt_1', Webhooks::verify(self::PAYLOAD, $header, self::SECRET, 300, $now)->id);
    }

    public function testAnUppercaseHexSignatureStillMatches(): void
    {
        $now = 1756468800;
        $header = 't=' . $now . ',v1='
            . strtoupper(hash_hmac('sha256', $now . '.' . self::PAYLOAD, self::SECRET));

        self::assertSame('evt_1', Webhooks::verify(self::PAYLOAD, $header, self::SECRET, 300, $now)->id);
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/signing secret is required/');

        Webhooks::verify(self::PAYLOAD, self::header(1756468800), '   ', 300, 1756468800);
    }

    /** Returning the expected signature would turn the verifier into an oracle. */
    public function testTheErrorNeverContainsTheExpectedSignature(): void
    {
        $now = 1756468800;
        $expected = hash_hmac('sha256', $now . '.' . self::PAYLOAD, self::SECRET);

        try {
            Webhooks::verify(self::PAYLOAD, 't=' . $now . ',v1=' . str_repeat('b', 64), self::SECRET, 300, $now);
            self::fail('the bad signature should have been rejected');
        } catch (WebhookVerificationException $e) {
            self::assertStringNotContainsString($expected, $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString($expected, (string) $e);
        }
    }

    public function testAVerifiedButUnparseableBodyIsStillARejection(): void
    {
        $now = 1756468800;
        $payload = 'not json at all';

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/not a JSON object/');

        Webhooks::verify($payload, self::header($now, $payload), self::SECRET, 300, $now);
    }

    public function testAnEventWithUnknownFieldsStillDecodes(): void
    {
        $now = 1756468800;
        $payload = '{"id":"evt_2","type":"email.opened","new_field":"from a later release","data":{}}';

        $event = Webhooks::verify($payload, self::header($now, $payload), self::SECRET, 300, $now);

        self::assertSame('evt_2', $event->id);
        self::assertSame('email.opened', $event->type);
        self::assertNull($event->createdAt);
        self::assertSame([], $event->data);
    }

    /**
     * hash_equals, never ===. A short-circuiting comparison leaks how many
     * leading characters were right through its timing.
     */
    public function testTheComparisonIsConstantTime(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Webhooks.php');

        self::assertStringContainsString('hash_equals(', (string) $source);
        self::assertDoesNotMatchRegularExpression('/\$expected\s*===/', (string) $source);
    }
}
