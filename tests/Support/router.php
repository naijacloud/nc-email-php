<?php

declare(strict_types=1);

/**
 * The mock Naijamail API, served by `php -S`.
 *
 * A real socket and a real HTTP response, so the tests exercise CurlTransport
 * itself — TLS aside, this is the code that runs in production. The suite never
 * touches the network: everything here is 127.0.0.1 on an ephemeral port.
 *
 * The scenario is chosen by the base URL the client was built with
 * (`http://127.0.0.1:PORT/s/<scenario>`), so no test has to reach inside the
 * SDK to make the server behave a particular way.
 */

$stateDir = getenv('NM_MOCK_STATE') ?: sys_get_temp_dir();

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scenario = 'ok';
if (preg_match('#^/s/([^/]+)(/.*)$#', $path, $matches) === 1) {
    $scenario = $matches[1];
    $path = $matches[2];
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

$body = file_get_contents('php://input');

// Every request is recorded so a test can assert on what actually went out —
// the Idempotency-Key across retries, the User-Agent, the JSON body shape.
file_put_contents(
    $stateDir . '/requests.jsonl',
    json_encode([
        'scenario' => $scenario,
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => $path,
        'uri' => $_SERVER['REQUEST_URI'],
        'headers' => $headers,
        'body' => $body,
    ], JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND | LOCK_EX,
);

/** How many times this scenario has been hit, starting at 1. */
$hit = static function (string $scenario) use ($stateDir): int {
    $file = $stateDir . '/count-' . preg_replace('/[^a-z0-9\-]/i', '', $scenario);
    $count = is_file($file) ? (int) file_get_contents($file) : 0;
    $count++;
    file_put_contents($file, (string) $count, LOCK_EX);

    return $count;
};

$json = static function (int $status, array $payload, array $extraHeaders = []): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('x-request-id: req_test_00000001');
    foreach ($extraHeaders as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
};

$error = static function (int $status, string|array $message, string $label) use ($json): void {
    $json($status, ['statusCode' => $status, 'message' => $message, 'error' => $label]);
};

$accepted = static function (array $extra = []) use ($json): void {
    $json(202, ['id' => '5b1e0000-0000-4000-8000-000000000001', 'status' => 'queued'] + $extra);
};

$message = [
    'id' => '5b1e0000-0000-4000-8000-000000000001',
    'to' => 'customer@example.com',
    'from' => 'hello@acme.com',
    'subject' => 'Your receipt',
    'status' => 'delivered',
    'created_at' => '2026-08-29T10:00:00.000Z',
    'delivered_at' => '2026-08-29T10:00:04.000Z',
    'opened' => false,
    'clicked' => false,
];

$isSend = $_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/v1/emails';

switch ($scenario) {
    case 'ok':
        if ($isSend) {
            $accepted();
            break;
        }
        // An unknown field on the way out: an SDK that chokes on one is an SDK
        // that breaks the next time the platform ships before the client does.
        $json(200, $message + ['delivery_attempts' => 2]);
        break;

    case 'rejected':
        $accepted(['rejected' => [['address' => 'blocked@example.com', 'reason' => 'suppressed']]]);
        break;

    case 'queued-message':
        $json(200, ['id' => $message['id'], 'to' => 'x@y.com', 'from' => 'hello@acme.com',
            'subject' => '', 'status' => 'queued', 'created_at' => '2026-08-29T10:00:00.000Z',
            'delivered_at' => null, 'opened' => false, 'clicked' => false]);
        break;

    case 'failed-message':
        $json(200, ['id' => $message['id'], 'to' => 'x@y.com', 'from' => 'hello@acme.com',
            'subject' => 'Hi', 'status' => 'bounced', 'created_at' => '2026-08-29T10:00:00.000Z',
            'delivered_at' => null, 'opened' => true, 'clicked' => true,
            'failure_reason' => '550 5.1.1 user unknown']);
        break;

    case 'unknown-status':
        $json(200, ['id' => $message['id'], 'to' => 'x@y.com', 'from' => 'hello@acme.com',
            'subject' => 'Hi', 'status' => 'quarantined', 'created_at' => 'not-a-date',
            'delivered_at' => null, 'opened' => false, 'clicked' => false]);
        break;

    case 'err-400':
        $error(400, '"to" is required', 'Bad Request');
        break;

    case 'err-400-notfound':
        $error(400, 'message not found', 'Bad Request');
        break;

    case 'err-400-array':
        $error(400, ['"from" is required', 'subject must be a string'], 'Bad Request');
        break;

    case 'err-401':
        $error(401, 'invalid API key', 'Unauthorized');
        break;

    case 'err-403':
        $error(403, 'this is a test key — it cannot send real email. Use a live key.', 'Forbidden');
        break;

    case 'err-404':
        $error(404, 'not found', 'Not Found');
        break;

    case 'err-408':
        $error(408, 'request timeout', 'Request Timeout');
        break;

    case 'err-409':
        $error(409, 'idempotency key reused with a different body', 'Conflict');
        break;

    case 'err-422':
        $error(422, 'unprocessable', 'Unprocessable Entity');
        break;

    case 'err-429':
        $json(429, ['statusCode' => 429, 'message' => 'Too many requests'], ['Retry-After' => '7']);
        break;

    case 'err-429-bare':
        $json(429, ['statusCode' => 429, 'message' => 'Too many requests']);
        break;

    case 'err-500':
        $error(500, 'Internal server error', 'Internal Server Error');
        break;

    case 'err-502-html':
        http_response_code(502);
        header('Content-Type: text/html');
        echo '<html><head><title>502 Bad Gateway</title></head><body>nginx</body></html>';
        break;

    case 'ok-nonjson':
        http_response_code(200);
        header('Content-Type: text/html');
        echo '<html>we are down for maintenance</html>';
        break;

    case 'redirect':
        http_response_code(302);
        header('Location: https://evil.example.com/v1/emails');
        break;

    case 'retry-429-seconds':
        if ($hit($scenario) === 1) {
            $json(429, ['statusCode' => 429, 'message' => 'Too many requests'], ['Retry-After' => '3']);
            break;
        }
        $accepted();
        break;

    case 'retry-429-date':
        if ($hit($scenario) === 1) {
            $json(
                429,
                ['statusCode' => 429, 'message' => 'Too many requests'],
                ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 4) . ' GMT'],
            );
            break;
        }
        $accepted();
        break;

    case 'retry-twice':
        // Fails the first two attempts, so a default client (three attempts)
        // succeeds and every attempt is on the record.
        $count = $hit($scenario);
        if ($count === 1) {
            $json(429, ['statusCode' => 429, 'message' => 'Too many requests'], ['Retry-After' => '0']);
            break;
        }
        if ($count === 2) {
            $error(503, 'Service Unavailable', 'Service Unavailable');
            break;
        }
        $accepted();
        break;

    case 'always-500':
        $hit($scenario);
        $error(500, 'Internal server error', 'Internal Server Error');
        break;

    case 'always-400':
        $hit($scenario);
        $error(400, '"from" is required', 'Bad Request');
        break;

    case 'always-403':
        $hit($scenario);
        $error(403, 'not allowed to send from "x@y.com". Verify the domain first.', 'Forbidden');
        break;

    case 'slow':
        // Longer than any timeout the tests configure, so the client aborts.
        usleep(1_500_000);
        $accepted();
        break;

    default:
        $error(400, 'unknown mock scenario: ' . $scenario, 'Bad Request');
}

return true;
