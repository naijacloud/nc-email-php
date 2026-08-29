# Changelog

All notable changes to this package are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-08-29

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

[Unreleased]: https://github.com/naija-cloud/nc-email-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/naija-cloud/nc-email-php/releases/tag/v0.1.0
