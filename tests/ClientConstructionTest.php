<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests;

use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Naijamail;
use NaijaCloud\Email\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Construction-time rules: the key, the base URL, the options.
 *
 * Everything here fails before a socket exists, which is the point — a
 * misconfigured client should not be discovered by a 401 in production.
 */
final class ClientConstructionTest extends TestCase
{
    private const KEY = 'nmail_live_test0000000000000000';

    protected function tearDown(): void
    {
        putenv('NAIJAMAIL_API_KEY');
        putenv('NAIJAMAIL_BASE_URL');
    }

    public function testReadsTheKeyFromTheEnvironment(): void
    {
        putenv('NAIJAMAIL_API_KEY=' . self::KEY);

        $client = new Naijamail();

        self::assertSame('nmail_live_***', $client->redactedApiKey());
    }

    public function testAnExplicitKeyBeatsTheEnvironment(): void
    {
        putenv('NAIJAMAIL_API_KEY=nmail_test_fromenv0');

        $transport = new FakeTransport([FakeTransport::json(202, ['id' => 'x', 'status' => 'queued'])]);
        $client = new Naijamail(self::KEY, ['transport' => $transport]);
        $client->emails->send(self::message());

        self::assertSame('Bearer ' . self::KEY, $transport->lastHeader('Authorization'));
    }

    public function testAMissingKeyNamesTheEnvironmentVariable(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/NAIJAMAIL_API_KEY/');

        new Naijamail();
    }

