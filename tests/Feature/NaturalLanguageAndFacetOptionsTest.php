<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Support\RequestContext;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * SearchService::naturalLanguageSearch() (/v1/search/natural_language/{query})
 * and SearchService::getFacetsWithOptions() (/browse/facets_with_options).
 */
class NaturalLanguageAndFacetOptionsTest extends TestCase
{
    private function service(): SearchService
    {
        return $this->app->make(SearchService::class);
    }

    public function test_natural_language_search_calls_the_documented_endpoint_with_identity(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response([
            'request' => ['term' => 'gas grills'],
            'result_id' => 'nl-result-1',
            'response' => [
                'total_num_results' => 3,
                'results' => [['value' => 'Spirit E-325', 'data' => ['id' => 'WEB-E325', 'price' => 599]]],
                'facets' => [],
            ],
        ])]);
        $this->actAsShopperRequest([RequestContext::CLIENT_ID_COOKIE => 'client-abc', RequestContext::SESSION_ID_COOKIE => '2']);

        $results = $this->service()->naturalLanguageSearch('show me gas grills under 500/600 dollars', ['brand' => 'Weber'], [
            'per_page' => 12,
            'user_segments' => ['vip'],
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $this->pathOf($request) === '/v1/search/natural_language/show%20me%20gas%20grills%20under%20500%2F600%20dollars'
                && $query['key'] === ['key_test']
                && $query['section'] === ['Products']
                && $query['num_results_per_page'] === ['12']
                && $query['page'] === ['1']
                && $query['filters[brand]'] === ['Weber']
                && $query['us'] === ['vip']
                && $query['i'] === ['client-abc']
                && $query['s'] === ['2']
                && $query['c'] === ['cio-be-web-bbq-test-store']
                && $request->header('x-cnstrc-token') === ['backend_security_token']
                && $request->header('X-Forwarded-For') === ['203.0.113.42']
                && ! $request->hasHeader('Authorization');
        });

        $this->assertSame('nl-result-1', $results->resultId);
        $this->assertSame(3, $results->total);
        $this->assertSame('WEB-E325', $results->products[0]['id']);
        $this->assertSame(['term' => 'gas grills'], $results->trackingData()['request']);
    }

    public function test_natural_language_search_follows_redirect_rules(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response([
            'request' => [],
            'response' => ['redirect' => ['data' => ['url' => '/gift-cards'], 'matched_terms' => ['gift card']]],
        ])]);

        $results = $this->service()->naturalLanguageSearch('I want a gift card');

        $this->assertTrue($results->isRedirect());
        $this->assertSame('/gift-cards', $results->redirectUrl());
    }

    public function test_natural_language_search_with_a_blank_query_makes_no_request(): void
    {
        Http::fake();

        $this->assertTrue($this->service()->naturalLanguageSearch('   ')->isEmpty());

        Http::assertNothingSent();
    }

    public function test_natural_language_search_returns_empty_results_on_failure(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['message' => 'boom'], 400)]);
        $this->spyOnLog();

        $this->assertTrue($this->service()->naturalLanguageSearch('cheap smoker')->isEmpty());
    }

    public function test_facets_with_options_requests_a_page_and_maps_facets(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response([
            'result_id' => 'facets-result-1',
            'response' => [
                'total_num_results' => 14,
                'facets' => [
                    ['name' => 'brand', 'display_name' => 'Brand', 'type' => 'multiple', 'hidden' => false, 'data' => [],
                        'options' => [
                            ['value' => 'Weber', 'display_name' => 'Weber', 'count' => 12, 'status' => '', 'data' => []],
                            ['value' => 'Traeger', 'display_name' => 'Traeger', 'count' => 7, 'status' => '', 'data' => []],
                        ]],
                    ['name' => 'price', 'display_name' => 'Price', 'type' => 'range', 'hidden' => false, 'data' => [], 'min' => 99, 'max' => 4999],
                ],
            ],
        ])]);
        $this->actAsShopperRequest([RequestContext::CLIENT_ID_COOKIE => 'client-abc']);

        $page = $this->service()->getFacetsWithOptions([
            'page' => 1,
            'per_page' => 50,
            'fmt_options' => ['show_hidden_facets' => true],
            'user_segments' => 'vip',
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $this->pathOf($request) === '/browse/facets_with_options'
                && $query['section'] === ['Products']
                && $query['num_results_per_page'] === ['10'] // capped at Constructor's maximum
                && $query['page'] === ['1']
                && ! isset($query['offset'])
                && $query['fmt_options[show_hidden_facets]'] === ['true']
                && $query['us'] === ['vip']
                && $query['i'] === ['client-abc']
                && $request->header('x-cnstrc-token') === ['backend_security_token'];
        });

        $this->assertSame(['Weber' => 12, 'Traeger' => 7], $page['facets']['brand']['values']);
        $this->assertSame('checkbox_list', $page['facets']['brand']['type']);
        $this->assertSame(99, $page['facets']['price']['min']);
        $this->assertSame(14, $page['total']);
        $this->assertSame(10, $page['per_page']);
        $this->assertTrue($page['has_more']);
        $this->assertSame('facets-result-1', $page['result_id']);
    }

    public function test_facets_with_options_sends_offset_instead_of_page(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['total_num_results' => 14, 'facets' => []]])]);

        $page = $this->service()->getFacetsWithOptions(['offset' => 10, 'per_page' => 5]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $query['offset'] === ['10'] && ! isset($query['page']) && $query['num_results_per_page'] === ['5'];
        });

        $this->assertSame(3, $page['page']);
        $this->assertFalse($page['has_more']); // 10 skipped + 5 on this page >= 14
    }

    public function test_facets_with_options_returns_an_empty_page_on_failure(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['message' => 'boom'], 500)]);
        $this->spyOnLog();

        $page = $this->service()->getFacetsWithOptions(['page' => 2]);

        $this->assertSame(['facets' => [], 'total' => 0, 'page' => 2, 'per_page' => 10, 'has_more' => false, 'result_id' => null], $page);
    }

    public function test_facets_with_options_throws_when_configured(): void
    {
        $this->reconfigure(['constructor.throw_exceptions' => true]);
        Http::fake(['ac.cnstrc.com/*' => Http::response(['message' => 'bad'], 400)]);

        $this->expectException(ApiException::class);

        $this->service()->getFacetsWithOptions();
    }

    public function test_the_facade_delegates_both_methods(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['total_num_results' => 0, 'facets' => [], 'results' => []]])]);

        Constructor::naturalLanguageSearch('smoker');
        Constructor::getFacetsWithOptions();

        Http::assertSentCount(2);
    }
}
