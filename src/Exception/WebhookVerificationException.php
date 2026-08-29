<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

/**
 * A webhook payload did not verify against the signing secret.
 *
 * Raised for a bad or missing signature, a timestamp outside the tolerance,
 * and a verified-but-unparseable body. The message never contains the
 * expected signature — handing an attacker the answer turns a verifier into
 * an oracle.
 */
final class WebhookVerificationException extends NaijamailException
{
}