    /**
     * @return array<string,array{string}>
     */
    public static function malformedKeys(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'wrong prefix' => ['sk_live_0123456789abcdef'],
            'no environment' => ['nmail_0123456789abcdef'],
            'too short' => ['nmail_live_short'],
            'illegal characters' => ['nmail_live_has spaces in it'],
            // The pre-scopes platform token. The API refuses it on the mail
            // routes outright — it predates the Email send scope and was never
            // granted mail access — so it fails here rather than at send time.
            'a platform token' => ['nc_pat_0123456789abcdef'],
            // There is no test variant of a workspace key.
            'a workspace test variant' => ['nc_test_0123456789abcdef'],
        ];
    }

    /**
     * @dataProvider malformedKeys
     */
    public function testAMalformedKeyIsRejectedLocally(string $key): void
    {
        $this->expectException(ValidationException::class);

        new Naijamail($key);
    }

    /** The commonest real failure: a key pasted with the newline attached. */
    public function testASurroundingNewlineIsTrimmedRatherThanRejected(): void
    {
        $client = new Naijamail("\n" . self::KEY . "\n");

        self::assertSame('nmail_live_***', $client->redactedApiKey());
    }

    /** A key from Settings -> API keys, carrying the Email send scope. */
    public function testAWorkspaceApiKeyIsAccepted(): void
    {
        $client = new Naijamail('nc_live_0123456789abcdefghij');

        // Redaction has to know the prefix too, or a workspace key falls
        // through to a bare '***' and an operator loses the one useful signal
        // in a dump: which kind of credential this process is holding.
        self::assertSame('nc_live_***', $client->redactedApiKey());
    }

    public function testATestKeyIsAcceptedLocally(): void
    {
        // Refusing it here would be wrong: a test key is legitimate against a
        // dev control plane. It is the live send path that rejects it, with 403.
        $client = new Naijamail('nmail_test_test0000000000000000');

        self::assertSame('nmail_test_***', $client->redactedApiKey());
    }

    public function testTheKeyIsNotInTheConstructionErrorMessage(): void
    {
        $key = 'nmail_live_notinerr';

        try {
            new Naijamail($key, ['base_url' => 'http://api.naijacloud.com']);
            self::fail('a plaintext base URL should have been rejected');
        } catch (ValidationException $e) {
            self::assertStringNotContainsString($key, $e->getMessage());
            self::assertStringNotContainsString($key, (string) $e);
        }
    }

    public function testAPlaintextBaseUrlIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/must be https/');

        new Naijamail(self::KEY, ['base_url' => 'http://api.naijacloud.com']);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function loopbackUrls(): array
    {
        return [
            'localhost' => ['http://localhost:3000'],
            'ipv4' => ['http://127.0.0.1:3000'],
            'ipv6' => ['http://[::1]:3000'],
        ];
    }

    /**
     * @dataProvider loopbackUrls
     */
    public function testLoopbackMayUsePlaintextForLocalDevelopment(string $url): void
    {
        $client = new Naijamail(self::KEY, ['base_url' => $url]);

        self::assertSame($url, $client->getBaseUrl());
    }

    /**
     * @return array<string,array{string}>
     */
    public static function badBaseUrls(): array
    {
        return [
            'not a url' => ['nonsense'],
            'no host' => ['https://'],
            'ftp' => ['ftp://127.0.0.1/v1'],
            'file' => ['file:///etc/passwd'],
            'credentials in the url' => ['https://user:secret@api.naijacloud.com'],
            'query string' => ['https://api.naijacloud.com?debug=1'],
            'fragment' => ['https://api.naijacloud.com#x'],
            'empty' => ['   '],
        ];
    }

    /**
     * @dataProvider badBaseUrls
     */
    public function testABaseUrlThatCouldLeakOrMisrouteIsRefused(string $url): void
    {
        $this->expectException(ValidationException::class);

        new Naijamail(self::KEY, ['base_url' => $url]);
    }

    public function testTheBaseUrlComesFromTheEnvironmentWhenNoOptionIsGiven(): void
    {
        putenv('NAIJAMAIL_BASE_URL=https://api.staging.naijacloud.com/');

        $client = new Naijamail(self::KEY);

        self::assertSame('https://api.staging.naijacloud.com', $client->getBaseUrl());
    }

    public function testTheDefaultBaseUrlIsProduction(): void
    {
        self::assertSame('https://api.naijacloud.com', (new Naijamail(self::KEY))->getBaseUrl());
    }

    public function testAnUnknownOptionIsRejectedRatherThanIgnored(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/unknown client option: timout/');

        new Naijamail(self::KEY, ['timout' => 5]);
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function badOptions(): array
    {
        return [
            'zero timeout' => [['timeout' => 0]],
            'negative timeout' => [['timeout' => -1]],
            'non-numeric timeout' => [['timeout' => '30']],
            'negative retries' => [['max_retries' => -1]],
            'non-integer retries' => [['max_retries' => 1.5]],
            'absurd retries' => [['max_retries' => 50]],
            'non-string suffix' => [['user_agent_suffix' => 42]],
            'transport of the wrong type' => [['transport' => new \stdClass()]],
        ];
    }

    /**
     * @dataProvider badOptions
     *
     * @param array<string,mixed> $options
     */
    public function testOptionsAreValidated(array $options): void
    {
        $this->expectException(ValidationException::class);

        new Naijamail(self::KEY, $options);
    }

    public function testTheUserAgentIdentifiesTheSdkAndRuntime(): void
    {
        $client = new Naijamail(self::KEY);

        self::assertSame(
            sprintf('nc-email-php/%s (PHP/%s)', Naijamail::VERSION, PHP_VERSION),
            $client->getUserAgent(),
        );
    }

    public function testTheUserAgentSuffixIsAppended(): void
    {
        $client = new Naijamail(self::KEY, ['user_agent_suffix' => 'acme-billing/2.1']);

        self::assertStringEndsWith(' acme-billing/2.1', $client->getUserAgent());
    }

    /** A newline in a header value appends headers of the caller's choosing. */
    public function testAUserAgentSuffixCannotInjectAHeader(): void
    {
        $this->expectException(ValidationException::class);

        new Naijamail(self::KEY, ['user_agent_suffix' => "acme\r\nX-Admin: 1"]);
    }

    public function testTheUserAgentNeverCarriesTheKey(): void
    {
        $client = new Naijamail(self::KEY);

        self::assertStringNotContainsString(self::KEY, $client->getUserAgent());
    }

    /** Two clients, two keys, one process: neither may touch the other. */
    public function testTwoClientsDoNotShareState(): void
    {
        $first = new FakeTransport([FakeTransport::json(202, ['id' => 'a', 'status' => 'queued'])]);
        $second = new FakeTransport([FakeTransport::json(202, ['id' => 'b', 'status' => 'queued'])]);

        $platform = new Naijamail('nmail_live_platfrm0', [
            'transport' => $first,
            'base_url' => 'https://api.naijacloud.com',
        ]);
        $customer = new Naijamail('nmail_test_custmer0', [
            'transport' => $second,
            'base_url' => 'https://api.staging.naijacloud.com',
        ]);

        $platform->emails->send(self::message());
        $customer->emails->send(self::message());

        self::assertSame('Bearer nmail_live_platfrm0', $first->lastHeader('Authorization'));
        self::assertSame('Bearer nmail_test_custmer0', $second->lastHeader('Authorization'));
        self::assertStringStartsWith('https://api.naijacloud.com/', $first->sends[0]['url']);
        self::assertStringStartsWith('https://api.staging.naijacloud.com/', $second->sends[0]['url']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function message(): array
    {
        return [
            'from' => 'hello@acme.com',
            'to' => 'customer@example.com',
            'subject' => 'Hi',
            'text' => 'Hello.',
        ];
    }
}
