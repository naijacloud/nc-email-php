<?php

declare(strict_types=1);

namespace NaijaCloud\Email;

use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Exception\WebhookVerificationException;
use NaijaCloud\Email\Internal\Json;
use NaijaCloud\Email\Model\WebhookEvent;
use SensitiveParameter;

/**
 * Verify a Naijamail webhook.
 *
 * Header: `NC-Signature: t=1756468800,v1=<hex sha256 hmac>`, where the signed
 * payload is `"<t>.<raw body bytes>"` and the secret is the endpoint's
 * `nmail_whsec_...`.
 *
 * The control plane signs every event webhook delivery this way. During a
 * secret rotation the header carries two `v1=` values for 24 hours; either
 * matching is enough.
 */
final class Webhooks
{
    /**
     * Default replay window. Five minutes is long enough to survive a receiver
     * whose clock drifts and a retry queue that hesitates, short enough that a
     * captured request is worthless by the time it is replayed.
     */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    public const SIGNATURE_HEADER = 'NC-Signature';

    private function __construct()
    {
    }

    /**
     * @param string $payload   The raw request body, byte for byte. Never a re-encoded
     *                          array: `json_encode(json_decode($body))` reorders keys and
     *                          changes spacing, and the signature is over the bytes that
     *                          were actually sent.
     * @param string $header    The `NC-Signature` header value.
     * @param string $secret    The endpoint signing secret.
     * @param int    $tolerance Replay window in seconds. 0 is strict (only the
     *                          current second passes), never "the default";
     *                          a negative value is a ValidationException.
     * @param int|null $now     Overridable for tests only.
     *
     * @throws WebhookVerificationException Bad signature, stale timestamp, or a body
     *         that verified but is not a JSON object.
     * @throws ValidationException           A negative tolerance.
     */
    public static function verify(
        string $payload,
        string $header,
        #[SensitiveParameter]
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): WebhookEvent {
        if (trim($secret) === '') {
            throw new WebhookVerificationException('a webhook signing secret is required');
        }
        if ($tolerance < 0) {
            throw new ValidationException('tolerance must be 0 or more seconds');
        }

        [$timestamp, $signatures] = self::parseHeader($header);

        // Checked before the HMAC: a replayed request carries a signature that
        // is genuinely ours and will verify forever. The timestamp is the only
        // thing that makes a captured delivery expire.
        $age = abs(($now ?? time()) - $timestamp);
        if ($age > $tolerance) {
            throw new WebhookVerificationException(sprintf(
                'webhook timestamp is %ds away from now, outside the %ds tolerance',
                $age,
                $tolerance,
            ));
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        $matched = false;
        foreach ($signatures as $candidate) {
            // hash_equals, never ===. A short-circuiting comparison leaks how
            // many leading characters were right through its timing, and a
            // signature can be recovered a character at a time from that.
            // Both arguments are fixed-length hex here, so no length leak either.
            if (hash_equals($expected, $candidate)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            // Several v1 values are legitimate: during a secret rotation both
            // the old and the new secret sign the delivery, so a mismatch means
            // none of them was ours. The expected value is never included —
            // that would turn this verifier into an oracle that hands an
            // attacker the answer.
            throw new WebhookVerificationException('webhook signature does not match');
        }

        $decoded = Json::decodeObject($payload);
        if ($decoded === null) {
            throw new WebhookVerificationException(
                'the webhook payload verified but is not a JSON object',
            );
        }

        return WebhookEvent::fromArray($decoded);
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private static function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }

            [$name, $value] = [trim($pair[0]), trim($pair[1])];

            // 1–12 ASCII digits and nothing else: no sign, no exponent, and
            // short enough that it can never overflow an integer.
            if ($name === 't' && preg_match('/^[0-9]{1,12}$/D', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1' && preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                // Lowercased so a sender that hex-encodes in upper case still
                // matches hash_hmac's lower-case output.
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new WebhookVerificationException(sprintf(
                'malformed %s header; expected "t=<unix seconds>,v1=<hex sha256>"',
                self::SIGNATURE_HEADER,
            ));
        }

        return [$timestamp, $signatures];
    }
}
