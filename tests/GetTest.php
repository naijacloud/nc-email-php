<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests;

use NaijaCloud\Email\Exception\NotFoundException;
use NaijaCloud\Email\MessageStatus;
use NaijaCloud\Email\Tests\Support\ServerTestCase;

/**
 * Retrieving one message.
 */
final class GetTest extends ServerTestCase
{
    private const ID = '5b1e0000-0000-4000-8000-000000000001';

    public function testADeliveredMessageIsFullyDecoded(): void
    {
        $email = $this->client()->emails->get(self::ID);

        self::assertSame(self::ID, $email->id);
        self::assertSame('customer@example.com', $email->to);
        self::assertSame('hello@acme.com', $email->from);
        self::assertSame('Your receipt', $email->subject);
        self::assertSame(MessageStatus::DELIVERED, $email->status);
        self::assertSame('2026-08-29T10:00:00+00:00', $email->createdAt?->format('c'));
        self::assertSame('2026-08-29T10:00:04+00:00', $email->deliveredAt?->format('c'));
        self::assertFalse($email->opened);
        self::assertFalse($email->clicked);
        self::assertNull($email->failureReason);
        self::assertTrue($email->isTerminal());
    }

    public function testTheIdIsSentInThePath(): void
    {
        $this->client()->emails->get(self::ID);

        self::assertSame('/v1/emails/' . self::ID, self::$server->requests()[0]['path']);
        self::assertSame('GET', self::$server->requests()[0]['method']);
    }

    /** A field this release has never heard of must not break decoding. */
    public function testAnUnknownFieldIsIgnored(): void
    {
        $email = $this->client()->emails->get(self::ID);

        self::assertSame(MessageStatus::DELIVERED, $email->status);
    }

    /**
     * A test-key message is never sent, so a "bounced" one is simulated.
     * Without the flag it reads exactly like a real deliverability problem.
     */
    public function testTheSandboxFlagIsExposed(): void
    {
        self::assertTrue($this->client('sandbox-message')->emails->get(self::ID)->sandbox);
        self::assertFalse($this->client()->emails->get(self::ID)->sandbox);
    }

    public function testDeliveredAtIsNullUntilDelivery(): void
    {
        $email = $this->client('queued-message')->emails->get(self::ID);

        self::assertSame(MessageStatus::QUEUED, $email->status);
        self::assertNull($email->deliveredAt);
        self::assertFalse($email->isTerminal());
    }

    public function testAFailureReasonIsExposedWhenPresent(): void
    {
        $email = $this->client('failed-message')->emails->get(self::ID);

        self::assertSame(MessageStatus::BOUNCED, $email->status);
        self::assertSame('550 5.1.1 user unknown', $email->failureReason);
        self::assertTrue($email->opened);
        self::assertTrue($email->clicked);
    }

    /**
     * A backed enum would throw on a status added after this release shipped,
     * turning "the platform gained a status" into "every deployed SDK crashes".
     */
    public function testAnUnknownStatusPassesThroughAsAString(): void
    {
        $email = $this->client('unknown-status')->emails->get(self::ID);

        self::assertSame('quarantined', $email->status);
        self::assertFalse(MessageStatus::isKnown($email->status));
        self::assertTrue(MessageStatus::isKnown(MessageStatus::DELIVERED));
    }

    /** An unparseable timestamp is null, not an exception. */
    public function testAnUnreadableTimestampDoesNotBreakTheMessage(): void
    {
        $email = $this->client('unknown-status')->emails->get(self::ID);

        self::assertNull($email->createdAt);
        self::assertSame('x@y.com', $email->to);
    }

    /**
     * The known server quirk: an id that does not exist answers 400 with
     * "message not found" instead of 404. Callers should not have to know.
     */
    public function testTheFourHundredNotFoundQuirkIsMappedToNotFound(): void
    {
        try {
            $this->client('err-400-notfound')->emails->get(self::ID);
            self::fail('a NotFoundException should have been raised');
        } catch (NotFoundException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertSame('message not found', $e->getMessage());
            self::assertSame('req_test_00000001', $e->requestId);
        }
    }

    public function testARealFourHundredIsStillAValidationError(): void
    {
        $this->expectException(\NaijaCloud\Email\Exception\ValidationException::class);

        $this->client('err-400')->emails->get(self::ID);
    }
}
