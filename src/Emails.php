<?php

declare(strict_types=1);

namespace NaijaCloud\Email;

use NaijaCloud\Email\Exception\NaijamailException;
use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Internal\ApiClient;
use NaijaCloud\Email\Internal\Guard;
use NaijaCloud\Email\Internal\SendRequest;
use NaijaCloud\Email\Model\Email;
use NaijaCloud\Email\Model\SendEmailResponse;

/**
 * The `emails` resource: the two endpoints the API has.
 *
 * Reached as `$nm->emails`, never constructed directly.
 */
final class Emails
{
    /**
     * @internal
     */
    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Send a message.
     *
     * ```php
     * $sent = $nm->emails->send([
     *     'from' => 'Acme <hello@acme.com>',
     *     'to' => ['a@example.com', 'b@example.com'],
     *     'subject' => 'Invoice #1024',
     *     'html' => '<p>Attached.</p>',
     *     'attachments' => [
     *         ['filename' => 'invoice.pdf', 'content' => $pdfBytes],
     *     ],
     * ]);
     * ```
     *
     * A 202 — which is what a returned response means — says the message passed
     * authorisation, suppression, reputation and quota checks and is queued.
     * It does not say a mailbox has it. Check `$sent->rejected` for recipients
     * that were refused, and use {@see self::get()} or a webhook for delivery.
     *
     * Retries are automatic and safe: every send carries an `Idempotency-Key`,
     * generated once per call, so a retry after a timeout returns the original
     * message rather than sending a second one.
     *
     * @param array<string,mixed> $params from, to, cc, bcc, reply_to, subject, html,
     *                                    text, headers, attachments, tags, idempotency_key.
     *
     * @throws ValidationException The message is not sendable as written, or the API refused it.
     * @throws NaijamailException  Everything else: auth, permission, rate limit, network.
     */
    public function send(array $params): SendEmailResponse
    {
        $request = SendRequest::build($params);

        $body = $this->api->request(
            'POST',
            '/v1/emails',
            $request->encode(),
            // The header, not the body field: the server reads the header first
            // and it is the form every proxy and log in between understands.
            ['Idempotency-Key' => $request->idempotencyKey],
        );

        return SendEmailResponse::fromArray($body);
    }

    /**
     * Fetch one message's current state.
     *
     * The id is one message, meaning one recipient: a send to three people
     * writes three records, and the send response carries the id of the first.
     *
     * @throws \NaijaCloud\Email\Exception\NotFoundException No such message for this key's team.
     * @throws NaijamailException
     */
    public function get(string $id): Email
    {
        $id = trim($id);
        if ($id === '') {
            throw new ValidationException('an email id is required');
        }
        Guard::noHeaderBreaks($id, 'email id');

        // rawurlencode, always. Without it an id of "../../admin" or one
        // carrying a query string would rewrite the request path — the caller's
        // id often comes from a URL or a database column they did not write.
        $body = $this->api->request('GET', '/v1/emails/' . rawurlencode($id));

        return Email::fromArray($body);
    }
}
