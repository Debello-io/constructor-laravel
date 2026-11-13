<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\DataTransferObjects\AgentInsight;
use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\ConnectionException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Services\InsightsService;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Agent Insights (/v1/agent_insights/*), checked against the
 * "AI Shopping Agent (reference)" spec.
 */
class InsightsServiceTest extends TestCase
{
    private function insights(): InsightsService
    {
        return app(InsightsService::class);
    }

    /**
     * The aggregated JSON document Constructor returns for Accept: application/json.
     *
     * @return array<string, mixed>
     */
    private function insightResponse(): array
    {
        return [
            'result_id' => 'insight-1',
            'thread_id' => '1f6b8d2e-0000-4000-8000-000000000001',
            'status' => 'complete',
            'message' => 'Compact pellet smokers suit a small patio.',
            'item_results' => [[
                'result_id' => 'set-1',
                'title' => 'Small-space smokers',
                'text' => 'Under 30 inches wide.',
                'request' => ['type' => 'search', 'params' => ['section' => 'Products']],
                'response' => [
                    'result_id' => 'search-result-9',
                    'results' => [['value' => 'Traeger Ranger', 'data' => ['id' => 'TRG-RANGER', 'url' => '/p/ranger', 'price' => 449]]],
                ],
            ]],
            'article_references' => [['source' => 'https://store.test/guides/smokers', 'title' => 'Choosing a smoker']],
            'images' => [['item_id' => 'TRG-RANGER', 'image_url' => 'https://img.test/ranger.jpg']],
            'follow_up_refinement' => [
                'question' => 'Which fuel do you prefer?',
                'options' => ['Pellet', 'Charcoal'],
                'choices' => [['label' => 'Pellet', 'filter' => ['name' => 'fuel_type', 'value' => ['Pellet']]]],
            ],
        ];
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

    public function test_search_overview_posts_to_the_agent_host_with_identity_and_backend_headers(): void
    {
        Http::fake(['agent.cnstrc.com/v1/agent_insights/search*' => Http::response($this->insightResponse())]);
        $this->actAsShopperRequest(['ConstructorioID_client_id' => 'client-abc', 'ConstructorioID_session_id' => '4']);

        $overview = $this->insights()->searchOverview(' smoker for a small patio ');

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $request->method() === 'POST'
                && $this->pathOf($request) === '/v1/agent_insights/search'
                && $request->header('Accept') === ['application/json']
                && $request->header('x-cnstrc-token') === ['backend_security_token']
                && ! $request->hasHeader('Authorization')
                && $query['key'] === ['key_test']
                && $query['i'] === ['client-abc']
                && $query['s'] === ['4']
                && $query['guard'] === ['true']
                && ! isset($query['num_result_events'])
                && $request->data() === [
                    'shopper_input' => 'smoker for a small patio',
                    'page_context' => ['page_type' => 'home'],
                ];
        });

        $this->assertTrue($overview->isComplete());
        $this->assertTrue($overview->hasContent());
        $this->assertSame('insight-1', $overview->resultId);
        $this->assertSame('Compact pellet smokers suit a small patio.', $overview->message);
        $this->assertSame('Small-space smokers', $overview->itemResults[0]['title']);
        $this->assertSame('TRG-RANGER', $overview->products()[0]['id']);
        // Clicks are tracked against the underlying Constructor response.
        $this->assertSame('search-result-9', $overview->products()[0]['result_id']);
        $this->assertSame('https://store.test/guides/smokers', $overview->articles[0]['url']);
        $this->assertSame(['Pellet', 'Charcoal'], $overview->followUpOptions());
        $this->assertSame('fuel_type', $overview->followUpChoices()[0]['filter']['name']);
    }

    public function test_a_declined_query_is_not_an_error(): void
    {
        $log = $this->spyOnLog();
        Http::fake(['agent.cnstrc.com/*' => Http::response(['detail' => 'Not suitable'], 422)]);

        $overview = $this->insights()->searchOverview('order status 1234');

        $this->assertTrue($overview->isDeclined());
        $this->assertFalse($overview->hasContent());
        $log->shouldNotHaveReceived('warning');
    }

    public function test_page_decorations_degrade_gracefully_and_are_never_retried(): void
    {
        $log = $this->spyOnLog();
        Http::fake(['agent.cnstrc.com/*' => Http::response(['message' => 'boom'], 503)]);

        $overview = $this->insights()->searchOverview('smoker');
        $set = $this->insights()->recommendations('TRG-780', 'complementary_pod');
        $refined = $this->insights()->refineListing(['search_term' => 'grill'], 'under $500');

        foreach ([$overview, $set, $refined] as $insight) {
            $this->assertSame(AgentInsight::STATUS_UNAVAILABLE, $insight->status);
        }
        // A retried POST would add a second turn to the shopper's thread.
        Http::assertSentCount(3);
        $log->shouldHaveReceived('warning')->times(3);
    }

    public function test_page_decorations_throw_when_configured_to(): void
    {
        $this->reconfigure(['constructor.throw_exceptions' => true]);
        Http::fake(['agent.cnstrc.com/*' => Http::response(['message' => 'boom'], 500)]);

        $this->expectException(ApiException::class);

        $this->insights()->searchOverview('smoker');
    }

    public function test_chat_uses_the_configured_domain_and_continues_a_thread(): void
    {
        Http::fake(['agent.cnstrc.com/*' => Http::response($this->insightResponse())]);

        $reply = $this->insights()->chat('Electric instead', [
            'thread_id' => 'thread-1',
            'page_context' => ['page_type' => 'plp', 'search_term' => 'smoker', 'filters' => ['brand' => ['Traeger', 'Weber']]],
            'prices' => [['item_id' => 'TRG-780', 'amount' => '899.00', 'currency' => 'USD', 'expires_at' => '2026-12-01T00:00:00Z', 'source' => 'integration']],
            'qs' => ['sort_by' => 'price'],
            'num_results_per_event' => 4,
        ]);

        $this->assertSame('1f6b8d2e-0000-4000-8000-000000000001', $reply->threadId);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);
            $body = $request->data();

            return $this->pathOf($request) === '/v1/agent_insights/chat'
                && $query['thread_id'] === ['thread-1']
                && $query['qs'] === ['{"sort_by":"price"}']
                && $query['num_results_per_event'] === ['4']
                && $body['shopper_input'] === 'Electric instead'
                && $body['parameters'] === ['domain' => 'test-agent-domain']
                && $body['page_context'] === [
                    'page_type' => 'plp',
                    'search_term' => 'smoker',
                    'filters' => [['name' => 'brand', 'value' => ['Traeger', 'Weber']]],
                ]
                && $body['request_context']['prices'][0]['amount'] === '899.00';
        });
    }

    public function test_chat_needs_a_domain(): void
    {
        $this->reconfigure(['constructor.agent.domain' => null]);
        Http::fake();

        $this->expectException(ConfigurationException::class);

        $this->insights()->chat('Hello');
    }

    public function test_answer_question_is_about_one_product(): void
    {
        Http::fake(['agent.cnstrc.com/*' => Http::response(['result_id' => 'qna-1', 'status' => 'partial', 'message' => 'Yes, it'])]);

        $answer = $this->insights()->answerQuestion('Does it fit a 20 lb tank?', 'WEB/E325', ['page_context' => ['variation_id' => 'WEB/E325-BLK']]);

        $this->assertTrue($answer->isPartial());
        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/v1/agent_insights/qna'
            && $request->data() === [
                'shopper_input' => 'Does it fit a 20 lb tank?',
                'page_context' => ['page_type' => 'pdp', 'item_id' => 'WEB/E325', 'variation_id' => 'WEB/E325-BLK'],
            ]);
    }

    public function test_argument_mistakes_throw_before_anything_is_sent(): void
    {
        Http::fake();
        $cases = [
            fn () => $this->insights()->answerQuestion('  ', 'TRG-780'),
            fn () => $this->insights()->answerQuestion('Is it waterproof?', ''),
            fn () => $this->insights()->recommendations('TRG-780', ''),
            fn () => $this->insights()->recommendations('TRG-780', 'complementary_pod', ['default_title' => str_repeat('x', 101)]),
            fn () => $this->insights()->refineListing([], 'only white'),
            fn () => $this->insights()->refineListing(['page_type' => 'pdp', 'search_term' => 'grill']),
            fn () => $this->insights()->refineListing(['collection_id' => 'summer'], 'only white'),
            fn () => $this->insights()->searchOverview('smoker', ['page_context' => ['page_type' => 'checkout']]),
            fn () => $this->insights()->chat('Hi', ['page_context' => ['page_type' => 'pdp']]),
        ];

        foreach ($cases as $index => $case) {
            try {
                $case();
                $this->fail("Case {$index} should have thrown.");
            } catch (InvalidArgumentException) {
                // Expected, even though failures are logged rather than thrown by default.
            }
        }

        Http::assertNothingSent();
    }

    public function test_recommendations_send_the_strategy_refinement_and_page_filters(): void
    {
        Http::fake(['agent.cnstrc.com/*' => Http::response($this->insightResponse())]);

        $this->insights()->recommendations('TRG-780', 'complementary_pod', [
            'refinement' => 'Under $50',
            'default_title' => 'Goes well with',
            'page_context' => [
                'filters' => ['color' => 'Black', 'in_stock' => true],
                'range_filters' => ['price' => ['max' => 50]],
                'filter_match_types' => ['color' => 'none'],
            ],
        ]);

        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/v1/agent_insights/recommendations'
            && $request->data() === [
                'shopper_input' => 'Under $50',
                'page_context' => [
                    'page_type' => 'pdp',
                    'item_id' => 'TRG-780',
                    'filters' => [
                        ['name' => 'color', 'value' => ['Black'], 'match_type' => 'none'],
                        ['name' => 'in_stock', 'value' => ['True']],
                        ['name' => 'price', 'range' => ['-inf', '50']],
                    ],
                ],
                'parameters' => ['strategy' => 'complementary_pod', 'default_title' => 'Goes well with'],
            ]);
    }

    public function test_refine_listing_round_trips_the_refined_listing(): void
    {
        $refined = ['page_type' => 'plp', 'browse_filter' => ['name' => 'group_id', 'value' => 'grills'], 'filters' => [['name' => 'color', 'value' => ['white']]]];
        Http::fake(['agent.cnstrc.com/*' => Http::response(['result_id' => 'r-1', 'status' => 'complete', 'item_results' => [[
            'response' => ['result_id' => 'browse-1', 'results' => []],
            'listing' => ['page_context' => $refined, 'added' => [['name' => 'color', 'value' => ['white']]], 'removed' => []],
        ]]])]);

        $first = $this->insights()->refineListing(['group_id' => 'grills'], 'only white');
        $this->insights()->refineListing($first->refinedListing() ?? []);

        $this->assertSame($refined, $first->refinedListing());
        $sent = $this->sentRequests();
        $this->assertSame([
            'shopper_input' => 'only white',
            'page_context' => ['page_type' => 'plp', 'browse_filter' => ['name' => 'group_id', 'value' => 'grills']],
        ], $sent[0]->data());
        // The refined listing goes back as it came, with no shopper input.
        $this->assertSame(['page_context' => $refined], $sent[1]->data());
    }

    public function test_chat_streaming_forwards_events_and_adds_products(): void
    {
        Http::fake(['agent.cnstrc.com/*' => Http::response($this->sse([
            ['start', ['event' => 'start', 'result_id' => 'insight-1']],
            ['ping', ['event' => 'ping']],
            ['message', ['event' => 'message', 'text' => 'Here are some options.']],
            ['item_results', ['event' => 'item_results', 'response' => ['result_id' => 'search-1', 'results' => [['value' => 'Ranger', 'data' => ['id' => 'TRG-RANGER']]]]]],
            ['end', ['event' => 'end']],
        ]), 200, ['Content-Type' => 'text/event-stream'])]);

        $received = [];
        $streamed = $this->insights()->chatStreaming('A smoker', function (string $event, array $data) use (&$received) {
            $received[] = [$event, $data];
        });

        $this->assertTrue($streamed);
        $this->assertSame(['start', 'message', 'item_results', 'end'], array_column($received, 0));
        $this->assertSame('TRG-RANGER', $received[2][1]['products'][0]['id']);
        $this->assertSame('search-1', $received[2][1]['products'][0]['result_id']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->header('Accept') === ['text/event-stream']
            && $request->data()['parameters'] === ['domain' => 'test-agent-domain']);
    }

    public function test_streaming_reports_declines_errors_and_truncation(): void
    {
        Http::fakeSequence('agent.cnstrc.com/*')
            ->push(['detail' => 'Not suitable'], 422)
            ->push($this->sse([
                ['start', ['event' => 'start']],
                ['server_error', ['event' => 'server_error', 'status_code' => 503, 'message' => 'Upstream unavailable']],
            ]), 200, ['Content-Type' => 'text/event-stream'])
            ->push($this->sse([['start', ['event' => 'start']], ['message', ['event' => 'message', 'text' => 'Cut']]]), 200, ['Content-Type' => 'text/event-stream']);

        $this->assertFalse($this->insights()->searchOverviewStreaming('order status', fn () => null));

        $events = [];
        try {
            $this->insights()->searchOverviewStreaming('smoker', function (string $event) use (&$events) {
                $events[] = $event;
            });
            $this->fail('A server_error event should throw.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame(['start', 'server_error'], $events);
        }

        $this->expectException(ConnectionException::class);
        $this->insights()->searchOverviewStreaming('smoker', fn () => null);
    }
}
