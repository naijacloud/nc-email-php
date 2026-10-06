<p align="center">
  <a href="https://www.naijacloud.com">
    <img alt="Naijamail — PHP SDK" src="https://raw.githubusercontent.com/naijacloud/nc-email-php/main/.github/assets/banner.png" width="100%">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/naijacloud/email"><img alt="packagist" src="https://img.shields.io/badge/packagist-naijacloud%2Femail-008751?style=flat-square&labelColor=0A0E0C"></a>
  <img alt="php" src="https://img.shields.io/badge/php-%3E%3D_8.1-8892BF?style=flat-square&labelColor=0A0E0C">
  <img alt="dependencies" src="https://img.shields.io/badge/dependencies-ext_only-46C98A?style=flat-square&labelColor=0A0E0C">
  <a href="LICENSE"><img alt="license" src="https://img.shields.io/badge/license-MIT-8A988F?style=flat-square&labelColor=0A0E0C"></a>
</p>

<p align="center">
  <a href="#quickstart">Quickstart</a> ·
  <a href="#sending">Sending</a> ·
  <a href="#options">Options</a> ·
  <a href="#errors">Errors</a> ·
  <a href="#retries">Retries</a> ·
  <a href="#webhooks">Webhooks</a> ·
  <a href="#laravel">Laravel</a> ·
  <a href="#security">Security</a>
</p>

# nc-email-php

