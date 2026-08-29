<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

/**
 * No such message for this team.
 *
 * The retrieve endpoint currently answers 400 with the message "message not
 * found" instead of 404. The SDK maps that case here so callers do not have
 * to know about the quirk, and so the mapping survives the server being
 * fixed.
 */
final class NotFoundException extends NaijamailException
{
}
