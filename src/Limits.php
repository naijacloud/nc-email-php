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

    /** Total encoded request body. Attachments are base64, so ~4/3 of raw bytes. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** An unbounded header map is a way to inflate a message past a naive size check. */
    public const MAX_HEADERS = 25;

    public const MAX_TAGS = 10;
    public const MAX_TAG_KEY_CHARS = 64;
    public const MAX_TAG_VALUE_CHARS = 256;

    /** The server's column width for an idempotency key. */
    public const MAX_IDEMPOTENCY_KEY_CHARS = 255;

    private function __construct()
    {
    }
}
