<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Model;

use DateTimeImmutable;
use Throwable;

/**
 * A verified webhook event.
 *
 * Only ever constructed by {@see \NaijaCloud\Email\Webhooks::verify()}, so
 * holding one is proof the signature and timestamp checked out. `data` is left
 * as a decoded array rather than typed per event: the event catalogue is not
 * frozen yet, and an SDK that throws on an event type it does not recognise is
 * an SDK that breaks the day a new event ships.
 */
final class WebhookEvent
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly array $data = [],
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $createdAt = null;
        $rawCreatedAt = $payload['created_at'] ?? null;
        if (is_string($rawCreatedAt) && $rawCreatedAt !== '') {
            try {
                $createdAt = new DateTimeImmutable($rawCreatedAt);
            } catch (Throwable) {
                $createdAt = null;
            }
        }

        return new self(
            isset($payload['id']) && is_scalar($payload['id']) ? (string) $payload['id'] : '',
            isset($payload['type']) && is_scalar($payload['type']) ? (string) $payload['type'] : '',
            $createdAt,
            isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [],
        );
    }
}
