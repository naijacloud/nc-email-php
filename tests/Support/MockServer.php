<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Tests\Support;

use RuntimeException;

/**
 * A real HTTP server on 127.0.0.1, spawned with `php -S`.
 *
 * The point is to exercise CurlTransport rather than mock it away: no-redirect,
 * the timeout options, header parsing and the retry loop are all things that
 * only a real socket proves. Nothing here leaves the loopback interface, so the
 * suite runs with no network.
 */
final class MockServer
{
    /** @var resource */
    private $process;

    private function __construct(
        $process,
        public readonly int $port,
        public readonly string $stateDir,
    ) {
        $this->process = $process;
    }

    public static function start(): self
    {
        $port = self::freePort();
        $stateDir = sys_get_temp_dir() . '/nm-mock-' . $port . '-' . getmypid();
        if (!is_dir($stateDir) && !mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
            throw new RuntimeException('could not create the mock server state directory');
        }

        $command = sprintf(
            '%s -d error_reporting=E_ALL -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg(__DIR__ . '/router.php'),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stateDir . '/server.out', 'a'],
            2 => ['file', $stateDir . '/server.err', 'a'],
        ];

        $env = getenv();
        $env['NM_MOCK_STATE'] = $stateDir;

        $process = proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('could not start the mock HTTP server');
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $server = new self($process, $port, $stateDir);
        $server->waitUntilListening();

        return $server;
    }

    /**
     * @param string $scenario One of the cases in router.php. The scenario rides in
     *                         the base URL so that no test has to reach into the SDK.
     */
    public function baseUrl(string $scenario = ''): string
    {
        $base = 'http://127.0.0.1:' . $this->port;

        return $scenario === '' ? $base : $base . '/s/' . $scenario;
    }

    /** A port nothing is listening on, for the connection-refused case. */
    public static function deadPort(): int
    {
        return self::freePort();
    }

    /**
     * Every request the server has seen since the last reset.
     *
     * @return list<array{scenario:string,method:string,path:string,uri:string,
     *                       headers:array<string,string>,body:string}>
     */
    public function requests(): array
    {
        $file = $this->stateDir . '/requests.jsonl';
        if (!is_file($file)) {
            return [];
        }

        $log = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $log[] = $decoded;
            }
        }

        return $log;
    }

    /**
     * @return array<string,mixed> The decoded JSON body of the nth request (0-based).
     */
    public function requestBody(int $index = 0): array
    {
        $requests = $this->requests();
        $decoded = json_decode($requests[$index]['body'] ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }

    public function reset(): void
    {
        // Two globs rather than GLOB_BRACE, which is not compiled in on every
        // libc the CI matrix might run on.
        $files = array_merge(
            glob($this->stateDir . '/requests.jsonl') ?: [],
            glob($this->stateDir . '/count-*') ?: [],
        );

        foreach ($files as $file) {
            @unlink($file);
        }
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            // No explicit signal: SIGTERM is defined by ext-pcntl, which is not
            // guaranteed to be loaded, and it is proc_terminate's default anyway.
            proc_terminate($this->process);
            proc_close($this->process);
        }

        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stateDir);
    }

    /**
     * Bind to port 0, let the OS name a free port, then release it. There is a
     * race between releasing and the server binding, but on a loopback
     * interface in a test run it is the least bad option — scanning a fixed
     * range collides with whatever else the developer has running.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('could not reserve a port: ' . $error);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
        if ($port <= 0) {
            throw new RuntimeException('could not determine a free port');
        }

        return $port;
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $connection = @stream_socket_client(
                'tcp://127.0.0.1:' . $this->port,
                $errno,
                $error,
                0.2,
            );

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        $stderr = @file_get_contents($this->stateDir . '/server.err') ?: '';
        $this->stop();

        throw new RuntimeException(
            'the mock HTTP server did not start within 10s. ' . $stderr,
        );
    }
}
