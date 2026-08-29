# Security policy

This package holds live sending credentials. Treat a problem in it as you would
a problem in an authentication library.

## Reporting a vulnerability

Email **security@naijacloud.com**. Include what you found, how to reproduce it,
and what an attacker gets from it. If you have a proof of concept, send it — we
will not act on the report as an attack.

Please do **not** open a public issue, a pull request that fixes it quietly, or
a discussion thread. A public patch is a disclosure.

We aim to acknowledge within two working days (West Africa Time, UTC+1) and to
have either a fix or a dated plan within ten. We will tell you when the fix
ships and credit you in the changelog unless you would rather we did not.

## Supported versions

The latest minor release is supported. While the package is pre-1.0 that means
0.1.x; security fixes are released as a patch and noted in `CHANGELOG.md`.

## What this package guarantees

These are enforced in code and covered by tests. A break in any of them is a
security bug, not a feature request:

- The API key is sent only in the `Authorization` header of a request to the
  configured base URL. It never appears in `var_dump()`, `print_r()`,
  `var_export()`, `json_encode()`, exception messages, stack traces (on PHP 8.2+,
  via `SensitiveParameter`), or the User-Agent.
- The client cannot be serialized. `serialize()` throws rather than writing a key
  into a session file, a cache entry or a queue payload.
- A base URL that is not HTTPS is refused at construction, except on loopback.
  libcurl is separately restricted to the HTTPS protocol, so even a malformed URL
  cannot put the key on a cleartext socket.
- Redirects are never followed, so the `Authorization` header is never re-sent to
  a host the response chose.
- TLS verification (`CURLOPT_SSL_VERIFYPEER`, `CURLOPT_SSL_VERIFYHOST`) is on and
  is not configurable. There is no "insecure" option to reach for at 2am.
- CR, LF and NUL are rejected in every field that becomes a mail header.
- Webhook signatures are compared with `hash_equals()` and are subject to a
  replay window. A verification failure never returns the expected signature.

## What it does not guarantee

- The bytes you pass as `html`, `text` or an attachment are sent as given. If
  they contain something you did not intend, that is your application's problem
  to catch, not this SDK's.
- Nothing here protects a key that is committed to a repository, printed by your
  own logging, or handed to a third-party error reporter. Redaction covers this
  package's own output.

## Handling keys

Read the key from the environment or a secret manager. A live key is
`nmail_live_...`; a test key is `nmail_test_...` and is refused by the live send
path with a 403, on purpose, so a staging box holding production credentials
fails loudly instead of mailing real customers.

Never commit a key. The literal `nmail_live_test0000000000000000` used in this
repository's tests is not a key and never was.
