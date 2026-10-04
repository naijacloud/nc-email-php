<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests;

use LogicException;
use NaijaCloud\Email\Exception\NaijamailException;
use NaijaCloud\Email\Http\CurlTransport;
use NaijaCloud\Email\Naijamail;
use NaijaCloud\Email\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * The key must not escape the SDK by any route except the Authorization header.
 *
 * Each of these corresponds to a way a credential has actually left a process
 * in the wild: a debug dump on an error page, a `print_r` in a log line, an
 * object graph exported into a bug report, a client cached in a session.
 */
final class KeyRedactionTest extends TestCase
{
    /**
     * A key whose secret half is a string nothing else in the repository
     * contains, so "the key did not appear" is an assertion about this key
     * and not about the prefix every test shares. Short on purpose: the
     * family's secret scan flags any long nmail_live_ literal in a repo, and
     * it is right to.
     */
    private const KEY = 'nmail_live_needle00';

    public function testVarDumpDoesNotRevealTheKey(): void
    {
        $client = new Naijamail(self::KEY);

        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::KEY, $dump);
        self::assertStringNotContainsString('needle00', $dump);
        self::assertStringContainsString('nmail_live_***', $dump);
    }

    public function testPrintRDoesNotRevealTheKey(): void
    {
        $client = new Naijamail(self::KEY);

        self::assertStringNotContainsString('needle00', print_r($client, true));
    }

    /**
     * var_export ignores __debugInfo entirely and prints the raw property
     * table, which is why the key is kept inside a closure rather than in a
     * property. This test is the reason that design exists.
     */
    public function testVarExportDoesNotRevealTheKey(): void
    {
        $client = new Naijamail(self::KEY);

        self::assertStringNotContainsString('needle00', var_export($client, true));
    }

    public function testJsonEncodingTheClientDoesNotRevealTheKey(): void
    {
        $client = new Naijamail(self::KEY);

        self::assertStringNotContainsString('needle00', (string) json_encode($client));
    }

    public function testTheEmailsResourceDoesNotRevealTheKeyEither(): void
    {
        $client = new Naijamail(self::KEY);

        ob_start();
        var_dump($client->emails);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('needle00', $dump);
        self::assertStringNotContainsString('needle00', var_export($client->emails, true));
        self::assertStringNotContainsString('needle00', print_r($client->emails, true));
    }

    public function testTheTransportDoesNotRetainRequestHeaders(): void
    {
        $transport = new CurlTransport();

        ob_start();
        var_dump($transport);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('Authorization', $dump);
        self::assertStringContainsString('no request headers are retained', $dump);
    }

    /**
     * A serialized client is a key written into a session file, a cache entry
     * or a queue payload — all of which outlive the process and get backed up.
     */
    public function testTheClientCannotBeSerialized(): void
    {
        $client = new Naijamail(self::KEY);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/cannot be serialized/');

        serialize($client);
    }

    public function testTheClientCannotBeUnserializedIntoExistence(): void
    {
        $this->expectException(LogicException::class);

        // The payload a deserialization gadget would use. It must not produce a
        // usable client, and it must not be quietly ignored either.
        unserialize('O:26:"NaijaCloud\Email\Naijamail":0:{}');
    }

    public function testAnErrorFromTheApiDoesNotCarryTheKey(): void
    {
        $transport = new FakeTransport([
            FakeTransport::json(401, ['statusCode' => 401, 'message' => 'invalid API key']),
        ]);
        $client = new Naijamail(self::KEY, ['transport' => $transport, 'max_retries' => 0]);

        try {
            $client->emails->send([
                'from' => 'hello@acme.com',
                'to' => 'customer@example.com',
                'subject' => 'Hi',
                'text' => 'Hello.',
            ]);
            self::fail('the 401 should have been raised');
        } catch (NaijamailException $e) {
            self::assertStringNotContainsString('needle00', $e->getMessage());
            self::assertStringNotContainsString('needle00', (string) $e);
            self::assertStringNotContainsString('needle00', print_r($e->getTrace(), true));
        }
    }

    /**
     * PHP puts function arguments in stack traces, so the constructor argument
     * itself is a leak path on any uncaught exception. 8.2+ redacts it through
     * the SensitiveParameter attribute; on 8.1 the argument is still there,
     * which is worth knowing rather than assuming.
     */
    public function testTheConstructorArgumentIsMarkedSensitive(): void
    {
        $parameter = (new \ReflectionMethod(Naijamail::class, '__construct'))->getParameters()[0];
        $attributes = array_map(
            static fn (\ReflectionAttribute $a): string => $a->getName(),
            $parameter->getAttributes(),
        );

        self::assertContains('SensitiveParameter', $attributes);
    }

    /**
     * The transport receives the built headers, Authorization included. PHP
     * records every argument of every frame in an exception's trace, which is
     * what error trackers serialize, so that parameter must be marked too.
     */
    public function testTheTransportHeadersParameterIsMarkedSensitive(): void
    {
        foreach ([\NaijaCloud\Email\Http\Transport::class, CurlTransport::class] as $class) {
            $parameter = (new \ReflectionMethod($class, 'send'))->getParameters()[2];
            self::assertSame('headers', $parameter->getName());
            $attributes = array_map(
                static fn (\ReflectionAttribute $a): string => $a->getName(),
                $parameter->getAttributes(),
            );
            self::assertContains('SensitiveParameter', $attributes, $class . '::send($headers)');
        }
    }

    /** End to end: a connection failure's trace must not hold the key (PHP 8.2+). */
    public function testAConnectionFailureTraceDoesNotCarryTheKey(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('SensitiveParameter redaction needs PHP 8.2');
        }
        $client = new Naijamail(self::KEY, ['base_url' => 'http://127.0.0.1:9', 'max_retries' => 0]);
        // PHP's own default: argument values are recorded in traces.
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $client->emails->get('x');
            self::fail('expected a connection failure');
        } catch (NaijamailException $e) {
            // A boolean, not assertStringNotContainsString: on failure that
            // would print the whole trace, key and all, into the test log.
            self::assertFalse(
                str_contains(print_r($e->getTrace(), true), 'needle'),
                'the exception trace holds the API key',
            );
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    public function testRedactionKeepsTheEnvironmentButNotTheSecret(): void
    {
        self::assertSame('nmail_live_***', (new Naijamail(self::KEY))->redactedApiKey());
        self::assertSame(
            'nmail_test_***',
            (new Naijamail('nmail_test_needle00'))->redactedApiKey(),
        );
    }
}
