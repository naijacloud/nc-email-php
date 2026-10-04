<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests;

use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Limits;
use NaijaCloud\Email\Naijamail;
use NaijaCloud\Email\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Everything the SDK refuses before opening a socket.
 *
 * A scripted transport rather than the local server, so that "the request never
 * left the process" is an assertion rather than an assumption.
 */
final class SendValidationTest extends TestCase
{
    private const KEY = 'nmail_live_test0000000000000000';

    private FakeTransport $transport;

    private Naijamail $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport([
            FakeTransport::json(202, ['id' => '5b1e', 'status' => 'queued']),
        ]);
        $this->client = new Naijamail(self::KEY, ['transport' => $this->transport]);
    }

    /**
     * @param array<string,mixed> $overrides
     *
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

    /**
     * @param array<string,mixed> $params
     */
    private function assertRejectedLocally(array $params, string $expectedMessage = ''): void
    {
        try {
            $this->client->emails->send($params);
            self::fail('the SDK should have refused this message');
        } catch (ValidationException $e) {
            if ($expectedMessage !== '') {
                self::assertStringContainsString($expectedMessage, $e->getMessage());
            }
            self::assertSame(0, $e->statusCode, 'a local error carries status 0');
            self::assertSame([], $this->transport->sends, 'nothing should have gone on the wire');
        }
    }

    public function testFromIsRequired(): void
    {
        $params = self::message();
        unset($params['from']);

        $this->assertRejectedLocally($params, '"from" is required');
    }

    public function testToIsRequired(): void
    {
        $params = self::message();
        unset($params['to']);

        $this->assertRejectedLocally($params, '"to" is required');
    }

    public function testAnEmptyRecipientArrayIsRequiredToo(): void
    {
        $this->assertRejectedLocally(self::message(['to' => []]), '"to" is required');
    }

    public function testAMessageWithNoContentAtAllIsRefused(): void
    {
        $params = self::message();
        unset($params['html']);

        $this->assertRejectedLocally($params, 'needs "html", "text" or an attachment');
    }

    public function testAnAttachmentOnlyMessageIsAllowed(): void
    {
        $params = self::message();
        unset($params['html']);
        $params['attachments'] = [['filename' => 'statement.pdf', 'content' => 'bytes']];

        $this->client->emails->send($params);

        self::assertCount(1, $this->transport->sends);
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function headerInjectionAttempts(): array
    {
        $crlf = "\r\nX-Injected: 1";

        return [
            'from' => [['from' => 'hello@acme.com' . $crlf]],
            'to' => [['to' => 'customer@example.com' . $crlf]],
            'to in an array' => [['to' => ['ok@example.com', 'bad@example.com' . $crlf]]],
            'cc' => [['cc' => 'cc@example.com' . $crlf]],
            'bcc' => [['bcc' => 'bcc@example.com' . $crlf]],
            'reply_to' => [['reply_to' => 'reply@example.com' . $crlf]],
            'subject' => [['subject' => 'Receipt' . $crlf]],
            'bare newline in subject' => [['subject' => "Receipt\nBcc: everyone@example.com"]],
            'NUL in subject' => [['subject' => "Receipt\0hidden"]],
            'header value' => [['headers' => ['X-Campaign' => 'august' . $crlf]]],
            'header name' => [['headers' => ["X-Campaign\r\nX-Admin" => 'yes']]],
            'attachment filename' => [[
                'attachments' => [['filename' => "invoice\r\n.pdf", 'content' => 'bytes']],
            ]],
            'idempotency key' => [['idempotency_key' => "abc\r\nX-Admin: 1"]],
        ];
    }

    /**
     * @dataProvider headerInjectionAttempts
     *
     * @param array<string,mixed> $overrides
     */
    public function testHeaderInjectionIsRefusedBeforeTheRequest(array $overrides): void
    {
        $this->assertRejectedLocally(self::message($overrides));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function forbiddenHeaders(): array
    {
        return [
            ['from'], ['To'], ['CC'], ['bcc'], ['Subject'], ['DKIM-Signature'], ['received'],
        ];
    }

    /**
     * @dataProvider forbiddenHeaders
     */
    public function testHeadersThatWouldSidestepDomainAuthorisationAreRefused(string $name): void
    {
        $this->assertRejectedLocally(
            self::message(['headers' => [$name => 'someone@elsewhere.com']]),
            'cannot be overridden',
        );
    }

    public function testAHeaderNameWithASpaceOrColonIsRefused(): void
    {
        $this->assertRejectedLocally(self::message(['headers' => ['X Campaign' => 'a']]));
    }

    public function testTooManyRecipientsIsRefusedWithoutARoundTrip(): void
    {
        $to = [];
        for ($i = 0; $i < Limits::MAX_RECIPIENTS + 1; $i++) {
            $to[] = sprintf('customer%d@example.com', $i);
        }

        $this->assertRejectedLocally(self::message(['to' => $to]), 'too many recipients');
    }

    public function testTheRecipientLimitCountsToCcAndBccTogether(): void
    {
        $this->assertRejectedLocally(
            self::message([
                'to' => array_map(static fn (int $i) => "to{$i}@example.com", range(1, 20)),
                'cc' => array_map(static fn (int $i) => "cc{$i}@example.com", range(1, 20)),
                'bcc' => array_map(static fn (int $i) => "bcc{$i}@example.com", range(1, 20)),
            ]),
            'too many recipients',
        );
    }

    public function testTooManyHeadersIsRefused(): void
    {
        $headers = [];
        for ($i = 0; $i <= Limits::MAX_HEADERS; $i++) {
            $headers['X-Custom-' . $i] = 'value';
        }

        $this->assertRejectedLocally(self::message(['headers' => $headers]), 'too many custom headers');
    }

    public function testTooManyTagsIsRefused(): void
    {
        $tags = [];
        for ($i = 0; $i <= Limits::MAX_TAGS; $i++) {
            $tags['tag' . $i] = 'value';
        }

        $this->assertRejectedLocally(self::message(['tags' => $tags]), 'too many tags');
    }

    /** The server would truncate these; a split campaign is worse than an error. */
    public function testAnOverlongTagIsRefusedRatherThanTruncated(): void
    {
        $this->assertRejectedLocally(
            self::message(['tags' => [str_repeat('k', Limits::MAX_TAG_KEY_CHARS + 1) => 'v']]),
            'would truncate',
        );
        $this->transport->sends = [];
        $this->assertRejectedLocally(
            self::message(['tags' => ['campaign' => str_repeat('v', Limits::MAX_TAG_VALUE_CHARS + 1)]]),
            'would truncate',
        );
    }

    /**
     * The server truncates by JavaScript's `.length` (UTF-16 units). An emoji
     * is 2 there and 1 in mb_strlen, so counting code points let through tags
     * the server then silently shortened.
     */
    public function testTagLengthIsCountedTheWayTheServerCountsIt(): void
    {
        $this->assertRejectedLocally(
            self::message(['tags' => [str_repeat("\u{1F600}", 33) => 'v']]),
            'would truncate',
        );
        $this->assertRejectedLocally(
            self::message(['tags' => ['k' => str_repeat("\u{1F600}", 129)]]),
            'would truncate',
        );

        $this->client->emails->send(self::message(['tags' => [str_repeat('é', 60) => str_repeat('ọ', 250)]]));
        self::assertCount(1, $this->transport->sends);
    }

    public function testAPayloadOverTheSizeLimitIsRefusedBeforeSending(): void
    {
        // Just over 10 MiB once base64 has added its third.
        $this->assertRejectedLocally(
            self::message([
                'attachments' => [[
                    'filename' => 'huge.bin',
                    'content' => str_repeat('A', 8 * 1024 * 1024),
                ]],
            ]),
            'over the',
        );
    }

    /** An SDK that opens whatever path it is handed is a file-disclosure bug. */
    public function testAnAttachmentPathIsRefusedWithAnExplanation(): void
    {
        $this->assertRejectedLocally(
            self::message(['attachments' => [['filename' => 'x.pdf', 'path' => '/etc/passwd']]]),
            'does not accept a file path',
        );
    }

    public function testAnUnknownParameterIsRejectedRatherThanDropped(): void
    {
        $this->assertRejectedLocally(
            self::message(['bcc_list' => 'quiet@example.com']),
            'unknown send() parameter: bcc_list',
        );
    }

    public function testAnUnknownAttachmentKeyIsRejected(): void
    {
        $this->assertRejectedLocally(
            self::message(['attachments' => [[
                'filename' => 'x.pdf',
                'content' => 'bytes',
                'mime_type' => 'application/pdf',
            ]]]),
            'unknown attachments[0] key',
        );
    }

    public function testNonUtf8ContentIsReportedAsAnEncodingErrorNotACrash(): void
    {
        $this->assertRejectedLocally(
            self::message(['html' => "<p>\xC3\x28 broken</p>"]),
            'could not be encoded as JSON',
        );
    }

    public function testAnEmptyEmailIdIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->client->emails->get('   ');
    }

    /** The id often comes from a URL or a column the caller did not write. */
    public function testAnEmailIdCannotEscapeTheRequestPath(): void
    {
        $this->client->emails->get('../../admin/keys');

        self::assertStringEndsWith(
            '/v1/emails/..%2F..%2Fadmin%2Fkeys',
            $this->transport->sends[0]['url'],
        );
    }
}
