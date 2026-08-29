<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Model;

use DateTimeImmutable;
use Throwable;

/**
 * One message's current state.
 *
 * `to` is a single address, not a list: the platform writes one record per
 * primary recipient, so a three-recipient send produces three of these and the
 * send response carries the id of the first.
 */
final class Email
{
    /**
     * @param string $status A {@see \NaijaCloud\Email\MessageStatus} constant, or a
     *                       newer value this release has never seen.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $to,
        public readonly string $from,
        public readonly string $subject,
        public readonly string $status,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $deliveredAt = null,
        public readonly bool $opened = false,
        public readonly bool $clicked = false,
        public readonly ?string $failureReason = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::str($data, 'id'),
            self::str($data, 'to'),
            self::str($data, 'from'),
            self::str($data, 'subject'),
            self::str($data, 'status'),
            self::timestamp($data['created_at'] ?? null),
            // Null until the delivery event arrives, which is the normal state
            // for a message that is still queued.
            self::timestamp($data['delivered_at'] ?? null),
            (bool) ($data['opened'] ?? false),
            (bool) ($data['clicked'] ?? false),
            isset($data['failure_reason']) && is_scalar($data['failure_reason'])
                ? (string) $data['failure_reason']
                : null,
        );
    }

    /** True when the message can no longer change state on its own. */
    public function isTerminal(): bool
    {
        return \NaijaCloud\Email\MessageStatus::isTerminal($this->status);
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
    }

    /**
     * A timestamp we cannot parse becomes null rather than an exception: a
     * date-format change on the server must not make an already-delivered
     * message unreadable to a deployed SDK.
     */
    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
