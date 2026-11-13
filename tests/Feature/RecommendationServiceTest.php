<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Services\RecommendationService;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class RecommendationServiceTest extends TestCase
{
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

        $results = $this->app->make(RecommendationService::class)->getItemRecommendations(
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

        $results = $this->app->make(RecommendationService::class)->getRecommendations('home-bestsellers');

        $this->assertCount(1, $results->products);
        Http::assertSent(fn (Request $request) => ! isset($this->queryOf($request)['ui']) && ! isset($this->queryOf($request)['i']));
    }

    public function test_failure_returns_an_empty_pod(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response('not found', 404)]);

        $results = $this->app->make(RecommendationService::class)->getRecommendations('missing-pod');

        $this->assertTrue($results->isEmpty());
        $this->assertSame('missing-pod', $results->podId);
    }
}
