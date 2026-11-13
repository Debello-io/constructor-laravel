<?php

namespace ConstructorIO\Laravel\Support;

/**
 * Incremental parser for a Server-Sent Events (SSE) stream.
 *
 * The AI agents answer with a text/event-stream body that arrives in
 * arbitrary chunks, so an event can be split across two network reads.
 * Feed every chunk in as it arrives; complete events come out, and any
 * partial event waits in this instance's buffer for the next chunk.
 *
 * Use one parser per response. The buffer belongs to the instance, so two
 * streams (or two requests on a long-running Octane worker) never mix.
 *
 *     $parser = new ServerSentEventParser;
 *     foreach ($chunks as $chunk) {
 *         foreach ($parser->feed($chunk) as $event) {
 *             // ['event' => 'message', 'data' => ['text' => '...']]
 *         }
 *     }
 *     $last = $parser->flush();
 *
 * @see https://html.spec.whatwg.org/multipage/server-sent-events.html#parsing-an-event-stream
 */
final class ServerSentEventParser
{
    /** Event name used when a block has data but no "event:" line. */
    private const DEFAULT_EVENT = 'message';

    private string $buffer = '';

    /** Raw bytes not yet line-normalised: a trailing "\r" may be half of "\r\n". */
    private string $pending = '';

    /**
     * Add a chunk and return the events it completed.
     *
     * @return list<array{event: string, data: mixed}>
     */
    public function feed(string $chunk): array
    {
        $raw = $this->pending.$chunk;

        // A chunk can end between the "\r" and "\n" of one line break. Hold a
        // trailing "\r" back until the next chunk, or it would be read as a
        // separate line ending and split the event in two.
        $this->pending = str_ends_with($raw, "\r") ? "\r" : '';
        if ($this->pending !== '') {
            $raw = substr($raw, 0, -1);
        }

        // Normalise line endings so "\r\n\r\n" and "\n\n" both end an event.
        $this->buffer .= str_replace(["\r\n", "\r"], "\n", $raw);

        $events = [];

        while (($end = strpos($this->buffer, "\n\n")) !== false) {
            $block = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 2);

            $event = $this->parseBlock($block);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Return the final event if the stream ended without a blank line.
     *
     * @return list<array{event: string, data: mixed}>
     */
    public function flush(): array
    {
        $block = trim($this->buffer.str_replace("\r", "\n", $this->pending), "\n");
        $this->buffer = '';
        $this->pending = '';

        $event = $block === '' ? null : $this->parseBlock($block);

        return $event === null ? [] : [$event];
    }

    /**
     * Turn one "event: x\ndata: {...}" block into an event.
     *
     * Lines starting with ":" are comments (often used as keep-alives).
     * Several "data:" lines are joined with newlines, per the SSE spec.
     *
     * @return array{event: string, data: mixed}|null
     */
    private function parseBlock(string $block): ?array
    {
        $eventName = self::DEFAULT_EVENT;
        $dataLines = [];

        foreach (explode("\n", $block) as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            // A single space after the colon is part of the syntax, not the value.
            $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

            match ($field) {
                'event' => $eventName = $value,
                'data' => $dataLines[] = $value,
                default => null, // "id" and "retry" are not used by Constructor.
            };
        }

        if ($dataLines === []) {
            return null;
        }

        return ['event' => $eventName, 'data' => $this->decode(implode("\n", $dataLines))];
    }

    /**
     * Constructor sends JSON. Keep anything else as the raw string.
     */
    private function decode(string $data): mixed
    {
        $decoded = json_decode($data, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $data;
    }
}
