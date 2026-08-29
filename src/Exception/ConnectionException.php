<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

/**
 * The request never reached the API — DNS, TCP or TLS failed.
 *
 * Kept separate from TimeoutException because the two say different things
 * to an operator: this one usually means the network or certificate
 * verification, not a slow server.
 */
final class ConnectionException extends NaijamailException
{
}
