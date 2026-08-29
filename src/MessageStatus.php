<?php

declare(strict_types=1);

namespace NaijaCloud\Email;

/**
 * The statuses the API sends today.
 *
 * Constants on a final class, deliberately not a backed enum: `Status::from()`
 * throws on a value it does not know, so the day the platform adds a status the
 * enum would turn every already-deployed copy of this SDK into a crash on
 * `get()`. A status the SDK has never heard of passes through as a plain string
 * instead, and `isKnown()` is there for callers who want to notice.
 *
 * Roughly a progression, but not a state machine — a message can go `delivered`
 * then `complained`, and providers deliver events out of order often enough
 * that no caller should assume otherwise.
 */
final class MessageStatus
{
    public const QUEUED = 'queued';
    public const SENT = 'sent';
    public const DELIVERED = 'delivered';
    public const BOUNCED = 'bounced';
    public const DEFERRED = 'deferred';
    public const COMPLAINED = 'complained';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';

    /** @var list<string> */
    public const ALL = [
        self::QUEUED,
        self::SENT,
        self::DELIVERED,
        self::BOUNCED,
        self::DEFERRED,
        self::COMPLAINED,
        self::REJECTED,
        self::FAILED,
    ];

    private function __construct()
    {
    }

    /** False for a status this release predates. Never a reason to throw. */
    public static function isKnown(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /** True once the message can no longer change state on its own. */
    public static function isTerminal(string $status): bool
    {
        return in_array(
            $status,
            [self::DELIVERED, self::BOUNCED, self::REJECTED, self::FAILED],
            true,
        );
    }
}
