<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\Services\RecommendationService;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class RecommendationServiceTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function podResponse(): array
    {
        return [
            'request' => ['request_id' => 'req-9'],
            'result_id' => 'rec-result-1',
            'response' => [
                'pod' => ['id' => 'pdp-complementary', 'display_name' => 'Complete your cookout'],
                'total_num_results' => 1,
                'results' => [['value' => 'Grill Cover', 'strategy' => ['id' => 'complementary_items'], 'data' => ['id' => 'COVER-1']]],
            ],
        ];
    }

    public function test_item_recommendations_send_every_item_id_and_filters(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response($this->podResponse())]);
        $this->actAsShopperRequest();

        $results = app(RecommendationService::class)->getItemRecommendations(
            'pdp-complementary',
            ['GRILL-1', 'GRILL-2'],
            ['num_results' => 4, 'filters' => ['brand' => 'Weber']],
        );

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $this->pathOf($request) === '/recommendations/v1/pods/pdp-complementary'
                && $query['item_id'] === ['GRILL-1', 'GRILL-2']
                && $query['num_results'] === ['4']
                && $query['filters[brand]'] === ['Weber']
                && ! isset($query['ui']);
        });

        $this->assertSame('Complete your cookout', $results->title, 'Pod details live under response.pod');
        $this->assertSame('rec-result-1', $results->resultId);
        $this->assertSame('COVER-1', $results->products[0]['id']);
        $this->assertSame(['id' => 'complementary_items'], $results->products[0]['strategy']);
    }

    public function test_recommendations_work_outside_an_http_request(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response($this->podResponse())]);

        $results = app(RecommendationService::class)->getRecommendations('home-bestsellers');

        $this->assertCount(1, $results->products);
        Http::assertSent(fn (Request $request) => ! isset($this->queryOf($request)['ui']) && ! isset($this->queryOf($request)['i']));
    }

    public function test_failure_returns_an_empty_pod(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response('not found', 404)]);

        $results = app(RecommendationService::class)->getRecommendations('missing-pod');

        $this->assertTrue($results->isEmpty());
        $this->assertSame('missing-pod', $results->podId);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageResponse(): array
    {
        return [
            'request' => ['request_id' => 'page-req-1'],
            'result_id' => 'page-request-result',
            'response' => [
                'page_id' => 'pdp',
                'display_name' => 'Product page',
                'page_type' => 'pdp',
                'experiments' => [['id' => 'exp-1', 'cell' => 'test']],
                'pods' => [
                    [
                        'pod_id' => 'pdp-similar',
                        'request' => ['num_results' => 12],
                        'result_id' => 'similar-result',
                        'response' => [
                            'pod' => ['id' => 'pdp-similar', 'display_name' => 'Similar grills'],
                            'total_num_results' => 1,
                            'results' => [['value' => 'Pro 575', 'is_slotted' => false, 'strategy' => ['id' => 'alternative_items'], 'data' => ['id' => 'GRILL-2']]],
                        ],
                    ],
                    [
                        'pod_id' => 'pdp-complementary',
                        'request' => [],
                        'result_id' => 'complementary-result',
                        'response' => [
                            'pod' => ['id' => 'pdp-complementary', 'display_name' => 'Complete your cookout'],
                            'total_num_results' => 0,
                            'results' => [],
                        ],
                    ],
                    'not-a-pod',
                ],
            ],
        ];
    }

    public function test_page_request_sends_shared_parameters_overrides_and_backend_headers(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response($this->pageResponse())]);
        $this->actAsShopperRequest(['ConstructorioID_client_id' => 'client-1', 'ConstructorioID_session_id' => '3']);

        Constructor::getRecommendationPage('pdp page/1', [
            'item_id' => 'GRILL-1',
            'num_results' => 6,
            'filters' => ['brand' => 'Weber'],
            'pod_overrides' => [
                'pdp-similar' => ['num_results' => 12, 'filters' => ['in_stock' => true], 'range_filters' => ['price' => ['max' => 500]]],
                'pdp-complementary' => ['pre_filter_expression' => ['name' => 'brand', 'value' => 'Weber'], 'hidden_fields' => ['margin']],
            ],
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $this->pathOf($request) === '/recommendations/v1/pages/pdp%20page%2F1'
                && $query['item_id'] === ['GRILL-1']
                && $query['num_results'] === ['6']
                && $query['filters[brand]'] === ['Weber']
                && $query['pod_overrides[pdp-similar][num_results]'] === ['12']
                && $query['pod_overrides[pdp-similar][filters][in_stock]'] === ['True']
                && $query['pod_overrides[pdp-similar][filters][price]'] === ['-inf-500']
                && $query['pod_overrides[pdp-complementary][pre_filter_expression]'] === ['{"name":"brand","value":"Weber"}']
                && $query['pod_overrides[pdp-complementary][fmt_options][hidden_fields]'] === ['margin']
                && $query['key'] === ['key_test']
                && $query['i'] === ['client-1']
                && $query['s'] === ['3']
                && $request->header('x-cnstrc-token') === ['backend_security_token']
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_page_num_results_is_only_sent_when_given_and_is_capped(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response($this->pageResponse())]);

        $service = app(RecommendationService::class);
        $service->getPage('home');
        $service->getPage('home', ['num_results' => 500, 'pod_overrides' => ['home-hero' => ['num_results' => 0]]]);

        $sent = Http::recorded()->map(fn (array $pair) => $this->queryOf($pair[0]))->all();

        $this->assertArrayNotHasKey('num_results', $sent[0], "Without num_results, each pod keeps Constructor's default");
        $this->assertSame(['100'], $sent[1]['num_results']);
        $this->assertSame(['0'], $sent[1]['pod_overrides[home-hero][num_results]'], 'Constructor allows 0 for a pod');
    }

    public function test_page_maps_every_pod_with_its_own_result_id(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response($this->pageResponse())]);

        $page = app(RecommendationService::class)->getPage('pdp', ['item_id' => 'GRILL-1']);

        $this->assertSame('pdp', $page->pageId);
        $this->assertSame('Product page', $page->title);
        $this->assertSame('pdp', $page->pageType);
        $this->assertSame('page-request-result', $page->resultId);
        $this->assertCount(2, $page->pods, 'Malformed pod entries are skipped');
        $this->assertSame(['pdp-similar', 'pdp-complementary'], array_map(fn ($pod) => $pod->podId, $page->pods), 'Page order is kept');

        $similar = $page->pod('pdp-similar');
        $this->assertNotNull($similar);
        $this->assertSame('Similar grills', $similar->title);
        $this->assertSame('similar-result', $similar->resultId, 'Each pod is tracked with its own result_id');
        $this->assertSame('GRILL-2', $similar->products[0]['id']);
        $this->assertSame(['id' => 'alternative_items'], $similar->products[0]['strategy']);
        $this->assertSame([['id' => 'exp-1', 'cell' => 'test']], $similar->metadata['experiments'], 'Page experiments are copied to each pod');
        $this->assertSame(['num_results' => 12], $similar->metadata['request']);

        $this->assertNull($page->pod('missing'));
        $this->assertSame(['pdp-similar'], array_map(fn ($pod) => $pod->podId, $page->podsWithResults()));
        $this->assertTrue($page->hasRecommendations());
        $this->assertSame('complementary-result', $page->toArray()['pods'][1]['result_id']);
    }

    public function test_page_failure_returns_an_empty_page_and_logs(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response('not found', 404)]);
        $log = $this->spyOnLog();

        $page = app(RecommendationService::class)->getPage('missing-page');

        $this->assertTrue($page->isEmpty());
        $this->assertSame('missing-page', $page->pageId);
        $this->assertSame([], $page->pods);
        $log->shouldHaveReceived('warning')->once();
    }

    public function test_page_rejects_page_wide_settings_in_pod_overrides_before_sending(): void
    {
        Http::fake();

        try {
            app(RecommendationService::class)->getPage('pdp', [
                'pod_overrides' => ['pdp-similar' => ['item_id' => 'GRILL-9']],
            ]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('item_id', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_page_rejects_pod_ids_with_brackets(): void
    {
        Http::fake();
        $this->expectException(InvalidArgumentException::class);

        app(RecommendationService::class)->getPage('pdp', [
            'pod_overrides' => ['pod][num_results' => ['num_results' => 1]],
        ]);
    }
}
