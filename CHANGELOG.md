# Changelog

All notable changes to this package are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-10-04

The first version published to Packagist
(`composer require naijacloud/email`). 0.1.0 was written up here but never
published, so its entries below are part of this release too.

### Added

- Accept a workspace API key (`nc_live_…`) alongside the Naijamail keys. It is
  the credential from **Settings → API keys**, and it reaches the mail API when
  it carries the **Email send** scope — so a team that already has one for
  deploys and the platform API does not need a second secret to send mail.
  Redaction knows the new prefix, so a dump still shows which kind of credential
  a process is holding. `nc_pat_…` platform tokens remain refused: they predate
  the scope and the API rejects them on the mail routes.
- `Email::$sandbox` on a retrieved email: true for a message sent with a test key,
  which is recorded but never delivered, so a simulated bounce can be told from
  a real one.

### Fixed

- The transport's `$headers` argument is marked `#[\SensitiveParameter]`, so the
  API key no longer appears in the stack trace of a connection or timeout
  exception (PHP 8.2+; on 8.1 PHP ignores the attribute).
- Tag length is counted in UTF-16 units, the way the server counts it; emoji
  tags the server would truncate are refused.
- Test keys (`nmail_test_…`) are sandboxed by the API, not refused with a 403.
  The README said otherwise.

## 0.1.0 - 2026-08-29 (never published)

First release. Implements the Naijamail SDK contract for PHP 8.1+.

### Added

- `Naijamail` client, configured from `NAIJAMAIL_API_KEY` / `NAIJAMAIL_BASE_URL`
  or from constructor options (`base_url`, `timeout`, `max_retries`,
  `user_agent_suffix`).
- `$nm->emails->send()` and `$nm->emails->get()` — the two endpoints the API has.
- Typed responses (`SendEmailResponse`, `Email`, `RejectedRecipient`) that ignore
  unknown fields, so a platform release cannot break a deployed SDK. `rejected`
  is always present, empty when nobody was refused.
- `MessageStatus` constants with passthrough for statuses newer than this
  release.
- One exception hierarchy under `NaijamailException`, carrying `statusCode`,
  `errorLabel`, `requestId` and the raw `body`. The retrieve endpoint's
  400-for-missing-message quirk is mapped to `NotFoundException`.
- Retries on 429, 408, 5xx and transport failures: three attempts by default,
  exponential backoff with full jitter, `Retry-After` honoured in both its
  integer-seconds and HTTP-date forms and clamped to 60s.
- An idempotency key generated once per `send()` call and reused across every
  attempt of that call, which is what makes retrying a send safe.
- `Webhooks::verify()` for the `NC-Signature` scheme, with a constant-time
  comparison and a replay window. Naija Cloud emits these events; the scheme
  is shared with every other Naijamail SDK, so all of them verify identically.
- Security rules enforced in code and in tests: HTTPS-only base URLs (loopback
  excepted), no redirect following, key redaction across every dump function, a
  non-serializable client, header-injection rejection, forbidden custom headers,
  and the client-side sending limits.

[Unreleased]: https://github.com/naijacloud/nc-email-php/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/naijacloud/nc-email-php/releases/tag/v0.2.0
