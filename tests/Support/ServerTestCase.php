<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests\Support;

use NaijaCloud\Email\Naijamail;
use PHPUnit\Framework\TestCase;

/**
 * Base for the tests that drive the real CurlTransport against a local server.
 */
abstract class ServerTestCase extends TestCase
{
    /** The literal the contract reserves for tests. Never a real key. */
    public const TEST_KEY = 'nmail_live_test0000000000000000';

    protected static MockServer $server;

    /**
     * Backoff the client would have spent. Recorded rather than slept, so the
     * retry policy is asserted exactly and the suite stays fast.
     *
     * @var list<float>
     */
    protected array $slept = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = MockServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->reset();
        $this->slept = [];
    }

    /**
     * @param array<string,mixed> $options
     */
    protected function client(string $scenario = '', array $options = []): Naijamail
    {
        return new Naijamail(self::TEST_KEY, array_merge([
            'base_url' => self::$server->baseUrl($scenario),
            'sleeper' => function (float $seconds): void {
                $this->slept[] = $seconds;
            },
        ], $options));
    }

    /**
     * @return array<string,mixed>
     */
    protected static function validMessage(): array
    {
        return [
            'from' => 'Acme <hello@acme.com>',
            'to' => 'customer@example.com',
            'subject' => 'Your receipt',
            'html' => '<p>Thanks.</p>',
        ];
    }
}
