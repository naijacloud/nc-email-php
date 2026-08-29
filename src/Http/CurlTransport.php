<?php

declare(strict_types=1);

namespace NaijaCloud\Email\Http;

use NaijaCloud\Email\Exception\ConnectionException;
use NaijaCloud\Email\Exception\TimeoutException;

/**
 * The default transport: ext-curl, no dependencies.
 *
 * ext-curl rather than streams because TLS verification with
 * `https_wrapper`-style stream contexts is easy to get subtly wrong and easy
 * for a host's php.ini to weaken, while curl's verification is on by default
 * and set explicitly here regardless.
 *
 * @internal
 */
final class CurlTransport implements Transport
{
    /**
     * Connecting should never be allowed to consume the whole per-attempt
     * budget: a black-holed SYN would otherwise burn the full 30s before the
     * first byte of the request is written, and the retry that follows would
     * do it again.
     */
    private const MAX_CONNECT_SECONDS = 10.0;

    /**
     * @param bool $allowPlaintext Set only when the client's base URL is a
     *        loopback dev override. In every other case the protocol allow-list
     *        below is HTTPS-only, so a redirect or a mistyped URL cannot put a
     *        live key on a cleartext socket.
     */
    public function __construct(private readonly bool $allowPlaintext = false)
    {
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse {
        // A fresh handle per request, never a pooled one. A reused handle keeps
        // options that were set on it, so a later request that omits
        // Authorization would silently inherit the previous request's key —
        // the kind of leak that only shows up in someone else's access log.
        $ch = curl_init();
        if ($ch === false) {
            throw new ConnectionException('could not initialise a cURL handle');
        }

        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,

            // A followed redirect re-sends Authorization to whatever host the
            // response names. That is how bearer tokens leak, so 3xx is data
            // to be reported, never something to act on.
            CURLOPT_FOLLOWLOCATION => false,

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_TIMEOUT => max(1, (int) ceil($timeout)),
            CURLOPT_CONNECTTIMEOUT => max(1, (int) ceil(min($timeout, self::MAX_CONNECT_SECONDS))),

            // libcurl keeps these in the same fields as the whole-second
            // options above, so setting them afterwards refines the same
            // limits. Needed because a sub-second timeout would otherwise
            // round up to a full second.
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round(min($timeout, self::MAX_CONNECT_SECONDS) * 1000)),

            // Without this libcurl may use SIGALRM for DNS timeouts, which is
            // unsafe in any process that is not single-threaded and can leave
            // a sub-second timeout unenforced.
            CURLOPT_NOSIGNAL => true,

            CURLOPT_HTTPHEADER => self::formatHeaders($headers),
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }

                // curl requires the byte count it handed us, not the count we
                // kept; returning anything else aborts the transfer.
                return $length;
            },
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        foreach (self::protocolOptions($this->allowPlaintext) as $option => $value) {
            $options[$option] = $value;
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($responseBody === false || $errno !== 0) {
            throw self::transportFailure($errno, $error, $url);
        }

        return new HttpResponse($status, $responseHeaders, (string) $responseBody);
    }

    /**
     * The key is never held here — it arrives per request and is gone when the
     * handle is closed — but say so explicitly, so that adding a field to this
     * class later cannot quietly put a credential into someone's `var_dump`.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'allowPlaintext' => $this->allowPlaintext,
            'note' => 'no request headers are retained by this transport',
        ];
    }

    /**
     * @param array<string,string> $headers
     *
     * @return list<string>
     */
    private static function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        // curl adds `Expect: 100-continue` to bodies over 1KB on its own, which
        // costs a round trip and makes some proxies stall for a second before
        // the body is sent. An empty value removes the header entirely.
        $formatted[] = 'Expect:';

        return $formatted;
    }

    /**
     * Restrict the protocols libcurl will speak at all. Belt to the braces of
     * the base-URL check: even a malformed URL that slipped through cannot
     * become an ftp:// or file:// fetch with the Authorization header attached.
     *
     * @return array<int,int|string>
     */
    private static function protocolOptions(bool $allowPlaintext): array
    {
        $options = [];

        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            // The string form is what libcurl 7.85+ wants; the integer form
            // below is deprecated there but is all that older builds have.
            $allowed = $allowPlaintext ? 'https,http' : 'https';
            $options[CURLOPT_PROTOCOLS_STR] = $allowed;
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = $allowed;

            return $options;
        }

        $mask = $allowPlaintext ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS;
        $options[CURLOPT_PROTOCOLS] = $mask;
        $options[CURLOPT_REDIR_PROTOCOLS] = $mask;

        return $options;
    }

    /**
     * Map a curl failure onto the two error types the contract distinguishes.
     * The URL is included because a wrong base URL is the most common cause and
     * the least obvious from the curl message alone; it never contains the key.
     */
    private static function transportFailure(
        int $errno,
        string $error,
        string $url,
    ): ConnectionException|TimeoutException
    {
        $message = $error !== '' ? $error : 'cURL error ' . $errno;

        if ($errno === CURLE_OPERATION_TIMEOUTED) {
            return new TimeoutException(sprintf('request to %s timed out: %s', $url, $message));
        }

        return new ConnectionException(sprintf('could not reach %s: %s', $url, $message));
    }
}
