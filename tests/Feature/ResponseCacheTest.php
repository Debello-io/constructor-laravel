<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Constructor;
use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Support\ResponseCache;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The opt-in response cache: what is cached, what never is, and how the
 * cache key is built.
 */
class ResponseCacheTest extends TestCase
{
    /** A category tree with the usual "All" root. */
    private const GROUPS = ['response' => ['groups' => [[
        'group_id' => 'all', 'display_name' => 'All', 'children' => [
            ['group_id' => 'grills', 'display_name' => 'Grills', 'children' => []],
        ],
    ]]]];

    /**
     * @param  array<string, mixed>  $settings  Extra config values.
     */
    private function enableCache(array $settings = []): void
    {
        $this->reconfigure(array_merge([
            'cache.default' => 'array',
            'constructor.cache.enabled' => true,
        ], $settings));
    }

    private function service(): SearchService
    {
        return app(SearchService::class);
    }

    public function test_caching_is_off_by_default(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(self::GROUPS)]);

        $this->service()->getBrowseGroups();
        $this->service()->getBrowseGroups();

        Http::assertSentCount(2);
        $this->assertFalse(app(ResponseCache::class)->enabled());
    }

    public function test_the_category_tree_is_served_from_the_cache_once_enabled(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(self::GROUPS)]);

        $first = $this->service()->getBrowseGroups();
        $second = $this->service()->getBrowseGroups();

        Http::assertSentCount(1);
        $this->assertSame('grills', $first[0]['id']);
        $this->assertSame($first, $second);
    }

    public function test_every_cached_method_is_cached(): void
    {
        $this->enableCache();
        Http::fake([
            'ac.cnstrc.com/browse/facets_with_options*' => Http::response(['response' => ['facets' => [], 'total_num_results' => 0]]),
            'ac.cnstrc.com/browse/facets*' => Http::response(['response' => ['facets' => [['name' => 'brand', 'type' => 'multiple']]]]),
            'ac.cnstrc.com/browse/facet_options*' => Http::response(['response' => ['facets' => [[
                'name' => 'brand', 'type' => 'multiple',
                'options' => [['value' => 'Weber', 'display_name' => 'Weber', 'count' => 3, 'data' => ['image_url' => 'https://img/weber.jpg']]],
            ]]]]),
            'ac.cnstrc.com/browse/collections*' => Http::response(['response' => ['collections' => [['id' => 'summer']]]]),
            'ac.cnstrc.com/browse/collection_id/*' => Http::response(['response' => ['collection' => ['id' => 'summer']]]),
        ]);

        foreach (range(1, 2) as $call) {
            $this->service()->getAvailableFacets();
            $this->service()->getFacetsWithOptions();
            $this->service()->getFacetValuesWithImages('brand');
            $this->service()->getCollections();
            $this->service()->getCollection('summer');
        }

        Http::assertSentCount(5);
    }

    public function test_personalized_reads_are_never_cached(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['results' => []]])]);

        $this->service()->search('grill');
        $this->service()->search('grill');
        $this->service()->browse('group_id', 'grills');
        $this->service()->browse('group_id', 'grills');

        Http::assertSentCount(4);
    }

    public function test_failures_are_not_cached(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::sequence()
            ->push(['message' => 'unavailable'], 503)
            ->push(['message' => 'unavailable'], 503)
            ->push(self::GROUPS)]);
        $this->spyOnLog();

        // Two attempts (one retry) fail, so the page gets an empty menu...
        $outage = $this->service()->getBrowseGroups();
        // ...and the next call asks Constructor again instead of reusing it.
        $recovered = $this->service()->getBrowseGroups();
        $cached = $this->service()->getBrowseGroups();

        $this->assertSame([], $outage);
        $this->assertSame('grills', $recovered[0]['id']);
        $this->assertSame($recovered, $cached);

        Http::assertSentCount(3);
    }

    public function test_a_failed_image_lookup_inside_a_cached_call_is_not_cached(): void
    {
        $this->enableCache(['constructor.retry_attempts' => 1]);
        Http::fake([
            'ac.cnstrc.com/browse/groups*' => Http::response(self::GROUPS),
            'ac.cnstrc.com/browse/group_id/*' => Http::sequence()
                ->push(['message' => 'unavailable'], 503)
                ->push(['response' => ['results' => [['value' => 'Pro 780', 'data' => ['id' => 'TRG-780', 'image_url' => 'https://img/780.jpg']]]]]),
        ]);
        $this->spyOnLog();

        $first = $this->service()->getBrowseGroups(['with_images' => true]);
        $second = $this->service()->getBrowseGroups(['with_images' => true]);
        $third = $this->service()->getBrowseGroups(['with_images' => true]);

        $this->assertNull($first[0]['image']);
        $this->assertSame('https://img/780.jpg', $second[0]['image']);
        $this->assertSame($second, $third);
        // groups + failed image, groups + image, then nothing: served from the cache.
        Http::assertSentCount(4);
    }

    public function test_a_call_can_skip_the_cache(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(self::GROUPS)]);

        $this->service()->getBrowseGroups(['cache' => false]);
        $this->service()->getBrowseGroups(['cache' => false]);

        Http::assertSentCount(2);
        // 'cache' is a package option, never an API parameter.
        Http::assertSent(fn (Request $request) => ! isset($this->queryOf($request)['cache']));
    }

    public function test_options_section_and_segments_get_their_own_entries(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['facets' => [], 'total_num_results' => 0]])]);

        $this->service()->getFacetsWithOptions(['page' => 1]);
        $this->service()->getFacetsWithOptions(['page' => 2]);
        $this->service()->getFacetsWithOptions(['section' => 'Recipes']);
        $this->service()->getFacetsWithOptions(['user_segments' => ['vip']]);
        $this->service()->getFacetsWithOptions(['user_segments' => ['wholesale']]);
        $this->service()->getFacetsWithOptions(['fmt_options' => ['hidden_facets' => ['store_id']]]);

        Http::assertSentCount(6);

        // Same options in a different order share one entry.
        $this->service()->getFacetsWithOptions(['user_segments' => ['vip'], 'section' => 'Products']);
        $this->service()->getFacetsWithOptions(['section' => 'Products', 'user_segments' => ['vip']]);

        Http::assertSentCount(7);
    }

    public function test_shopper_identity_does_not_split_the_cache(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['facets' => [], 'total_num_results' => 0]])]);

        $this->service()->getFacetsWithOptions(['client_id' => 'shopper-a', 'session_id' => 3]);
        $this->service()->getFacetsWithOptions(['client_id' => 'shopper-b', 'session_id' => 7]);

        Http::assertSentCount(1);
    }

    public function test_arguments_are_part_of_the_key(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['collection' => ['id' => 'x']]])]);

        $this->service()->getCollection('summer');
        $this->service()->getCollection('winter');
        $this->service()->getCollection('summer');

        Http::assertSentCount(2);
    }

    public function test_an_unknown_collection_is_cached_too(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => []])]);

        $this->assertNull($this->service()->getCollection('missing'));
        $this->assertNull($this->service()->getCollection('missing'));

        Http::assertSentCount(1);
    }

    public function test_different_api_keys_never_share_entries(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(self::GROUPS)]);

        $this->service()->getBrowseGroups();
        $this->reconfigure(['constructor.api_key' => 'key_other_index']);
        $this->service()->getBrowseGroups();

        Http::assertSentCount(2);
    }

    public function test_flush_cache_makes_every_entry_stale(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(self::GROUPS)]);

        $this->service()->getBrowseGroups();
        app(Constructor::class)->flushCache();
        $this->service()->getBrowseGroups();
        $this->service()->getBrowseGroups();

        Http::assertSentCount(2);
    }

    public function test_flush_cache_works_on_the_database_store(): void
    {
        // increment() on a missing key fails on this store, so flush must not rely on it.
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        $this->enableCache(['constructor.cache.store' => 'database', 'cache.stores.database.lock_connection' => null]);
        Http::fake(['ac.cnstrc.com/*' => Http::response(self::GROUPS)]);

        $this->service()->getBrowseGroups();
        app(Constructor::class)->flushCache();
        $this->service()->getBrowseGroups();

        Http::assertSentCount(2);
    }

    public function test_entries_from_before_a_flush_stay_stale_if_the_generation_is_evicted(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(self::GROUPS)]);

        $this->service()->getBrowseGroups();
        app(Constructor::class)->flushCache();
        $this->service()->getBrowseGroups();
        Cache::store('array')->forget('constructor:generation');
        $this->service()->getBrowseGroups();

        Http::assertSentCount(3);
    }

    public function test_cached_facet_pages_drop_the_per_request_result_id(): void
    {
        $this->enableCache();
        Http::fake(['ac.cnstrc.com/*' => Http::response(['result_id' => 'res-first-shopper', 'response' => ['facets' => [], 'total_num_results' => 0]])]);

        $first = $this->service()->getFacetsWithOptions();
        $second = $this->service()->getFacetsWithOptions();

        $this->assertSame('res-first-shopper', $first['result_id']);
        $this->assertNull($second['result_id']);
        Http::assertSentCount(1);
    }

    public function test_ttl_overrides_apply_per_response_type(): void
    {
        $this->enableCache([
            'constructor.cache.ttl' => 300,
            'constructor.cache.ttl_overrides' => ['categories' => '3600', 'facets' => '', 'collections' => null],
        ]);

        $cache = app(ResponseCache::class);

        $this->assertSame(3600, $cache->ttl('categories'));
        $this->assertSame(300, $cache->ttl('facets'));
        $this->assertSame(300, $cache->ttl('collections'));
    }

    public function test_a_broken_cache_store_falls_back_to_constructor(): void
    {
        $this->enableCache(['constructor.cache.store' => 'not-configured']);
        Http::fake(['ac.cnstrc.com/*' => Http::response(self::GROUPS)]);
        $log = $this->spyOnLog();

        $this->assertSame('grills', $this->service()->getBrowseGroups()[0]['id']);

        $log->shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'response cache unavailable'),
        );
    }
}
