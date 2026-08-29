<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests;

use NaijaCloud\Email\MessageStatus;
use NaijaCloud\Email\Model\RejectedRecipient;
use NaijaCloud\Email\Naijamail;
use NaijaCloud\Email\Tests\Support\ServerTestCase;

/**
 * Sending, over a real socket to a local server.
 */
final class SendTest extends ServerTestCase
{
    public function testASuccessfulSendReturnsTheMessageId(): void
    {
        $sent = $this->client()->emails->send(self::validMessage());

        self::assertSame('5b1e0000-0000-4000-8000-000000000001', $sent->id);
        self::assertSame(MessageStatus::QUEUED, $sent->status);
        self::assertSame([], $sent->rejected);
    }

    /**
     * The server omits `rejected` when nobody was refused. Callers must never
     * have to branch on absence, so it is always a list.
     */
    public function testRejectedIsAnEmptyListWhenTheServerOmitsIt(): void
    {
        $sent = $this->client()->emails->send(self::validMessage());

        self::assertIsArray($sent->rejected);
        self::assertCount(0, $sent->rejected);
    }

    public function testRejectedRecipientsArePassedThroughAndAreNotAnError(): void
    {
        $sent = $this->client('rejected')->emails->send(self::validMessage());

        self::assertCount(1, $sent->rejected);
        self::assertInstanceOf(RejectedRecipient::class, $sent->rejected[0]);
        self::assertSame('blocked@example.com', $sent->rejected[0]->address);
        self::assertSame('suppressed', $sent->rejected[0]->reason);
        self::assertSame(MessageStatus::QUEUED, $sent->status, 'the rest of the message still went');
    }

    public function testTheRequestCarriesTheAuthAndContentHeaders(): void
    {
        $this->client()->emails->send(self::validMessage());

        $headers = self::$server->requests()[0]['headers'];

        self::assertSame('Bearer ' . self::TEST_KEY, $headers['authorization']);
        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('application/json', $headers['accept']);
        self::assertStringStartsWith('nc-email-php/' . Naijamail::VERSION, $headers['user-agent']);
        self::assertStringContainsString('PHP/', $headers['user-agent']);
    }

    public function testTheBodyIsTheContractsWireShape(): void
    {
        $this->client()->emails->send([
            'from' => 'Acme <hello@acme.com>',
            'to' => 'customer@example.com',
            'cc' => ['finance@acme.com'],
            'reply_to' => 'support@acme.com',
            'subject' => 'Invoice #1024',
            'html' => '<p>Attached.</p>',
            'text' => 'Attached.',
            'headers' => ['X-Campaign' => 'invoices'],
            'tags' => ['campaign' => 'invoices'],
        ]);

        $body = self::$server->requestBody();

        self::assertSame('Acme <hello@acme.com>', $body['from']);
        // Single addresses are normalised to a list, which the API accepts
        // everywhere and which removes a shape from the caller's problem.
        self::assertSame(['customer@example.com'], $body['to']);
        self::assertSame(['finance@acme.com'], $body['cc']);
        self::assertSame(['support@acme.com'], $body['reply_to'], 'snake_case on the wire');
        self::assertArrayNotHasKey('replyTo', $body);
        self::assertSame('Invoice #1024', $body['subject']);
        self::assertSame(['X-Campaign' => 'invoices'], $body['headers']);
        self::assertSame(['campaign' => 'invoices'], $body['tags']);
    }

    public function testReplyToIsAlsoAcceptedInItsCamelCaseSpelling(): void
    {
        $this->client()->emails->send(self::validMessage() + ['replyTo' => 'support@acme.com']);

        self::assertSame(['support@acme.com'], self::$server->requestBody()['reply_to']);
    }

    /** The server defaults a missing subject to ""; send it anyway, explicitly. */
    public function testTheSubjectIsAlwaysSentEvenWhenEmpty(): void
    {
        $params = self::validMessage();
        unset($params['subject']);

        $this->client()->emails->send($params);

        self::assertArrayHasKey('subject', self::$server->requestBody());
        self::assertSame('', self::$server->requestBody()['subject']);
    }

    /** Callers pass bytes; the base64 is ours to get right. */
    public function testAttachmentBytesAreBase64EncodedOnTheWire(): void
    {
        $pdf = "%PDF-1.4\n\x00\x01\x02binary";

        $this->client()->emails->send(self::validMessage() + [
            'attachments' => [[
                'filename' => 'invoice-1024.pdf',
                'content' => $pdf,
                'content_type' => 'application/pdf',
            ]],
        ]);

        $attachment = self::$server->requestBody()['attachments'][0];

        self::assertSame('invoice-1024.pdf', $attachment['filename']);
        self::assertSame(base64_encode($pdf), $attachment['content']);
        self::assertSame($pdf, base64_decode($attachment['content'], true));
        self::assertSame('application/pdf', $attachment['content_type']);
    }

    public function testAnIdempotencyKeyIsGeneratedForEverySend(): void
    {
        $this->client()->emails->send(self::validMessage());

        $key = self::$server->requests()[0]['headers']['idempotency-key'] ?? null;

        self::assertIsString($key);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $key,
            'a UUIDv4',
        );
    }

    public function testTwoSendsGetDifferentIdempotencyKeys(): void
    {
        $client = $this->client();
        $client->emails->send(self::validMessage());
        $client->emails->send(self::validMessage());

        $requests = self::$server->requests();

        self::assertNotSame(
            $requests[0]['headers']['idempotency-key'],
            $requests[1]['headers']['idempotency-key'],
            'two separate messages must not dedupe into one',
        );
    }

    public function testACallerSuppliedIdempotencyKeyIsUsedVerbatim(): void
    {
        $this->client()->emails->send(self::validMessage() + ['idempotency_key' => 'order-1024-receipt']);

        self::assertSame(
            'order-1024-receipt',
            self::$server->requests()[0]['headers']['idempotency-key'],
        );
        self::assertArrayNotHasKey(
            'idempotency_key',
            self::$server->requestBody(),
            'the header is what the server reads first',
        );
    }

    public function testTheSdkDoesNotFollowRedirects(): void
    {
        // A followed redirect would re-send the Authorization header to
        // evil.example.com, which is exactly how bearer tokens leak.
        $this->expectException(\NaijaCloud\Email\Exception\ServerException::class);
        $this->expectExceptionMessageMatches('/unexpected redirect/');

        $this->client('redirect')->emails->send(self::validMessage());
    }

    public function testA2xxThatIsNotJsonIsAServerFaultNotAnEmptySuccess(): void
    {
        $this->expectException(\NaijaCloud\Email\Exception\ServerException::class);
        $this->expectExceptionMessageMatches('/not JSON/');

        $this->client('ok-nonjson')->emails->send(self::validMessage());
    }
}
