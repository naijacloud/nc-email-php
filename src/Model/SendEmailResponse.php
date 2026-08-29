<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Model;

/**
 * The answer to a successful send.
 *
 * A 202 means the message passed authorisation, suppression, reputation and
 * quota checks and is queued — not that a mailbox has it. Poll
 * {@see \NaijaCloud\Email\Emails::get()} or wait for a webhook for that.
 */
final class SendEmailResponse
{
    /**
     * @param string                 $id       Our message id, stable across a delivery-backend
     *                                         change. One record exists per primary recipient;
     *                                         this is the first of them.
     * @param string                 $status   A {@see \NaijaCloud\Email\MessageStatus} constant,
     *                                         or a newer value this release has never seen.
     * @param list<RejectedRecipient> $rejected Always present, empty when nobody was refused.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly array $rejected = [],
    ) {
    }

    /**
     * Build from a decoded response body, ignoring keys we do not know.
     *
     * Ignoring them is the point: the platform ships fields ahead of the SDKs,
     * and a strict decoder would turn "the API gained a field" into "every
     * deployed copy of this SDK throws".
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rejected = [];
        // The server omits `rejected` entirely when nobody was refused. Callers
        // must never have to branch on absence, so normalise to an empty list.
        if (isset($data['rejected']) && is_array($data['rejected'])) {
            foreach ($data['rejected'] as $entry) {
                if (is_array($entry)) {
                    $rejected[] = RejectedRecipient::fromArray($entry);
                }
            }
        }

        return new self(
            isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : '',
            isset($data['status']) && is_scalar($data['status']) ? (string) $data['status'] : '',
            $rejected,
        );
    }
}
