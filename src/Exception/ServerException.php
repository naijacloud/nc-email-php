<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

/**
 * The API failed (5xx), or answered with something we refuse to follow
 * (3xx).
 *
 * A 3xx lands here as "unexpected redirect". We never follow redirects: a
 * followed redirect re-sends the Authorization header to whatever host the
 * response names, which is how bearer tokens leak.
 */
final class ServerException extends NaijamailException
{
}
