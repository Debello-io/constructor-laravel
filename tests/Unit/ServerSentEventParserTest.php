<?php

namespace ConstructorIO\Laravel\Tests\Unit;

use ConstructorIO\Laravel\Support\ServerSentEventParser;
use PHPUnit\Framework\TestCase;

class ServerSentEventParserTest extends TestCase
{
    public function test_it_parses_complete_events(): void
    {
        $events = (new ServerSentEventParser)->feed(
            "event: start\ndata: {\"thread_id\":\"t-1\"}\n\nevent: message\ndata: {\"text\":\"Hi\"}\n\n"
        );

        $this->assertSame([
            ['event' => 'start', 'data' => ['thread_id' => 't-1']],
            ['event' => 'message', 'data' => ['text' => 'Hi']],
        ], $events);
    }

    public function test_an_event_split_across_chunks_is_emitted_once_complete(): void
    {
        $parser = new ServerSentEventParser;

        $this->assertSame([], $parser->feed("event: message\ndata: {\"te"));
        $this->assertSame([], $parser->feed('xt":"Hello"}'));
        $this->assertSame(
            [['event' => 'message', 'data' => ['text' => 'Hello']]],
            $parser->feed("\n\n"),
        );
    }

    public function test_crlf_line_endings_are_supported(): void
    {
        $events = (new ServerSentEventParser)->feed("event: end\r\ndata: {\"done\":true}\r\n\r\n");

        $this->assertSame([['event' => 'end', 'data' => ['done' => true]]], $events);
    }

    public function test_multiple_data_lines_are_joined_with_newlines(): void
    {
        $events = (new ServerSentEventParser)->feed("event: note\ndata: line one\ndata: line two\n\n");

        $this->assertSame([['event' => 'note', 'data' => "line one\nline two"]], $events);
    }

    public function test_comments_are_ignored_and_missing_event_name_defaults_to_message(): void
    {
        $events = (new ServerSentEventParser)->feed(": keep-alive\n\ndata: {\"text\":\"x\"}\n\n");

        $this->assertSame([['event' => 'message', 'data' => ['text' => 'x']]], $events);
    }

    public function test_ping_events_are_returned_for_the_caller_to_ignore(): void
    {
        $events = (new ServerSentEventParser)->feed("event: ping\ndata: {\"time\":\"2025-06-02T00:00:00+01:00\"}\n\n");

        $this->assertSame('ping', $events[0]['event']);
    }

    public function test_non_json_data_is_kept_as_a_string(): void
    {
        $events = (new ServerSentEventParser)->feed("event: message\ndata: plain text\n\n");

        $this->assertSame('plain text', $events[0]['data']);
    }

    public function test_flush_returns_a_trailing_event_without_a_blank_line(): void
    {
        $parser = new ServerSentEventParser;

        $this->assertSame([], $parser->feed("event: end\ndata: {}"));
        $this->assertSame([['event' => 'end', 'data' => []]], $parser->flush());
        $this->assertSame([], $parser->flush());
    }

    public function test_each_parser_has_its_own_buffer(): void
    {
        $first = new ServerSentEventParser;
        $first->feed("event: message\ndata: {\"text\":\"partial");

        $second = new ServerSentEventParser;

        $this->assertSame(
            [['event' => 'end', 'data' => []]],
            $second->feed("event: end\ndata: {}\n\n"),
        );
    }

    public function test_a_crlf_split_between_chunks_does_not_end_the_event(): void
    {
        $parser = new ServerSentEventParser;

        $this->assertSame([], $parser->feed("event: search_result\r"));
        $this->assertSame(
            [['event' => 'search_result', 'data' => ['result_id' => 'r-1']]],
            $parser->feed("\ndata: {\"result_id\":\"r-1\"}\r\n\r\n"),
        );
    }
}
