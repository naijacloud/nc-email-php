<?php

declare(strict_types=1);

/**
 * Receiving a Naijamail webhook in Laravel.
 *
 * NOT RUNNABLE AS A SCRIPT — this is the controller and route to copy into an
 * application, and the control plane does not emit these webhooks yet. The
 * signature scheme is fixed ahead of the server so both sides ship against one
 * definition; nothing sends NC-Signature today.
 */

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NaijaCloud\Email\Exception\WebhookVerificationException;
use NaijaCloud\Email\Webhooks;

// routes/web.php
//
//     Route::post('/webhooks/naijamail', NaijamailWebhookController::class)
//         ->withoutMiddleware([VerifyCsrfToken::class]);
//
// config/services.php
//
//     'naijamail' => [
//         'key' => env('NAIJAMAIL_API_KEY'),
//         'webhook_secret' => env('NAIJAMAIL_WEBHOOK_SECRET'),
//     ],

final class NaijamailWebhookController
{
    public function __invoke(Request $request): Response
    {
        try {
            $event = Webhooks::verify(
                // getContent(), not $request->all(). The signature is over the
                // bytes that were sent: re-encoding a decoded array reorders
                // keys and changes spacing, and nothing would ever verify.
                $request->getContent(),
                (string) $request->header('NC-Signature'),
                (string) config('services.naijamail.webhook_secret'),
            );
        } catch (WebhookVerificationException $e) {
            // 400, not 500: a 5xx tells the sender to retry, and a request that
            // does not verify will not verify on the second attempt either.
            report($e);

            return response('', 400);
        }

        // Do the work in a job. A webhook endpoint that does its work inline
        // times out under a burst, and the sender retries what did not answer —
        // which is how one bounce becomes four.
        ProcessNaijamailEvent::dispatch($event->type, $event->id, $event->data);

        return response('', 204);
    }
}
