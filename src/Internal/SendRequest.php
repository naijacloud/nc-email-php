<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Internal;

use NaijaCloud\Email\Exception\ValidationException;
use NaijaCloud\Email\Limits;

/**
 * Validates the caller's array and builds the wire body for `POST /v1/emails`.
 *
 * Everything here is checked before a socket is opened. The server checks the
 * same things — it has to, since not every caller uses an SDK — but a local
 * failure names the field, arrives in the caller's stack trace, and costs no
 * round trip.
 *
 * @internal
 */
final class SendRequest
{
    /**
     * Parameters we accept. Anything else is a typo and is rejected: a silently
     * dropped `'bcc_list'` is a message that quietly did not reach half its
     * recipients.
     *
     * `replyTo` is accepted alongside `reply_to` only because the API accepts
     * both and people copy field names out of the dashboard; the wire body
     * always says `reply_to`.
     *
     * @var list<string>
     */
    private const ALLOWED_PARAMS = [
        'from', 'to', 'cc', 'bcc', 'reply_to', 'replyTo', 'subject', 'html', 'text',
        'headers', 'attachments', 'tags', 'idempotency_key',
    ];

    /**
     * Overriding any of these would sidestep the domain authorisation that the
     * From address is checked against — a caller could pass an unverified
     * `From:` in a custom header and have it win in the built message.
     */
    private const FORBIDDEN_HEADERS = ['from', 'to', 'cc', 'bcc', 'subject', 'dkim-signature', 'received'];

    /** @var list<string> */
    private const ALLOWED_ATTACHMENT_KEYS = ['filename', 'content', 'content_type', 'content_id'];

    /**
     * @param array<string,mixed> $body The JSON body, ready to encode.
     */
    private function __construct(
        public readonly array $body,
        public readonly string $idempotencyKey,
    ) {
    }

    /**
     * @param array<string,mixed> $params
     */
    public static function build(array $params): self
    {
        Guard::onlyKnownKeys($params, self::ALLOWED_PARAMS, 'send() parameter');

        $body = [];

        // The From domain is the one thing the platform authorises against, so
        // it is checked first: a caller who got this wrong has nothing else
        // worth reporting.
        $body['from'] = Guard::noHeaderBreaks(
            trim(Guard::requiredString($params['from'] ?? null, 'from')),
            'from',
        );

        $to = Guard::addressList($params['to'] ?? null, 'to');
        if ($to === []) {
            throw new ValidationException('"to" is required');
        }
        $body['to'] = $to;

        $cc = Guard::addressList($params['cc'] ?? null, 'cc');
        $bcc = Guard::addressList($params['bcc'] ?? null, 'bcc');
        $replyTo = Guard::addressList($params['reply_to'] ?? $params['replyTo'] ?? null, 'reply_to');

        $recipients = count($to) + count($cc) + count($bcc);
        if ($recipients > Limits::MAX_RECIPIENTS) {
            throw new ValidationException(sprintf(
                'too many recipients: %d across to, cc and bcc (limit %d). Split the send.',
                $recipients,
                Limits::MAX_RECIPIENTS,
            ));
        }

        if ($cc !== []) {
            $body['cc'] = $cc;
        }
        if ($bcc !== []) {
            $body['bcc'] = $bcc;
        }
        if ($replyTo !== []) {
            $body['reply_to'] = $replyTo;
        }

        // Always sent, even empty: the server defaults a missing subject to ""
        // anyway, and sending it explicitly keeps the request identical to what
        // the caller asked for.
        $body['subject'] = Guard::noHeaderBreaks(
            Guard::optionalString($params['subject'] ?? null, 'subject') ?? '',
            'subject',
        );

        $html = Guard::optionalString($params['html'] ?? null, 'html');
        $text = Guard::optionalString($params['text'] ?? null, 'text');

        if ($html !== null) {
            $body['html'] = $html;
        }
        if ($text !== null) {
            $body['text'] = $text;
        }

        $headers = self::headers($params['headers'] ?? null);
        if ($headers !== []) {
            $body['headers'] = $headers;
        }

        $attachments = self::attachments($params['attachments'] ?? null);
        if ($attachments !== []) {
            $body['attachments'] = $attachments;
        }

        $tags = self::tags($params['tags'] ?? null);
        if ($tags !== []) {
            $body['tags'] = $tags;
        }

        // An attachment-only message is legitimate — a statement PDF with no
        // covering text — so the check is for a message with nothing in it at
        // all, which is always a mistake in the caller's code rather than
        // something to discover from an empty mail in a customer's inbox.
        $empty = ($html === null || $html === '')
            && ($text === null || $text === '')
            && $attachments === [];
        if ($empty) {
            throw new ValidationException('a message needs "html", "text" or an attachment');
        }

        return new self($body, self::idempotencyKey($params['idempotency_key'] ?? null));
    }

    /**
     * The encoded body, with the size limit applied to what actually goes on
     * the wire rather than to the raw bytes the caller handed us — base64 is a
     * third larger, and it is the encoded size the server measures.
     */
    public function encode(): string
    {
        $json = Json::encode($this->body);

        if (strlen($json) > Limits::MAX_BYTES) {
            throw new ValidationException(sprintf(
                'the message is %.1f MiB encoded, over the %d MiB limit. Attachments are base64 on'
                . ' the wire, so they cost about a third more than their raw size.',
                strlen($json) / 1048576,
                (int) (Limits::MAX_BYTES / 1048576),
            ));
        }

        return $json;
    }

    /**
     * @return array<string,string>
     */
    private static function headers(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new ValidationException('headers must be a map of header name to value');
        }

        if (count($raw) > Limits::MAX_HEADERS) {
            throw new ValidationException(sprintf(
                'too many custom headers: %d (limit %d)',
                count($raw),
                Limits::MAX_HEADERS,
            ));
        }

