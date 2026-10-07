<?php

declare(strict_types=1);

namespace NaijaCloud\Email;

/**
 * The client-side limits, mirroring `SENDING_LIMITS` in the control plane.
 *
 * Checked here so a message that cannot possibly be accepted fails in the
 * caller's process, with the field named, instead of spending a round trip to
 * be told the same thing by a machine they cannot see. They are public because
 * an application that batches recipients needs the same numbers to split on.
 *
 * If these drift from the server, the server wins — it is the one that refuses
 * the message.
 */
final class Limits
{
    /** Across to + cc + bcc, per message. */
    public const MAX_RECIPIENTS = 50;

    /**
     * Message size: UTF-8 bytes of html + text plus the raw (decoded) bytes of
     * every attachment — what the server counts. Equal to the limit is allowed.
     */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** An unbounded header map is a way to inflate a message past a naive size check. */
    public const MAX_HEADERS = 25;

    public const MAX_TAGS = 10;
    public const MAX_TAG_KEY_CHARS = 64;
    public const MAX_TAG_VALUE_CHARS = 256;

    /** Longest idempotency key, counted in bytes of UTF-8. */
    public const MAX_IDEMPOTENCY_KEY_BYTES = 255;

    /** @deprecated 0.3.0 The limit is in bytes; use MAX_IDEMPOTENCY_KEY_BYTES. */
    public const MAX_IDEMPOTENCY_KEY_CHARS = self::MAX_IDEMPOTENCY_KEY_BYTES;

    private function __construct()
    {
    }
}
