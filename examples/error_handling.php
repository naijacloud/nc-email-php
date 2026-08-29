<?php

declare(strict_types=1);

/**
 * What to catch, and what to do about it.
 *
 *   NAIJAMAIL_API_KEY=nmail_live_... php examples/error_handling.php
 */

require __DIR__ . '/../vendor/autoload.php';

use NaijaCloud\Email\Exception\AuthenticationException;
use NaijaCloud\Email\Exception\ConnectionException;
use NaijaCloud\Email\Exception\NaijamailException;
use NaijaCloud\Email\Exception\PermissionException;
use NaijaCloud\Email\Exception\RateLimitException;
use NaijaCloud\Email\Exception\ServerException;
use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Naijamail;

$nm = new Naijamail();

try {
    $sent = $nm->emails->send([
        'from' => 'Acme <hello@acme.com>',
        'to' => 'customer@example.com',
        'subject' => 'Your receipt',
        'html' => '<p>Thanks.</p>',
        // Tie the key to the thing being mailed about, not to the attempt. A
        // queue job that runs twice then sends once.
        'idempotency_key' => 'receipt-order-1024',
    ]);

    printf("queued %s\n", $sent->id);
} catch (ValidationException $e) {
    // Either the SDK refused it locally (statusCode 0) or the API did. Both
    // mean the same thing: this message is not sendable as written, and
    // retrying it unchanged will fail the same way.
    fwrite(STDERR, "not sendable: {$e->getMessage()}\n");
} catch (AuthenticationException $e) {
    // Missing, malformed, unknown or revoked — the API answers all four the
    // same way so a probe cannot learn which one it hit.
    fwrite(STDERR, "check NAIJAMAIL_API_KEY: {$e->getMessage()}\n");
} catch (PermissionException $e) {
    // An unverified From domain, a test key on the live path, a paused domain,
    // or the daily quota. Never worth retrying as-is.
    fwrite(STDERR, "not allowed: {$e->getMessage()}\n");
} catch (RateLimitException $e) {
    // The SDK already retried within its budget; this means the limit outlasted
    // it. Requeue rather than spin.
    fwrite(STDERR, sprintf("rate limited, retry in %ds\n", $e->getRetryAfter() ?? 60));
} catch (ConnectionException | ServerException $e) {
    // Already retried three times. Requeue and alert if it persists; quote the
    // request id in a support ticket.
    fwrite(STDERR, sprintf(
        "naijamail is unhealthy (%s), request id %s\n",
        $e->statusCode,
        $e->requestId ?? 'none',
    ));
} catch (NaijamailException $e) {
    // The catch-all. Everything above extends this, so one clause is enough if
    // you do not need to tell the cases apart.
    fwrite(STDERR, "send failed: {$e->getMessage()}\n");
}
