<?php

namespace ConstructorIO\Laravel\Concerns;

use ConstructorIO\Laravel\Exceptions\ConnectionException;
use ConstructorIO\Laravel\Support\ServerSentEventParser;
use Illuminate\Support\Carbon;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Reads a Server-Sent Events response from Constructor's agent host.
 *
 * Shared by AgentService (the Shopping Agent and Product Insights Agent) and
 * InsightsService (Agent Insights), which stream the same way.
 */
trait ReadsEventStreams
{
    /**
     * Call $handle(event, data) for every event on an open stream, then close it.
     *
     * The body is read in chunks rather than buffered, so events reach the
     * caller as soon as Constructor sends them. Keep-alive "ping" events are
     * dropped here.
     *
     * Two guarantees a caller can rely on:
     *   - The whole response must finish within $timeoutSeconds. Pings don't
     *     extend it: a stream that only ever sends keep-alives would otherwise
     *     hold the PHP worker forever.
     *   - A stream that ends before Constructor's `end` event is a failure,
     *     not a short answer. It throws instead of returning partial text.
     *
     * @param  callable(string, mixed): void  $handle
     *
     * @throws ConnectionException
     */
    protected function readEventStream(StreamInterface $body, int $timeoutSeconds, callable $handle): void
    {
        $parser = new ServerSentEventParser;
        $deadline = Carbon::now()->addSeconds($timeoutSeconds);
        $receivedEnd = false;

        $dispatch = function (array $events) use ($handle, &$receivedEnd): void {
            foreach ($events as $event) {
                if ($event['event'] === 'ping') {
                    continue;
                }

                $receivedEnd = $receivedEnd || $event['event'] === 'end';
                $handle($event['event'], $event['data']);
            }
        };

        try {
            while (! $body->eof()) {
                if (Carbon::now()->greaterThan($deadline)) {
                    throw new ConnectionException(
                        "The Constructor agent response exceeded {$timeoutSeconds} seconds (CONSTRUCTOR_STREAM_TIMEOUT)."
                    );
                }

                $dispatch($parser->feed($this->readStreamChunk($body)));
            }

            $endedMidEvent = $parser->hasPartialEvent();
            $dispatch($parser->flush());

            if (! $receivedEnd || $endedMidEvent) {
                throw new ConnectionException('The Constructor agent stream ended before the response was complete.');
            }
        } finally {
            $body->close();
        }
    }

    /**
     * Read the next chunk of a stream.
     *
     * Guzzle throws a plain RuntimeException when the connection drops or
     * times out mid-stream; report it as a ConnectionException.
     *
     * @throws ConnectionException
     */
    private function readStreamChunk(StreamInterface $body): string
    {
        try {
            // 8 KiB: small enough that events reach the caller promptly.
            return $body->read(8192);
        } catch (RuntimeException $e) {
            throw new ConnectionException('The Constructor agent stream was interrupted: '.$e->getMessage(), 0, $e);
        }
    }
}
