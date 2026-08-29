<?php

declare(strict_types=1);

/**
 * The 10-line quickstart.
 *
 *   NAIJAMAIL_API_KEY=nmail_live_... php examples/send.php you@example.com
 */

require __DIR__ . '/../vendor/autoload.php';

use NaijaCloud\Email\Naijamail;

$recipient = $argv[1] ?? 'customer@example.com';

// No key in the file, and none in your shell history either: export it, or put
// it in a .env your shell sources. A key pasted into an example is a key in a
// screenshot six months from now.
$nm = new Naijamail();

$sent = $nm->emails->send([
    'from' => 'Acme <hello@acme.com>',
    'to' => $recipient,
    'subject' => 'Your receipt',
    'html' => '<p>Thanks for your order.</p>',
    'text' => 'Thanks for your order.',
]);

printf("queued %s (%s)\n", $sent->id, $sent->status);

// Present only when the platform refused someone — an address on the team's
// suppression list. Not an error: everyone else still got the message.
foreach ($sent->rejected as $rejected) {
    printf("not sent to %s: %s\n", $rejected->address, $rejected->reason);
}

// A 202 means queued, not delivered. This is the state a moment later.
$email = $nm->emails->get($sent->id);
printf("%s is %s\n", $email->id, $email->status);
