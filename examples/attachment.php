<?php

declare(strict_types=1);

/**
 * Sending an attachment, and referencing one inline from the HTML.
 *
 *   NAIJAMAIL_API_KEY=nmail_live_... php examples/attachment.php
 */

require __DIR__ . '/../vendor/autoload.php';

use NaijaCloud\Email\Naijamail;

$nm = new Naijamail();

// Generated here so the example runs anywhere; in your application these bytes
// come from wherever you build the document.
$invoice = "%PDF-1.4\n% a real invoice would go here\n";
$logo = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true,
);

$sent = $nm->emails->send([
    'from' => 'Acme <billing@acme.com>',
    'to' => 'customer@example.com',
    'subject' => 'Invoice #1024',
    // content_id makes the attachment addressable from the HTML as cid:logo,
    // which is how an image reaches a client that blocks remote loads.
    'html' => '<p><img src="cid:logo" alt="Acme"></p><p>Your invoice is attached.</p>',
    'text' => 'Your invoice is attached.',
    'attachments' => [
        [
            'filename' => 'invoice-1024.pdf',
            // Raw bytes. The SDK base64-encodes them; encoding by hand is how
            // an invoice arrives double-encoded and unopenable.
            'content' => $invoice,
            'content_type' => 'application/pdf',
        ],
        [
            'filename' => 'logo.png',
            'content' => (string) $logo,
            'content_type' => 'image/png',
            'content_id' => 'logo',
        ],
    ],
    'tags' => ['campaign' => 'invoices'],
]);

printf("queued %s\n", $sent->id);

// The SDK will never take a path and read it for you. Read it yourself, so the
// decision about which files this process may open stays in your code:
//
//     'content' => file_get_contents($safePathYouChose),
