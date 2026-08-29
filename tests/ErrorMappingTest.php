<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests;

use NaijaCloud\Email\Exception\AuthenticationException;
use NaijaCloud\Email\Exception\ConflictException;
use NaijaCloud\Email\Exception\NaijamailException;
use NaijaCloud\Email\Exception\NotFoundException;
use NaijaCloud\Email\Exception\PermissionException;
use NaijaCloud\Email\Exception\RateLimitException;
use NaijaCloud\Email\Exception\ServerException;
use NaijaCloud\Email\Exception\TimeoutException;
use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Tests\Support\ServerTestCase;

/**
 * Every status the contract names, mapped to its type.
 *
 * The taxonomy is identical in every language SDK, so a customer moving from
 * Node to PHP rewrites syntax and not error handling.
 */
final class ErrorMappingTest extends ServerTestCase
{
    /**
     * @return array<string,array{string,class-string<NaijamailException>,int}>
     */
    public static function statuses(): array
    {
        return [
            '400 validation' => ['err-400', ValidationException::class, 400],
            '400 message not found' => ['err-400-notfound', NotFoundException::class, 400],
            '401 authentication' => ['err-401', AuthenticationException::class, 401],
            '403 permission' => ['err-403', PermissionException::class, 403],
            '404 not found' => ['err-404', NotFoundException::class, 404],
            '408 timeout' => ['err-408', TimeoutException::class, 408],
            '409 conflict' => ['err-409', ConflictException::class, 409],
            '422 validation' => ['err-422', ValidationException::class, 422],
            '429 rate limit' => ['err-429', RateLimitException::class, 429],
            '500 server' => ['err-500', ServerException::class, 500],
        ];
    }

    /**
     * @dataProvider statuses
     *
     * @param class-string<NaijamailException> $expected
     */
    public function testEachStatusRaisesItsType(string $scenario, string $expected, int $status): void
    {
        try {
            // No retries, so a retryable status is observed rather than
            // absorbed; the retry policy has its own tests.
            $this->client($scenario, ['max_retries' => 0])->emails->send(self::validMessage());
            self::fail($expected . ' should have been raised');
        } catch (NaijamailException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertSame($status, $e->statusCode);
            self::assertSame($status, $e->getCode(), 'the status is also the exception code');
        }
    }

    public function testEveryErrorIsCatchableAsOneBaseType(): void
    {
        $this->expectException(NaijamailException::class);

        $this->client('err-403', ['max_retries' => 0])->emails->send(self::validMessage());
    }

    public function testTheServerLabelAndRequestIdAreCarried(): void
    {
        try {
            $this->client('err-403', ['max_retries' => 0])->emails->send(self::validMessage());
            self::fail('a 403 should have been raised');
        } catch (PermissionException $e) {
            self::assertSame('Forbidden', $e->errorLabel);
            self::assertSame('req_test_00000001', $e->requestId);
            self::assertStringContainsString('test key', $e->getMessage());
            self::assertJson((string) $e->body);
        }
    }

    /** NestJS sends one message or a list of them; both must read as one line. */
    public function testAnArrayOfMessagesIsJoined(): void
    {
        try {
            $this->client('err-400-array', ['max_retries' => 0])->emails->send(self::validMessage());
            self::fail('a 400 should have been raised');
        } catch (ValidationException $e) {
            self::assertSame('"from" is required; subject must be a string', $e->getMessage());
        }
    }

    /** Proxy HTML, an empty body, a gateway answering for the API. */
    public function testANonJsonErrorBodyFallsBackToTheStatusLine(): void
    {
        try {
            $this->client('err-502-html', ['max_retries' => 0])->emails->send(self::validMessage());
            self::fail('a 502 should have been raised');
        } catch (ServerException $e) {
            self::assertSame('HTTP 502 from the Naijamail API', $e->getMessage());
            self::assertStringContainsString('nginx', (string) $e->body, 'the raw body is kept');
        }
    }

    public function testRateLimitCarriesRetryAfterInSeconds(): void
    {
        try {
            $this->client('err-429', ['max_retries' => 0])->emails->send(self::validMessage());
            self::fail('a 429 should have been raised');
        } catch (RateLimitException $e) {
            self::assertSame(7, $e->getRetryAfter());
        }
    }

    public function testRateLimitWithoutTheHeaderHasNoRetryAfter(): void
    {
        try {
            $this->client('err-429-bare', ['max_retries' => 0])->emails->send(self::validMessage());
            self::fail('a 429 should have been raised');
        } catch (RateLimitException $e) {
            self::assertNull($e->getRetryAfter());
        }
    }
}
