<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Exception;

/**
 * The request was rejected as malformed — either by us before it went out
 * (statusCode 0), or by the server with 400 or 422.
 *
 * Local validation and the server's 400/422 deliberately share one type: to
 * the caller, "this message is not sendable as written" is one condition
 * with one fix, whether the SDK or the API noticed.
 */
final class ValidationException extends NaijamailException
{
}