The official PHP SDK for **Naijamail**, the transactional email API of
[Naija Cloud](https://www.naijacloud.com).

Two endpoints, no runtime dependencies, and a webhook verifier.

```bash
composer require naijacloud/email
```

## Quickstart

```php
use NaijaCloud\Email\Naijamail;

$nm = new Naijamail();                       // reads NAIJAMAIL_API_KEY

$sent = $nm->emails->send([
    'from' => 'Acme <hello@acme.com>',
    'to' => 'customer@example.com',
    'subject' => 'Your receipt',
    'html' => '<p>Thanks.</p>',
]);

echo $sent->id;                              // 5b1e0000-...
echo $nm->emails->get($sent->id)->status;    // queued | delivered | bounced | ...
```

A successful `send()` means the message was accepted and queued — it passed
authorisation, suppression, reputation and quota checks. It does not mean a
mailbox has it. Use `get()` or a webhook for that.

## Requirements

PHP 8.1+ with `ext-curl`, `ext-json` and `ext-mbstring`. Nothing else: this
package holds a live sending credential, and every third-party runtime
dependency is one more supply chain that can reach it.

PHP 8.2 or newer is recommended. The key is marked `#[\SensitiveParameter]`
wherever it is passed, which keeps it out of exception stack traces (what Sentry
and Monolog record); PHP 8.1 ignores that attribute, so on 8.1 set
`zend.exception_ignore_args=On` in production.

## Sending

```php
$sent = $nm->emails->send([
    'from' => 'Acme <hello@acme.com>',        // required, domain must be verified
    'to' => ['a@example.com', 'b@example.com'],
    'cc' => 'finance@acme.com',
    'bcc' => 'archive@acme.com',
    'reply_to' => 'support@acme.com',
    'subject' => 'Invoice #1024',
    'html' => '<p>Attached.</p>',
    'text' => 'Attached.',
    'headers' => ['X-Campaign' => 'invoices'],
    'attachments' => [[
        'filename' => 'invoice-1024.pdf',
        'content' => file_get_contents('/tmp/invoice.pdf'),   // raw bytes
        'content_type' => 'application/pdf',
    ]],
    'tags' => ['campaign' => 'invoices'],
    'idempotency_key' => 'invoice-1024',      // optional; generated if omitted
]);
```

`to`, `cc`, `bcc` and `reply_to` each take a string or an array of strings.

`idempotency_key` travels in the `Idempotency-Key` header only. An empty or
blank key is treated as omitted (one is generated); a supplied key is at most
255 bytes of UTF-8 and may not contain CR, LF or NUL.

**Attachments take raw bytes.** A PHP string *is* the byte type, so `content`
is always the raw file contents (`file_get_contents()`), never base64 — the SDK
base64-encodes it once on the way out. An empty attachment is refused locally,
as the server would refuse it. It will never
accept a file path and read it for you — an SDK that opens whatever path it is
handed becomes a file-disclosure bug the first time a request-supplied filename
reaches it.

### Rejected recipients

`$sent->rejected` is always an array, empty when nobody was refused. A rejection
is not a failure — the rest of the message still went out — and re-queueing to a
suppressed address is how a sending reputation is lost.

```php
foreach ($sent->rejected as $recipient) {
    $log->info("not sent to {$recipient->address}: {$recipient->reason}");
}
```

### Retrieving a message

`$nm->emails->get($id)` returns an `Email`. `createdAt` and `deliveredAt` are
`?DateTimeImmutable` (parsed from the API's ISO 8601 strings; `deliveredAt` is
null until delivery) — the other SDKs use their own language's date type, which
is a deliberate difference.

### Statuses

`queued`, `sent`, `delivered`, `bounced`, `deferred`, `complained`, `rejected`,
`failed` — as constants on `MessageStatus`. A status this release has never seen
passes through as a plain string rather than throwing, so a platform change does
not break an already-deployed application:

```php
use NaijaCloud\Email\MessageStatus;

if ($email->status === MessageStatus::BOUNCED) { /* ... */ }
if (!MessageStatus::isKnown($email->status))   { /* newer than this SDK */ }
```

## Options

```php
$nm = new Naijamail($apiKey, [
    'base_url' => 'https://api.naijacloud.com',  // or env NAIJAMAIL_BASE_URL
    'timeout' => 30.0,                           // seconds, per attempt
    'max_retries' => 2,                          // retries after the first try
    'user_agent_suffix' => 'acme-billing/2.1',
]);
```

### Which key

Two kinds work, and the SDK cannot tell them apart once it has one:

- **`nc_live_…`** — a workspace API key from **Settings → API keys**, ticked for
  the **Email send** scope. Most teams already have one: it is the same
  credential CI deploys with. Add **Platform API** as well if the key also needs
  to manage sending domains or suppressions.
- **`nmail_live_…` / `nmail_test_…`** — a Naijamail-only key from **Email**. The
  test variant is **sandboxed**: the API accepts the send, returns a real id
  and a final status, and never hands the message to a mail server. Use one in
  staging and CI. Send from any domain you have added, or from
  `…@test.mail.naijacloud.dev`; send *to* `delivered@`, `bounced@` or
  `complained@test.mail.naijacloud.dev` to get that outcome. A message sent
  this way comes back from `get` with `sandbox` set to true. There is no test
  variant of a workspace key.

An `nc_pat_…` platform token is not accepted: those predate the Email send scope
and the API refuses them on the mail routes, so the SDK refuses them at
construction rather than a request later, with a message saying it is a
personal access token and which keys to use instead.

The key comes from the first of: the constructor argument, `NAIJAMAIL_API_KEY`.
Surrounding whitespace (a trailing newline from a secrets file) is trimmed.
An absent or malformed key is an exception at construction, not a 401 an hour
later in production.

The base URL comes from the `base_url` option, else `NAIJAMAIL_BASE_URL`; a
blank `NAIJAMAIL_BASE_URL` counts as unset. A base URL with a query string or
fragment is refused. `timeout` must be greater than zero and `max_retries` an
integer from 0 to 10.

An unknown option is rejected rather than ignored: a silently dropped
`'timout' => 5` is a client that waits 30 seconds in production and gives you no
way to see why.

## Errors

Everything the SDK raises extends `NaijamailException`, so one `catch` covers
the lot — including the errors raised locally, before a request leaves the
process, which carry `statusCode === 0`.

| HTTP | Exception | Retried |
| --- | --- | --- |
| 400 | `ValidationException` (`NotFoundException` when the message is `message not found`) | no |
| 401 | `AuthenticationException` | no |
| 403 | `PermissionException` | no |
| 404 | `NotFoundException` | no |
| 408 | `TimeoutException` | yes |
| 409 | `ConflictException` | no |
| 422 | `ValidationException` | no |
| any other 4xx (405, 413, 415, 451…) | `ValidationException` | no |
| 429 | `RateLimitException` (`getRetryAfter()`) | yes |
| 3xx | `ServerException` ("unexpected redirect") | no |
| 5xx | `ServerException` | yes |
| 2xx that is not a JSON object, or a send response with no `id` | `ServerException` ("malformed response") | no |
| DNS / TCP / TLS | `ConnectionException` | yes |
| client-side deadline | `TimeoutException` | yes |
| bad input, caught locally | `ValidationException` | no |

Every one carries `statusCode`, `errorLabel` (the server's short label),
`requestId` (from `x-request-id` — quote it in a support ticket), the raw
response text (`getRawBody()`, also `body`/`getBody()`) and the parsed JSON body
(`getParsedBody()`, null when the body was not a JSON object).

```php
use NaijaCloud\Email\Exception\{NaijamailException, PermissionException, RateLimitException};

try {
    $nm->emails->send($message);
} catch (PermissionException $e) {
    // Unverified domain, a key without the right scope, or the daily quota.
    $log->error($e->getMessage(), ['request_id' => $e->requestId]);
} catch (RateLimitException $e) {
    $queue->later($e->getRetryAfter() ?? 60, $job);
} catch (NaijamailException $e) {
    $log->error('naijamail: ' . $e->getMessage(), ['status' => $e->statusCode]);
}
```

**A retrieve for an id that does not exist currently answers 400, not 404.** The
SDK maps that one case to `NotFoundException` so you do not have to know.

## Retries

Three attempts by default (one try, two retries), 30s per attempt.

Retried: `429`, `408`, any `5xx`, and connection or timeout failures. Never any
other `4xx` — a 403 on an unverified domain will not verify itself between
attempts, and retrying only burns your rate limit.

Backoff is exponential with full jitter (`random(0, min(8s, 0.5s * 2^attempt))`).
`Retry-After` overrides it on any retried response that carries it (a 429, a
503 during a drain), in either the integer-seconds or HTTP-date form, clamped to
60s so a mis-set header cannot park a worker for an hour.
`RateLimitException::getRetryAfter()` is clamped the same way.

The timeout is a deadline for the whole attempt — connect, upload and the full
response — not a per-read timeout. Each retry gets a fresh one.

This is safe because of the idempotency key: if you do not supply one, the SDK
generates a UUIDv4 **once per `send()` call** and sends it on every attempt of
that call. Without it, a timeout followed by a retry mails your customer twice.

## Security

The rules this package is held to, all of them tested:

- **HTTPS enforced.** A base URL that is not `https` is refused at construction.
  Only `localhost`, `127.0.0.1` and `::1` may use `http`, for local development
  against a dev control plane.
- **Redirects are never followed.** A followed redirect re-sends your
  `Authorization` header to whatever host the response names. A 3xx surfaces as
  `ServerException`.
- **The key never leaves the SDK except in the auth header.** It is not in
  `var_dump()`, `print_r()`, `var_export()`, `json_encode()`, exception
  messages, stack traces or the User-Agent — it prints as `nmail_live_***`. The
  client refuses to be serialized: a client in a session file or a queue payload
  is a credential in storage that outlives the process.
- **Header injection is refused locally.** CR, LF and NUL are rejected in
  `from`, every address, `subject`, custom header names and values, the
  idempotency key, and attachment filenames, content types and content ids.
- **Custom `from`, `to`, `cc`, `bcc`, `subject`, `dkim-signature` and `received`
  headers are refused** — overriding them would sidestep the domain
  authorisation your From address is checked against. The check runs on the
  trimmed name, so `" From"` is refused too.
- **Client-side limits**, so an impossible message fails without a round trip:
  50 recipients across to+cc+bcc, 10 MiB of message (html + text + raw
  attachment bytes, measured the way the server measures it — not the base64
  JSON), 25 custom headers, 10 tags.
- **No global state.** Two clients with two keys in one process cannot interfere.

Found a problem? See [SECURITY.md](SECURITY.md). Do not open a public issue.

## Laravel

Laravel is the common case in this market, but a separate package would be one
more thing to keep in step with the API. Bind the client yourself — it is six
lines.

`config/services.php`:

```php
'naijamail' => [
    'key' => env('NAIJAMAIL_API_KEY'),
    'from' => env('NAIJAMAIL_FROM', 'Acme <hello@acme.com>'),
],
```

`app/Providers/AppServiceProvider.php`:

```php
use NaijaCloud\Email\Naijamail;

public function register(): void
{
    // Singleton: one client per process, resolved from config rather than from
    // the environment directly, so `php artisan config:cache` keeps working.
    $this->app->singleton(Naijamail::class, fn () => new Naijamail(
        config('services.naijamail.key'),
        ['user_agent_suffix' => 'laravel/' . app()->version()],
    ));
}
```

Then inject it anywhere:

```php
public function __invoke(Naijamail $nm, Order $order)
{
    $nm->emails->send([
        'from' => config('services.naijamail.from'),
        'to' => $order->customer->email,
        'subject' => "Receipt for order #{$order->id}",
        'html' => view('mail.receipt', compact('order'))->render(),
        // The order id, so a retried queue job cannot mail the customer twice.
        'idempotency_key' => "receipt-{$order->id}",
    ]);
}
```

Do not put the client in the session, in a cached job payload, or anywhere else
that serializes objects: it will throw, on purpose. Inject it instead.

## Webhooks

> **Live.** Naija Cloud delivers these events to endpoints you register, signed
> exactly as below. Two details this verifier already handles: the timestamp is
> taken per delivery *attempt*, so a retry never arrives outside the tolerance
> window; and during a secret rotation the header carries two `v1=` values for
> 24 hours, which is why any match is accepted.

```php
use NaijaCloud\Email\Webhooks;
use NaijaCloud\Email\Exception\WebhookVerificationException;

try {
    $event = Webhooks::verify(
        $request->getContent(),                    // raw body, byte for byte
        $request->header('NC-Signature'),
        config('services.naijamail.webhook_secret'),
    );
} catch (WebhookVerificationException $e) {
    return response('', 400);
}

match ($event->type) {
    'email.delivered' => /* ... */,
    'email.bounced' => /* ... */,
    default => null,
};
```

Pass the **raw** body. `json_encode(json_decode($body))` reorders keys and
changes spacing, and the signature is over the bytes that were actually sent.

Verification rejects a bad signature, and also a timestamp more than 300 seconds
from now — a captured delivery carries a signature that is genuinely ours and
would otherwise verify forever. Pass a different `$tolerance` as the fourth
argument; `0` is strict (only the current second passes), and a negative value
is a `ValidationException`. The `t=` value must be 1–12 digits; the hex
signature is compared case-insensitively; and a verified payload that is not a
JSON object (an array, a string) is rejected.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). The test suite runs offline against a
local mock server; there is no way to point it at production.

## Licence

MIT. See [LICENSE](LICENSE).
