<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\AuthenticationException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\ConnectionException;
use ConstructorIO\Laravel\Exceptions\RateLimitException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Services\AgentService;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\RequestContext;
use ConstructorIO\Laravel\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class AgentServiceTest extends TestCase
{
    private function agent(): AgentService
    {
        return $this->app->make(AgentService::class);
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $events
     */
    private function sse(array $events): string
    {
        return implode('', array_map(
            fn (array $event) => "event: {$event[0]}\ndata: ".json_encode($event[1])."\n\n",
            $events,
        ));
    }

    private function fakeIntentStream(string $body): void
    {
        Http::fake([
            'agent.cnstrc.com/v1/intent/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
        ]);
    }

    private function product(string $id, string $name): array
    {
        return [
            'value' => $name,
            'matched_terms' => [],
            'data' => ['id' => $id, 'url' => "/p/{$id}", 'image_url' => "https://img.test/{$id}.jpg", 'price' => 499],
            'is_slotted' => false,
            'labels' => [],
        ];
    }

    public function test_shopping_agent_aggregates_the_documented_events(): void
    {
        $this->fakeIntentStream($this->sse([
            ['start', ['intent_result_id' => 'ir-1', 'thread_id' => 'th-1', 'request' => []]],
            ['message', ['intent_result_id' => 'ir-1', 'text' => 'Here are some ']],
            ['message', ['intent_result_id' => 'ir-1', 'text' => 'compact smokers.']],
            ['search_result', [
                'intent_result_id' => 'ir-1',
                'result_id' => 'res-1',
                'response' => [
                    'search_request' => ['display_name' => 'Smokers', 'search_term' => 'compact smoker', 'params' => []],
                    'alternative_search_requests' => [['display_name' => 'Pellet', 'search_term' => 'pellet smoker', 'params' => []]],
                    'results' => [$this->product('SMK-1', 'Mini Smoker')],
                ],
            ]],
            ['follow_up_refinement', ['intent_result_id' => 'ir-1', 'question' => 'Gas or charcoal?', 'options' => ['Gas', 'Charcoal']]],
            ['article_reference', ['intent_result_id' => 'ir-1', 'source' => 'https://store.test/guide', 'title' => 'Smoking 101']],
            ['image_meta', ['intent_result_id' => 'ir-1', 'item_id' => 'SMK-1', 'image_url' => 'https://img.test/a.jpg']],
            ['ping', ['time' => '2025-06-02T00:00:00+01:00']],
            ['end', ['intent_result_id' => 'ir-1', 'search_result_count' => 1]],
        ]));

        $reply = $this->agent()->askShoppingAgent('compact smoker for a balcony');

        $this->assertSame('Here are some compact smokers.', $reply['message']);
        $this->assertSame('th-1', $reply['thread_id']);
        $this->assertSame('ir-1', $reply['intent_result_id']);
        $this->assertSame(['res-1'], $reply['result_ids']);
        $this->assertSame('SMK-1', $reply['products'][0]['id']);
        $this->assertSame('Mini Smoker', $reply['products'][0]['name']);
        $this->assertSame('res-1', $reply['products'][0]['result_id']);
        $this->assertSame(['Gas or charcoal?'], $reply['follow_up_questions']);
        $this->assertSame([['question' => 'Gas or charcoal?', 'options' => ['Gas', 'Charcoal']]], $reply['refinements']);
        $this->assertSame('compact smoker', $reply['search_requests'][0]['search_term']);
        $this->assertSame('pellet smoker', $reply['alternative_search_requests'][0]['search_term']);
        $this->assertSame('https://store.test/guide', $reply['articles'][0]['url']);
        $this->assertSame('https://img.test/a.jpg', $reply['images']['SMK-1']['image_url']);
        $this->assertNotContains('ping', array_column($reply['_raw'], 'event'));
    }

    public function test_streaming_callback_receives_events_in_order(): void
    {
        $this->fakeIntentStream($this->sse([
            ['start', ['intent_result_id' => 'ir-1', 'thread_id' => 'th-1', 'request' => []]],
            ['message', ['intent_result_id' => 'ir-1', 'text' => 'Hello', 'route' => 'customer_support']],
            ['search_result', ['intent_result_id' => 'ir-1', 'result_id' => 'res-1', 'response' => ['results' => [$this->product('G-1', 'Grill')]]]],
            ['follow_up_refinement', ['intent_result_id' => 'ir-1', 'question' => 'Budget?', 'options' => null]],
            ['recipe_info', ['intent_result_id' => 'ir-1', 'recipe_title' => 'Brisket']],
            ['brand_new_event', ['intent_result_id' => 'ir-1', 'anything' => true]],
            ['end', ['intent_result_id' => 'ir-1']],
        ]));

        $received = [];
        $reply = $this->agent()->askShoppingAgentStreaming('hello', function (string $event, array $data) use (&$received) {
            $received[] = [$event, $data];
        });

        $this->assertSame(
            ['start', 'message', 'products', 'follow_up', 'recipe_info', 'brand_new_event', 'end', 'complete'],
            array_column($received, 0),
        );
        $this->assertSame(['thread_id' => 'th-1', 'intent_result_id' => 'ir-1'], $received[0][1]);
        $this->assertSame(['text' => 'Hello', 'accumulated' => 'Hello', 'route' => 'customer_support'], $received[1][1]);
        $this->assertSame('G-1', $received[2][1]['products'][0]['id']);
        $this->assertSame(['questions' => ['Budget?'], 'question' => 'Budget?', 'options' => []], $received[3][1]);
        $this->assertTrue($received[5][1]['anything']);
        $this->assertSame($reply, $received[7][1]);
        $this->assertSame('customer_support', $reply['route']);
        $this->assertSame('Brisket', $reply['recipe']['info']['recipe_title']);
    }

    public function test_server_error_event_notifies_the_callback_then_throws(): void
    {
        $this->fakeIntentStream($this->sse([
            ['start', ['intent_result_id' => 'ir-1', 'request' => []]],
            ['server_error', ['intent_result_id' => 'ir-1', 'status_code' => 500, 'message' => 'Internal Server Error']],
        ]));

        $received = [];

        try {
            $this->agent()->askShoppingAgentStreaming('hello', function (string $event, array $data) use (&$received) {
                $received[] = [$event, $data];
            });
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertStringContainsString('Internal Server Error', $e->getMessage());
        }

        $this->assertSame(['error', ['status_code' => 500, 'message' => 'Internal Server Error']], end($received));
    }

    public function test_query_is_one_rawurlencoded_path_segment(): void
    {
        $this->fakeIntentStream($this->sse([['end', ['intent_result_id' => 'ir-1']]]));

        $this->agent()->askShoppingAgent('1/2" grill grate #5 & tongs');

        Http::assertSent(function (Request $request) {
            return $this->pathOf($request) === '/v1/intent/1%2F2%22%20grill%20grate%20%235%20%26%20tongs';
        });
    }

    public function test_intent_request_parameters_and_headers(): void
    {
        $this->actAsShopperRequest([
            'ConstructorioID_client_id' => 'client-uuid-1',
            'ConstructorioID_session_id' => '7',
        ]);
        $this->fakeIntentStream($this->sse([['end', ['intent_result_id' => 'ir-1']]]));

        $this->agent()->askShoppingAgent('grill', 'thread-9', [
            'filters' => ['name' => 'in_stock', 'value' => 'True'],
            'user_segments' => ['vip', 'east'],
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame(['key_test'], $query['key']);
            $this->assertSame(['test-agent-domain'], $query['domain']);
            $this->assertSame(['thread-9'], $query['thread_id']);
            $this->assertSame(['true'], $query['guard']);
            $this->assertSame(['5'], $query['num_result_events']);
            $this->assertSame(['4'], $query['num_results_per_event']);
            $this->assertSame(['{"name":"in_stock","value":"True"}'], $query['pre_filter_expression']);
            $this->assertSame(['vip', 'east'], $query['us']);
            $this->assertSame(['client-uuid-1'], $query['i']);
            $this->assertSame(['7'], $query['s']);
            $this->assertSame(['cio-be-web-bbq-test-store'], $query['c']);
            $this->assertArrayNotHasKey('ui', $query, 'Guests must not send ui.');

            $this->assertSame('backend_security_token', $request->header('x-cnstrc-token')[0]);
            $this->assertSame('203.0.113.42', $request->header('X-Forwarded-For')[0]);
            $this->assertSame('Mozilla/5.0 (Shopper Browser)', $request->header('User-Agent')[0]);
            $this->assertSame('text/event-stream', $request->header('Accept')[0]);
            $this->assertStringNotContainsString('tok_secret_api_token', json_encode($request->headers()));
            $this->assertStringNotContainsString('tok_secret_api_token', $request->url());

            return true;
        });
    }

    public function test_missing_agent_domain_throws_a_configuration_exception(): void
    {
        $this->app['config']->set('constructor.agent.domain', null);
        $this->app->forgetInstance(ConstructorConfig::class);
        $this->app->forgetInstance(AgentService::class);
        $this->app->forgetInstance(ApiClient::class);
        $this->app->forgetInstance(RequestContext::class);

        $this->expectException(ConfigurationException::class);

        $this->agent()->askShoppingAgent('grill');
    }

    public function test_401_throws_an_authentication_exception(): void
    {
        Http::fake(['agent.cnstrc.com/*' => Http::response('Unauthorized', 401)]);

        $this->expectException(AuthenticationException::class);

        $this->agent()->askShoppingAgent('grill');
    }

    public function test_429_throws_a_rate_limit_exception(): void
    {
        Http::fake(['agent.cnstrc.com/*' => Http::response('Too many', 429)]);

        $this->expectException(RateLimitException::class);

        $this->agent()->askProductQuestion('Is it stainless?', 'GRILL-1');
    }

    public function test_400_is_not_retried(): void
    {
        Http::fake(['agent.cnstrc.com/*' => Http::response(['detail' => 'bad'], 400)]);

        try {
            $this->agent()->askProductQuestion('Is it stainless?', 'GRILL-1');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }

        Http::assertSentCount(1);
    }

    public function test_product_question_answer_is_mapped(): void
    {
        Http::fake([
            'agent.cnstrc.com/v1/item_questions/*' => Http::response([
                'qna_result_id' => 'qna-1',
                'value' => 'Yes, it is 304 stainless steel.',
                'thread_id' => 'th-2',
                'follow_up_questions' => [['value' => 'Is it dishwasher safe?'], ['value' => '']],
                'item_results' => ['response' => ['results' => [$this->product('COVER-1', 'Grill Cover')]]],
            ]),
        ]);

        $answer = $this->agent()->askProductQuestion('Is it stainless steel?', 'GRILL 1/A', null, ['variation_id' => 'V-2']);

        $this->assertSame('Yes, it is 304 stainless steel.', $answer['answer']);
        $this->assertSame(['Is it dishwasher safe?'], $answer['follow_up_questions']);
        $this->assertSame('qna-1', $answer['qna_result_id']);
        $this->assertSame('th-2', $answer['thread_id']);
        $this->assertSame('COVER-1', $answer['item_results'][0]['id']);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $this->pathOf($request) === '/v1/item_questions/Is%20it%20stainless%20steel%3F/answer'
                && $query['item_id'] === ['GRILL 1/A']
                && $query['variation_id'] === ['V-2']
                && $query['guard'] === ['true'];
        });
    }

    public function test_product_question_streaming(): void
    {
        Http::fake([
            'agent.cnstrc.com/v1/item_questions/*' => Http::response($this->sse([
                ['start', ['qna_result_id' => 'qna-1', 'thread_id' => 'th-3']],
                ['message', ['qna_result_id' => 'qna-1', 'text' => 'Yes, ']],
                ['message', ['qna_result_id' => 'qna-1', 'text' => 'it does.']],
                ['follow_up_questions', ['qna_result_id' => 'qna-1', 'questions' => [['value' => 'Warranty?']]]],
                ['item_results', ['qna_result_id' => 'qna-1', 'response' => ['results' => [$this->product('TANK-1', 'Propane Tank')]]]],
                ['end', ['qna_result_id' => 'qna-1']],
            ]), 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $events = [];
        $answer = $this->agent()->askProductQuestionStreaming('Fits a 20 lb tank?', 'GRILL-1', function (string $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertSame(['start', 'message', 'message', 'follow_up_questions', 'item_results', 'end', 'complete'], $events);
        $this->assertSame('Yes, it does.', $answer['answer']);
        $this->assertSame(['Warranty?'], $answer['follow_up_questions']);
        $this->assertSame('TANK-1', $answer['item_results'][0]['id']);
        $this->assertSame('th-3', $answer['thread_id']);
    }

    public function test_product_questions_are_normalised_to_strings(): void
    {
        Http::fake([
            'agent.cnstrc.com/v1/item_questions*' => Http::response([
                'questions' => [['value' => 'How hot does it get?'], ['value' => 'Is assembly required?']],
            ]),
        ]);

        $questions = $this->agent()->getProductQuestions('GRILL-1', ['num_results' => 2]);

        $this->assertSame(['How hot does it get?', 'Is assembly required?'], $questions['questions']);

        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/v1/item_questions'
            && $this->queryOf($request)['item_id'] === ['GRILL-1']
            && $this->queryOf($request)['num_results'] === ['2']);
    }

    public function test_product_questions_fail_gracefully_by_default(): void
    {
        Http::fake(['agent.cnstrc.com/*' => Http::response('boom', 500)]);

        $this->assertSame(['questions' => [], '_raw' => []], $this->agent()->getProductQuestions('GRILL-1'));
    }

    public function test_a_stream_that_never_finishes_is_cut_off_at_the_stream_timeout(): void
    {
        $this->app['config']->set('constructor.stream_timeout', 30);
        $this->app->forgetInstance(ConstructorConfig::class);
        $this->app->forgetInstance(ApiClient::class);
        $this->app->forgetInstance(AgentService::class);

        // Each read returns a keep-alive and moves the clock forward 10 seconds,
        // so the stream is alive but never completes.
        $pings = new PumpStream(function () {
            Carbon::setTestNow(Carbon::now()->addSeconds(10));

            return ": keep-alive\nevent: ping\ndata: {}\n\n";
        });

        Http::fake(fn () => Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'text/event-stream'], $pings)));

        try {
            $this->agent()->askShoppingAgent('A smoker for a small patio');
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('exceeded 30 seconds', $e->getMessage());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_stream_that_ends_without_an_end_event_throws(): void
    {
        $this->fakeIntentStream(
            $this->sse([['start', ['thread_id' => 'th-1']], ['message', ['text' => 'Here are']]])
            ."event: message\ndata: {\"text\":\"Hel"
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('ended before the response was complete');

        $this->agent()->askShoppingAgent('Pellet grills');
    }

    public function test_product_question_stream_server_error_notifies_then_throws(): void
    {
        Http::fake([
            'agent.cnstrc.com/v1/item_questions/*' => Http::response($this->sse([
                ['start', ['qna_result_id' => 'qna-1']],
                ['server_error', ['status_code' => 503, 'message' => 'Model unavailable']],
            ]), 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $events = [];

        try {
            $this->agent()->askProductQuestionStreaming('Is it rust proof?', 'GRILL-1', function (string $event, array $data) use (&$events) {
                $events[] = [$event, $data];
            });
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        $this->assertSame(['error', ['status_code' => 503, 'message' => 'Model unavailable']], $events[1]);
    }

    public function test_the_client_identifier_cannot_be_overridden(): void
    {
        $this->fakeIntentStream($this->sse([['end', []]]));

        $this->agent()->askShoppingAgent('Grill covers', null, [
            'client' => 'cio-custom',
            'parameters' => ['c' => 'cio-custom'],
        ]);

        Http::assertSent(fn (Request $request) => $this->queryOf($request)['c'] === ['cio-be-web-bbq-test-store']);
    }

    public function test_dot_only_queries_are_encoded_so_they_cannot_change_the_path(): void
    {
        $this->fakeIntentStream($this->sse([['end', []]]));

        $this->agent()->askShoppingAgent('..');

        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/v1/intent/%2E%2E');
    }
}