        $headers = [];
        foreach ($raw as $name => $value) {
            $name = (string) $name;

            if (in_array(strtolower(trim($name)), self::FORBIDDEN_HEADERS, true)) {
                throw new ValidationException(sprintf(
                    'header "%s" cannot be overridden. Use the "%s" parameter instead.',
                    $name,
                    strtolower(trim($name)),
                ));
            }

            // RFC 9110's token rule. A space or a colon in a header name breaks
            // out of the field just as surely as a newline does, so the whole
            // charset is checked rather than only CR, LF and NUL.
            if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
                throw new ValidationException(sprintf(
                    'header name "%s" is not a valid HTTP header name',
                    Guard::noHeaderBreaks($name, 'header name'),
                ));
            }

            if (!is_string($value)) {
                throw new ValidationException(sprintf('header "%s" must be a string', $name));
            }

            $headers[$name] = Guard::noHeaderBreaks($value, sprintf('header "%s"', $name));
        }

        return $headers;
    }

    /**
     * @return list<array<string,string>>
     */
    private static function attachments(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new ValidationException('attachments must be an array');
        }

        $attachments = [];
        foreach (array_values($raw) as $index => $attachment) {
            $label = sprintf('attachments[%d]', $index);

            if (!is_array($attachment)) {
                throw new ValidationException($label . ' must be an array with filename and content');
            }

            // A caller arriving from another SDK will try this. Say why it is
            // refused rather than dropping it: an SDK that opens whatever path
            // it is handed is a file-disclosure primitive the moment a web
            // handler passes user input into it.
            if (array_key_exists('path', $attachment)) {
                throw new ValidationException(
                    $label . ' does not accept a file path. Read the file yourself and pass the'
                    . ' bytes as "content" — an SDK that opens arbitrary paths on your behalf is a'
                    . ' file-disclosure bug waiting for the first request-supplied filename.',
                );
            }

            Guard::onlyKnownKeys($attachment, self::ALLOWED_ATTACHMENT_KEYS, $label . ' key');

            $filename = Guard::noHeaderBreaks(
                Guard::requiredString($attachment['filename'] ?? null, $label . ' filename'),
                $label . ' filename',
            );

            if (!isset($attachment['content']) || !is_string($attachment['content'])) {
                throw new ValidationException($label . ' content must be a string of raw bytes');
            }
            if ($attachment['content'] === '') {
                throw new ValidationException($label . ' content is empty');
            }

            $entry = [
                'filename' => $filename,
                // Base64 here, never in the caller's code. A caller who encodes
                // by hand gets it subtly wrong — double-encoded, or wrapped at
                // 76 columns by a helper that does not say so — and the result
                // is a corrupt invoice nobody notices until a customer calls.
                'content' => base64_encode($attachment['content']),
            ];

            $contentType = Guard::optionalString($attachment['content_type'] ?? null, $label . ' content_type');
            if ($contentType !== null && $contentType !== '') {
                $entry['content_type'] = Guard::noHeaderBreaks($contentType, $label . ' content_type');
            }

            $contentId = Guard::optionalString($attachment['content_id'] ?? null, $label . ' content_id');
            if ($contentId !== null && $contentId !== '') {
                $entry['content_id'] = Guard::noHeaderBreaks($contentId, $label . ' content_id');
            }

            $attachments[] = $entry;
        }

        return $attachments;
    }

    /**
     * @return array<string,string>
     */
    private static function tags(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new ValidationException('tags must be a map of key to value');
        }

        if (count($raw) > Limits::MAX_TAGS) {
            throw new ValidationException(sprintf(
                'too many tags: %d (limit %d)',
                count($raw),
                Limits::MAX_TAGS,
            ));
        }

        $tags = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;

            if (!is_string($value)) {
                throw new ValidationException(sprintf('tag "%s" must be a string', $key));
            }

            // The server truncates over-long tags. Rejecting instead is the
            // kinder failure: a truncated tag silently splits one campaign's
            // analytics across two labels, and nobody reads the row that says
            // so until the numbers are already wrong.
            if (mb_strlen($key) > Limits::MAX_TAG_KEY_CHARS) {
                throw new ValidationException(sprintf(
                    'tag key "%s" is longer than %d characters; the server would truncate it',
                    mb_substr($key, 0, 20) . '...',
                    Limits::MAX_TAG_KEY_CHARS,
                ));
            }
            if (mb_strlen($value) > Limits::MAX_TAG_VALUE_CHARS) {
                throw new ValidationException(sprintf(
                    'tag "%s" has a value longer than %d characters; the server would truncate it',
                    $key,
                    Limits::MAX_TAG_VALUE_CHARS,
                ));
            }

            $tags[$key] = $value;
        }

        return $tags;
    }

    /**
     * A caller's key always wins and is never regenerated. Otherwise generate
     * one UUIDv4 per send() call — not per attempt — because that is the whole
     * reason retrying a send is safe: the server dedupes on it, so a timeout
     * followed by a retry returns the original message instead of mailing the
     * customer twice.
     */
    private static function idempotencyKey(mixed $supplied): string
    {
        if ($supplied === null) {
            return Uuid::v4();
        }

        if (!is_string($supplied) || trim($supplied) === '') {
            throw new ValidationException('idempotency_key must be a non-empty string');
        }

        $key = trim($supplied);
        Guard::noHeaderBreaks($key, 'idempotency_key');

        if (strlen($key) > Limits::MAX_IDEMPOTENCY_KEY_CHARS) {
            throw new ValidationException(sprintf(
                'idempotency_key is longer than %d characters',
                Limits::MAX_IDEMPOTENCY_KEY_CHARS,
            ));
        }

        return $key;
    }
}
