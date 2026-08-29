<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Model;

/**
 * One recipient the platform refused to mail — today, always because the
 * address is on the team's suppression list.
 *
 * Not an error: the rest of the message still went out. A caller that treats a
 * rejection as a failed send will re-queue mail to an address that has already
 * bounced or complained, which is exactly how a sending reputation is lost.
 */
final class RejectedRecipient
{
    public function __construct(
        public readonly string $address,
        public readonly string $reason,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['address']) && is_scalar($data['address']) ? (string) $data['address'] : '',
            isset($data['reason']) && is_scalar($data['reason']) ? (string) $data['reason'] : '',
        );
    }
}
