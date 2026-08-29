# Contributing

## What this package is

One of several SDKs implementing `email-sdks/spec/SDK-CONTRACT.md`. The wire
format, the error taxonomy, the retry policy and the security rules are the same
in every language, so that a bug fixed in one is a bug fixed in all and a
customer moving from Node to PHP rewrites syntax rather than behaviour.

If the contract and this code disagree, the contract wins — unless the *server*
disagrees with the contract, in which case the server wins and the contract gets
fixed.

## Getting set up

```bash
composer install
vendor/bin/phpunit
```

PHP 8.1 or newer, with `ext-curl`, `ext-json` and `ext-mbstring`. There are no
runtime dependencies and there should never be any: this package holds a live
sending credential, and every dependency is another supply chain that can reach
it. Dev dependencies are unrestricted.

## Tests

**The suite runs offline.** It must stay that way — a test that needs the
network is a test that fails in someone's CI for reasons unrelated to their
change.

Two kinds:

- `tests/Support/MockServer.php` spawns `php -S` on an ephemeral loopback port
  and serves `tests/Support/router.php`. The real `CurlTransport` talks to it, so
  the no-redirect rule, the timeout options, header parsing and the retry loop
  are exercised as they run in production. The scenario is chosen through the
  base URL (`http://127.0.0.1:PORT/s/<scenario>`), so no test reaches inside the
  SDK to make the server behave.
- `tests/Support/FakeTransport.php` scripts responses for what a socket cannot
  produce on demand: a DNS failure, a timeout at an exact attempt, and the
  validation cases, where `$transport->sends === []` is the assertion that
  nothing left the process.

Adding a behaviour means adding a scenario to the router and a test that names
the reason it exists. A test whose name restates the method it calls is not
worth its runtime.

## House style

- `declare(strict_types=1)` in every file. PSR-12, PSR-4 (`NaijaCloud\Email\` →
  `src/`).
- Comments explain **why**: the decision, the failure it prevents, or the trap it
  avoids. A comment restating the code is noise and will be removed in review.
- No emoji, anywhere.
- Anything under `src/Internal/` and `src/Http/` is `@internal` and carries no
  backwards-compatibility promise. The public surface is `Naijamail`, `Emails`,
  `Webhooks`, `MessageStatus`, `Limits`, and the `Model` and `Exception`
  namespaces.

## Before you open a pull request

```bash
composer install
find src tests examples -name '*.php' -exec php -l {} \;
vendor/bin/phpunit
```

CI runs the suite on PHP 8.1, 8.2, 8.3 and 8.4. All four must pass.

## Releasing

1. `Naijamail::VERSION` and the `version` field in `composer.json` are the same
   string. Change both.
2. Move the `Unreleased` entries in `CHANGELOG.md` under the new version with a
   date.
3. Tag `vX.Y.Z`. Packagist picks it up from the tag.

`composer.lock` is deliberately not committed: this is a library, a consumer
resolves against their own lock, and pinning here would only hide the fact that
CI must exercise the dependency range the package actually claims to support.

## Security

Do not report a vulnerability through an issue or a pull request. See
[SECURITY.md](SECURITY.md).
