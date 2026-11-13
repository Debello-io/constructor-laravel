<?php

namespace ConstructorIO\Laravel\Exceptions;

use Illuminate\Http\Client\ConnectionException as HttpConnectionException;

/**
 * Constructor could not be reached (DNS failure, timeout, connection reset).
 */
class ConnectionException extends ConstructorException
{
    /**
     * Wrap an HTTP client connection error without its request URL.
     *
     * Guzzle appends the full URL to the message, and that URL carries the
     * shopper's identifiers (i, s, ui) and search terms. Exceptions end up in
     * logs and error trackers, so only the host is kept, and the original
     * exception is not chained (it would be logged with the URL).
     */
    public static function fromHttpClient(HttpConnectionException $e, string $url): self
    {
        $reason = preg_replace('/\s+for\s+\S+:\/\/\S+/', '', $e->getMessage()) ?? 'connection failed';
        $reason = preg_replace('/https?:\/\/\S+/', '[url]', $reason) ?? $reason;
        $host = parse_url($url, PHP_URL_HOST) ?: 'Constructor';

        return new self("Could not connect to Constructor ({$host}): {$reason}");
    }
}
