<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests;

use NaijaCloud\Email\Exception\ConnectionException;
use NaijaCloud\Email\Exception\TimeoutException;
use NaijaCloud\Email\Http\CurlTransport;
use NaijaCloud\Email\Naijamail;
use NaijaCloud\Email\Tests\Support\MockServer;
use NaijaCloud\Email\Tests\Support\ServerTestCase;

/**
 * The transport itself, against a real socket.
 */
final class TransportTest extends ServerTestCase
{
    /**
     * The protocol allow-list, tested directly: the client refuses a plaintext
     * base URL at construction, and libcurl refuses to speak http at all unless
     * the transport was told this is a loopback dev override. Two independent
     * checks, because a live key on a cleartext socket is unrecoverable.
     */
    public function testCurlRefusesPlaintextUnlessItIsALoopbackOverride(): void
    {
        $transport = new CurlTransport(allowPlaintext: false);

        $this->expectException(ConnectionException::class);

        $transport->send('GET', self::$server->baseUrl() . '/v1/emails/x', [], null, 5.0);
    }

    public function testTheLoopbackOverrideAllowsPlaintextForLocalDevelopment(): void
    {
        $transport = new CurlTransport(allowPlaintext: true);

        $response = $transport->send('GET', self::$server->baseUrl() . '/v1/emails/x', [], null, 5.0);

        self::assertSame(200, $response->status);
    }

    public function testResponseHeadersAreReadableCaseInsensitively(): void
    {
        $transport = new CurlTransport(allowPlaintext: true);

        $response = $transport->send('GET', self::$server->baseUrl() . '/v1/emails/x', [], null, 5.0);

        self::assertSame('req_test_00000001', $response->header('X-Request-Id'));
        self::assertSame('req_test_00000001', $response->header('x-request-id'));
        self::assertNull($response->header('x-not-sent'));
    }

    public function testNothingIsListeningIsAConnectionError(): void
    {
        $client = new Naijamail(self::TEST_KEY, [
            'base_url' => 'http://127.0.0.1:' . MockServer::deadPort(),
            'max_retries' => 0,
            'timeout' => 2.0,
        ]);

        $this->expectException(ConnectionException::class);

        $client->emails->send(self::validMessage());
    }

    /**
     * The client-side deadline. Kept last in the class: the mock server is
     * single-process, so it is still finishing this request while the next test
     * would be starting.
     */
    public function testAClientSideDeadlineRaisesTimeout(): void
    {
        $client = $this->client('slow', ['timeout' => 0.3, 'max_retries' => 0]);

        $this->expectException(TimeoutException::class);

        $client->emails->send(self::validMessage());
    }
}
