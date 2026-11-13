<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\RequestContext;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class SearchServiceTest extends TestCase
{
    private function service(): SearchService
    {
        return $this->app->make(SearchService::class);
    }

    private function searchResponse(array $overrides = []): array
    {
        return array_replace_recursive([
            'request' => ['request_id' => 'req-1'],
            'result_id' => 'result-123',
            'response' => [
                'total_num_results' => 42,
                'results' => [[
                    'value' => 'Pro 780 Pellet Grill',
                    'matched_terms' => ['pellet'],
                    'is_slotted' => true,
                    'labels' => ['is_sponsored' => true],
                    'data' => ['id' => 'TRG-780', 'url' => '/p/trg-780', 'image_url' => 'https://img/780.jpg', 'price' => 999, 'inventory' => 4],
                ]],
                'facets' => [
                    ['name' => 'brand', 'display_name' => 'Brand', 'type' => 'multiple', 'hidden' => false, 'data' => [],
                        'options' => [['value' => 'Traeger', 'display_name' => 'Traeger', 'count' => 12, 'status' => 'selected', 'data' => []]]],
                    ['name' => 'price', 'display_name' => 'Price', 'type' => 'range', 'hidden' => false, 'data' => [], 'min' => 199, 'max' => 2999],
                ],
                'sort_options' => [['sort_by' => 'price', 'sort_order' => 'ascending', 'display_name' => 'Price: low to high']],
            ],
        ], $overrides);
    }

    public function test_search_calls_the_v1_endpoint_with_an_encoded_query_and_shopper_identity(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response($this->searchResponse())]);
        $this->actAsShopperRequest([RequestContext::CLIENT_ID_COOKIE => 'client-abc', RequestContext::SESSION_ID_COOKIE => '4']);

        $this->service()->search('1/2" grill grate #5', ['brand' => ['Weber', 'Traeger']], [
            'page' => 2,
            'per_page' => 12,
            'range_filters' => ['price' => ['min' => 100]],
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $this->pathOf($request) === '/v1/search/1%2F2%22%20grill%20grate%20%235'
                && $query['key'] === ['key_test']
                && $query['page'] === ['2']
                && $query['num_results_per_page'] === ['12']
                && $query['filters[brand]'] === ['Weber', 'Traeger']
                && $query['filters[price]'] === ['100-inf']
                && $query['i'] === ['client-abc']
                && $query['s'] === ['4']
                && $query['c'] === ['cio-be-web-bbq-test-store']
                && ! isset($query['ui'])
                && $request->header('x-cnstrc-token') === ['backend_security_token']
                && $request->header('X-Forwarded-For') === ['203.0.113.42']
                && $request->header('User-Agent') === ['Mozilla/5.0 (Shopper Browser)']
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_search_results_are_mapped(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response($this->searchResponse())]);

        $results = $this->service()->search('pellet grill');

        $this->assertSame('result-123', $results->resultId, 'result_id is top-level in the response');
        $this->assertSame(42, $results->total);
        $this->assertSame('TRG-780', $results->products[0]['id']);
        $this->assertSame(4, $results->products[0]['_raw']['inventory']);
        $this->assertTrue($results->products[0]['is_slotted']);
        $this->assertSame(['is_sponsored' => true], $results->products[0]['labels']);
        $this->assertSame(['Traeger' => 12], $results->facets['brand']['values']);
        $this->assertTrue($results->facets['brand']['options'][0]['selected']);
        $this->assertSame('range', $results->facets['price']['type'], 'Range facets have no options but must be kept');
        $this->assertSame([199, 2999], [$results->facets['price']['min'], $results->facets['price']['max']]);
        $this->assertSame('price', $results->sortOptions[0]['sort_by']);
    }

    public function test_redirect_rules_are_exposed(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response([
            'result_id' => 'r-1',
            'response' => ['redirect' => ['data' => ['url' => '/brands/weber', 'rule_id' => 77], 'matched_terms' => ['weber']]],
        ])]);

        $results = $this->service()->search('weber');

        $this->assertTrue($results->isRedirect());
        $this->assertSame('/brands/weber', $results->redirectUrl());
    }

    public function test_empty_query_makes_no_request(): void
    {
        Http::fake();

        $this->assertTrue($this->service()->search('   ')->isEmpty());

        Http::assertNothingSent();
    }

    public function test_browse_single_encodes_filter_values(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response($this->searchResponse())]);

        $this->service()->browse('group_id', 'Grills & Smokers');

        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/browse/group_id/Grills%20%26%20Smokers');
    }

    public function test_browse_items_by_id(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response($this->searchResponse())]);

        $this->service()->browseItems(['A-1', 'B-2']);

        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/browse/items'
            && $this->queryOf($request)['ids'] === ['A-1', 'B-2']);
    }

    public function test_autocomplete_uses_documented_section_limits(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response([
            'result_id' => 'ac-1',
            'sections' => [
                'Search Suggestions' => [['value' => 'weber grill', 'matched_terms' => ['weber'], 'data' => []]],
                'Products' => [['value' => 'Weber Spirit', 'data' => ['id' => 'W-1']]],
            ],
        ])]);

        $results = $this->service()->autocomplete('weber', [
            'sections' => ['suggestions' => ['limit' => 4], 'products' => ['enabled' => false]],
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $this->pathOf($request) === '/v1/autocomplete/weber'
                && $query['num_section_results_per_page[Search Suggestions]'] === ['4']
                && $query['num_section_results_per_page[Products]'] === ['0'];
        });
        $this->assertSame('weber grill', $results->suggestions[0]['term']);
        $this->assertSame('W-1', $results->products[0]['id']);
        $this->assertSame('ac-1', $results->metadata['result_id']);
    }

    public function test_collections_use_public_browse_endpoints_without_the_api_token(): void
    {
        Http::fake([
            'ac.cnstrc.com/browse/collections*' => Http::response(['response' => [
                'collections' => [['id' => 'summer-bbq', 'display_name' => 'Summer BBQ', 'data' => ['image_url' => 'https://img/summer.jpg']]],
                'total_num_results' => 1,
            ]]),
        ]);

        $collections = $this->service()->getCollections(['max_items' => 5]);

        $this->assertSame([['id' => 'summer-bbq', 'name' => 'Summer BBQ', 'description' => null, 'image' => 'https://img/summer.jpg',
            'data' => ['image_url' => 'https://img/summer.jpg'], 'start_time' => null, 'end_time' => null, 'active_overriding_collection' => null]], $collections);
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization')
            && $this->queryOf($request)['num_results_per_page'] === ['5']);
    }

    public function test_browse_groups_skip_the_all_root(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['groups' => [[
            'group_id' => 'all', 'display_name' => 'All', 'children' => [
                ['group_id' => 'grills', 'display_name' => 'Grills', 'count' => 300, 'children' => [['group_id' => 'gas', 'display_name' => 'Gas Grills']]],
                ['group_id' => 'smokers', 'display_name' => 'Smokers', 'count' => 120, 'children' => []],
            ],
        ]]]])]);

        $groups = $this->service()->getBrowseGroups(['max_items' => 1, 'max_children' => 5]);

        $this->assertCount(1, $groups);
        $this->assertSame('grills', $groups[0]['id']);
        $this->assertSame('gas', $groups[0]['children'][0]['id']);
    }

    public function test_failures_return_empty_results_by_default(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['message' => 'boom'], 500)]);

        $results = $this->service()->search('grill');

        $this->assertTrue($results->isEmpty());
        Http::assertSentCount(2); // 5xx is retried, up to retry_attempts (2).
    }

    public function test_failures_throw_when_configured(): void
    {
        $this->app['config']->set('constructor.throw_exceptions', true);
        $this->app->forgetInstance(ConstructorConfig::class);
        Http::fake(['ac.cnstrc.com/*' => Http::response(['message' => 'unknown filter'], 400)]);

        try {
            $this->service()->search('grill');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }

        Http::assertSentCount(1); // 4xx is never retried.
    }

    public function test_missing_api_key_always_throws(): void
    {
        $this->app['config']->set('constructor.api_key', null);
        $this->app->forgetInstance(ConstructorConfig::class);
        Http::fake();

        $this->expectException(ConfigurationException::class);

        $this->app->make(SearchService::class)->search('grill');
    }

    public function test_connection_errors_do_not_leak_the_request_url(): void
    {
        $this->app['config']->set('constructor.throw_exceptions', true);
        $this->app->forgetInstance(ConstructorConfig::class);
        $this->actAsShopperRequest([RequestContext::CLIENT_ID_COOKIE => 'secret-client-id']);
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 7: Failed to connect for https://ac.cnstrc.com/v1/search/my%20query?key=key_test&i=secret-client-id'
        ));

        try {
            $this->service()->search('my query');
            $this->fail('Expected ConnectionException');
        } catch (\ConstructorIO\Laravel\Exceptions\ConnectionException $e) {
            $this->assertSame('Could not connect to Constructor (ac.cnstrc.com): cURL error 7: Failed to connect', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_browse_items_rejects_more_ids_than_constructor_allows(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        $this->service()->browseItems(array_map(fn (int $i) => "SKU-{$i}", range(1, 101)));
    }

    public function test_autocomplete_sends_per_section_filters_and_pre_filters(): void
    {
        Http::fake(['*' => Http::response(['sections' => []])]);

        $this->service()->autocomplete('grill', [
            'filters' => ['in_stock' => true],
            'section_filters' => ['Products' => ['brand' => ['Weber', 'Traeger']]],
        ]);
        $this->service()->autocomplete('grill', [
            'section_pre_filter_expression' => ['Products' => ['name' => 'brand', 'value' => 'Weber']],
        ]);

        $requests = Http::recorded();
        $first = $this->queryOf($requests[0][0]);
        $this->assertSame(['True'], $first['filters[in_stock]'] ?? null);
        $this->assertSame(['Weber', 'Traeger'], $first['filters[Products][brand]'] ?? null);
        $this->assertSame(
            ['{"name":"brand","value":"Weber"}'],
            $this->queryOf($requests[1][0])['pre_filter_expression[Products]'] ?? null,
        );
    }

    public function test_autocomplete_rejects_global_and_per_section_pre_filters_together(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        $this->service()->autocomplete('grill', [
            'pre_filter_expression' => ['name' => 'brand', 'value' => 'Weber'],
            'section_pre_filter_expression' => ['Products' => ['name' => 'brand', 'value' => 'Weber']],
        ]);
    }

    public function test_autocomplete_section_limits_are_clamped(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['sections' => []])]);

        $this->service()->autocomplete('grill', ['results_per_section' => ['Products' => 500]]);

        Http::assertSent(fn (Request $request) => $this->queryOf($request)['num_section_results_per_page[Products]'] === ['50']);
    }

    public function test_sponsored_listings_are_flagged_from_either_sponsored_label(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['results' => [[
            'value' => 'Sponsored Smoker',
            'data' => ['id' => 'AD-1'],
            'labels' => ['is_retail_media' => true, 'sl_campaign_id' => 'camp-1', 'sl_campaign_owner' => 'Acme Grills'],
        ], ['value' => 'Organic Smoker', 'data' => ['id' => 'ORG-1']], [
            'value' => 'Promoted Smoker',
            'data' => ['id' => 'PROMO-1'],
            'labels' => ['is_sponsored' => true],
        ]]]])]);

        $products = $this->service()->search('smoker')->products;

        $this->assertTrue($products[0]['is_sponsored']);
        $this->assertSame('camp-1', $products[0]['sponsored_campaign_id']);
        $this->assertSame('Acme Grills', $products[0]['sponsored_campaign_owner']);
        $this->assertFalse($products[1]['is_sponsored']);
        // The older label from the response-structure docs counts too.
        $this->assertTrue($products[2]['is_sponsored']);
    }

    public function test_available_facets_fetch_every_page_and_respect_hidden_facets(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['facets' => [
            ['name' => 'brand', 'display_name' => 'Brand', 'type' => 'multiple', 'hidden' => false],
            ['name' => 'store_id', 'display_name' => 'Store', 'type' => 'single', 'hidden' => true],
            ['name' => 'group_id', 'type' => 'hierarchical'],
            ['display_name' => 'Malformed: no name'],
            null,
        ]]])]);

        $visible = $this->service()->getAvailableFacets();
        $withHidden = $this->service()->getAvailableFacets(['fmt_options' => ['show_hidden_facets' => true]]);

        $this->assertSame([['name' => 'brand', 'display_name' => 'Brand', 'type' => 'checkbox_list']], $visible);
        $this->assertSame(['brand', 'store_id'], array_column($withHidden, 'name'));
        $this->assertSame('single_select', $withHidden[1]['type']);
        Http::assertSent(fn (Request $request) => $this->queryOf($request)['num_results_per_page'] === ['1000']);
    }

    public function test_malformed_entries_in_responses_are_skipped(): void
    {
        Http::fake([
            'ac.cnstrc.com/browse/collections*' => Http::response(['response' => ['collections' => [null, ['id' => 'summer']]]]),
            'ac.cnstrc.com/*' => Http::response(['response' => [
                'results' => [null, ['value' => 'Grill', 'data' => ['id' => 'G-1']]],
                'facets' => [null, ['name' => 'brand', 'type' => 'multiple', 'options' => [null, ['value' => 'Weber', 'count' => 3]]]],
                'groups' => [null],
            ]]),
        ]);

        $results = $this->service()->search('grill');

        $this->assertSame('G-1', $results->products[0]['id']);
        $this->assertSame(['Weber' => 3], $results->facets['brand']['values']);
        $this->assertSame('summer', $this->service()->getCollections()[0]['id']);
    }

    public function test_offset_reports_the_page_it_falls_on(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['total_num_results' => 50, 'results' => []]])]);

        $results = $this->service()->search('grill', [], ['offset' => 48, 'per_page' => 24]);

        $this->assertSame(3, $results->page);
        $this->assertFalse($results->hasMore());
    }

    public function test_autocomplete_exposes_result_id(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['result_id' => 'ac-9', 'sections' => []])]);

        $this->assertSame('ac-9', $this->service()->autocomplete('web')->resultId);
    }

    public function test_zero_state_ignores_invalid_pod_params(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['groups' => [], 'results' => []]])]);

        $results = $this->service()->getZeroStateData(['recommendation_pod_id' => 'zs', 'recommendation_pod_params' => '"not an object"']);

        $this->assertFalse($results->hasPopularProducts());
    }
}
