<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\ConstructorServiceProvider;
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Scout\ConstructorEngine;
use ConstructorIO\Laravel\Scout\ConstructorSearchable;
use ConstructorIO\Laravel\Scout\SearchResultCollection;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\EngineManager;
use Laravel\Scout\ScoutServiceProvider;
use Laravel\Scout\Searchable;

class ScoutEngineTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoutServiceProvider::class, ConstructorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('scout.driver', 'constructor');
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('grills', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price');
            $table->string('brand');
        });

        Schema::create('smokers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
    }

    /**
     * Change config after boot: forget the cached config and Scout drivers
     * so the engine is rebuilt with the new values.
     *
     * @param  array<string, mixed>  $values
     */
    private function reconfigureScout(array $values): void
    {
        config($values);
        $this->app->forgetInstance(ConstructorConfig::class);
        $this->app->make(EngineManager::class)->forgetDrivers();
    }

    /**
     * Fake search responses that return as many results as were asked for
     * (by num_results_per_page), numbered from the requested offset, out of $total.
     */
    private function fakeSearchReturningRequestedCount(int $total): void
    {
        Http::fake(function (Request $request) use ($total) {
            $query = $this->queryOf($request);
            $perPage = (int) $query['num_results_per_page'][0];
            $offset = isset($query['offset']) ? (int) $query['offset'][0] : ((int) ($query['page'][0] ?? 1) - 1) * $perPage;
            $count = max(0, min($perPage, $total - $offset));

            return Http::response(['response' => [
                'total_num_results' => $total,
                'results' => array_map(fn (int $i) => ['data' => ['id' => (string) ($offset + $i + 1)]], range(0, $count - 1)),
            ]]);
        });
    }

    private function createGrills(): void
    {
        // Saving would trigger Scout syncing; create the rows without it.
        Grill::withoutSyncingToSearch(function () {
            Grill::create(['name' => 'Pellet Grill', 'price' => 899, 'brand' => 'Traeger']);
            Grill::create(['name' => 'Kettle Grill', 'price' => 199, 'brand' => 'Weber']);
            Grill::create(['name' => 'Kamado', 'price' => 1299, 'brand' => 'Big Green Egg']);
        });
    }

    /**
     * @param  list<string>  $ids
     */
    private function searchResponse(array $ids, int $total): array
    {
        return [
            'result_id' => 'result-abc',
            'response' => [
                'total_num_results' => $total,
                'results' => array_map(fn (string $id) => ['value' => "Item {$id}", 'data' => ['id' => $id]], $ids),
                'facets' => [['name' => 'brand', 'display_name' => 'Brand', 'type' => 'multiple', 'options' => [['value' => 'Weber', 'count' => 3]]]],
                'sort_options' => [['sort_by' => 'price', 'sort_order' => 'ascending', 'display_name' => 'Price']],
            ],
        ];
    }

    public function test_the_constructor_driver_is_registered(): void
    {
        $this->assertInstanceOf(ConstructorEngine::class, $this->app->make(EngineManager::class)->engine('constructor'));
    }

    public function test_update_sends_items_with_the_scout_key_as_id_and_extra_fields_in_data(): void
    {
        $this->createGrills();
        Http::fake(['*' => Http::response(['task_id' => 1, 'task_status_path' => '/v1/tasks/1'])]);

        Grill::all()->searchable();

        Http::assertSent(function (Request $request) {
            $this->assertSame('PUT', $request->method());
            $this->assertSame('/v2/items', $this->pathOf($request));
            $this->assertSame(['Grills'], $this->queryOf($request)['section']);
            $this->assertSame(['id' => '1', 'name' => 'Pellet Grill', 'data' => ['price' => '899', 'brand' => 'Traeger', 'url' => '/grills/1']], $request->data()['items'][0]);
            $this->assertCount(3, $request->data()['items']);

            return true;
        });
    }

    public function test_update_errors_are_thrown_so_queued_jobs_retry(): void
    {
        $this->createGrills();
        Http::fake(['*' => Http::response(['message' => 'bad'], 400)]);

        $this->expectException(ApiException::class);
        Grill::all()->searchable();
    }

    public function test_delete_and_flush_use_the_scout_key(): void
    {
        $this->createGrills();
        Http::fake(['*' => Http::response(['task_id' => 2, 'task_status_path' => '/v1/tasks/2'])]);

        Grill::find(2)->unsearchable();
        Grill::removeAllFromSearch();

        $deletes = Http::recorded(fn (Request $request) => $request->method() === 'DELETE');
        $this->assertCount(2, $deletes);
        $this->assertSame([['id' => '2']], $deletes[0][0]->data()['items']);
        $this->assertSame([['id' => '1'], ['id' => '2'], ['id' => '3']], $deletes[1][0]->data()['items']);
    }

    public function test_search_maps_wheres_and_returns_models_in_constructor_order(): void
    {
        $this->createGrills();
        Http::fake(['*' => Http::response($this->searchResponse(['3', '1'], 2))]);

        $results = Grill::search('grill')
            ->where('brand', 'Traeger')
            ->whereIn('fuel', ['Pellet', 'Charcoal'])
            ->where('price', '>=', 100)
            ->where('price', '<=', 1500)
            ->whereNotIn('brand', ['Generic'])
            ->orderBy('price', 'asc')
            ->take(10)
            ->get();

        $this->assertInstanceOf(SearchResultCollection::class, $results);
        $this->assertSame([3, 1], $results->pluck('id')->all());
        $this->assertSame(2, $results->getTotal());
        $this->assertSame('result-abc', $results->getResultId());
        $this->assertArrayHasKey('brand', $results->getFacets());
        $this->assertSame('price', $results->getSortOptions()[0]['sort_by']);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame('/v1/search/grill', $this->pathOf($request));
            $this->assertSame(['Grills'], $query['section']);
            $this->assertSame(['Traeger'], $query['filters[brand]']);
            $this->assertSame(['Pellet', 'Charcoal'], $query['filters[fuel]']);
            $this->assertSame(['100-1500'], $query['filters[price]']);
            $this->assertSame(['price'], $query['sort_by']);
            $this->assertSame(['ascending'], $query['sort_order']);
            $this->assertSame(['10'], $query['num_results_per_page']);
            $this->assertSame(
                ['not' => ['or' => [['name' => 'brand', 'value' => 'Generic']]]],
                json_decode($query['pre_filter_expression'][0], true),
            );
            // Scout traffic carries the backend-integration token, not the API token.
            $this->assertSame(['backend_security_token'], $request->header('x-cnstrc-token'));
            $this->assertSame([], $request->header('Authorization'));

            return true;
        });
    }

    public function test_paginate_passes_page_and_per_page(): void
    {
        $this->createGrills();
        Http::fake(['*' => Http::response($this->searchResponse(['2'], 25))]);

        $paginator = Grill::search('kettle')->paginate(12, 'page', 3);

        $this->assertSame(25, $paginator->total());
        $this->assertSame(3, $paginator->currentPage());
        $this->assertSame('Kettle Grill', $paginator->items()[0]->name);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);
            $this->assertSame(['3'], $query['page']);
            $this->assertSame(['12'], $query['num_results_per_page']);

            return true;
        });
    }

    public function test_empty_query_returns_no_results_without_calling_constructor(): void
    {
        Http::fake();

        $this->assertCount(0, Grill::search('')->get());
        Http::assertNothingSent();
    }

    public function test_search_callback_receives_the_search_service(): void
    {
        $this->createGrills();
        Http::fake(['*' => Http::response($this->searchResponse(['1'], 1))]);

        $results = Grill::search('', function ($search, string $query, array $filters, array $options) {
            return $search->browse('group_id', 'pellet-grills', $filters, $options);
        })->get();

        $this->assertSame([1], $results->pluck('id')->all());
        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/browse/group_id/pellet-grills');
    }

    public function test_lazy_map_streams_models_in_order(): void
    {
        $this->createGrills();
        Http::fake(['*' => Http::response($this->searchResponse(['2', '3'], 2))]);

        $this->assertSame([2, 3], Grill::search('grill')->cursor()->pluck('id')->all());
    }

    public function test_soft_deleted_models_are_flagged_and_searches_filter_them_out(): void
    {
        $this->reconfigureScout(['scout.soft_delete' => true]);
        Smoker::withoutSyncingToSearch(function () {
            Smoker::create(['name' => 'Offset Smoker']);
            Smoker::create(['name' => 'Old Smoker'])->delete();
        });
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response($this->searchResponse(['1'], 1))
            : Http::response(['task_id' => 1, 'task_status_path' => '/v1/tasks/1']));

        Smoker::withTrashed()->get()->searchable();

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $this->assertSame([
                ['id' => '1', 'name' => 'Offset Smoker', 'data' => ['__soft_deleted' => '0']],
                ['id' => '2', 'name' => 'Old Smoker', 'data' => ['__soft_deleted' => '1']],
            ], $request->data()['items']);

            return true;
        });

        $this->assertSame([1], Smoker::search('smoker')->get()->pluck('id')->all());

        Http::assertSent(fn (Request $request) => ($this->queryOf($request)['filters[__soft_deleted]'] ?? null) === ['0']);
    }

    public function test_where_in_with_no_values_matches_nothing(): void
    {
        Http::fake();

        $this->assertCount(0, Grill::search('grill')->whereIn('brand', [])->get());
        Http::assertNothingSent();
    }

    public function test_take_above_the_per_request_limit_fetches_consecutive_batches(): void
    {
        $this->fakeSearchReturningRequestedCount(total: 1000);

        $keys = Grill::search('grill')->take(450)->keys();

        $this->assertCount(450, $keys);
        $this->assertSame('1', $keys->first());
        $this->assertSame('450', $keys->last());

        $requests = array_map(fn (array $pair) => [
            $this->queryOf($pair[0])['offset'][0],
            $this->queryOf($pair[0])['num_results_per_page'][0],
        ], Http::recorded()->all());
        $this->assertSame([['0', '200'], ['200', '200'], ['400', '50']], $requests);
    }

    public function test_batched_fetching_stops_when_results_run_out(): void
    {
        $this->fakeSearchReturningRequestedCount(total: 250);

        $this->assertCount(250, Grill::search('grill')->take(1000)->keys());
        Http::assertSentCount(2);
    }

    public function test_paginator_total_with_a_query_callback_counts_beyond_200(): void
    {
        Grill::withoutSyncingToSearch(function () {
            foreach (range(1, 300) as $i) {
                Grill::create(['name' => "Grill {$i}", 'price' => 100, 'brand' => 'Weber']);
            }
        });
        $this->fakeSearchReturningRequestedCount(total: 300);

        $paginator = Grill::search('grill')->query(fn ($query) => $query->where('price', '>', 0))->paginate(24);

        $this->assertSame(300, $paginator->total());
    }

    public function test_map_ids_keeps_the_id_zero(): void
    {
        $engine = $this->app->make(EngineManager::class)->engine('constructor');
        $results = new SearchResults(products: [['id' => '0'], ['id' => '5'], ['id' => null], ['id' => '']], total: 4, page: 1, perPage: 24);

        $this->assertSame(['0', '5'], $engine->mapIds($results)->all());
    }

    public function test_patch_sync_mode_only_changes_the_fields_the_model_sends(): void
    {
        $this->reconfigureScout(['constructor.scout.sync_mode' => 'patch']);
        $this->createGrills();
        Http::fake(['*' => Http::response(['task_id' => 3, 'task_status_path' => '/v1/tasks/3'])]);

        Grill::all()->searchable();

        Http::assertSent(function (Request $request) {
            $this->assertSame('PATCH', $request->method());
            $this->assertSame('/v2/items', $this->pathOf($request));
            $this->assertSame(['CREATE'], $this->queryOf($request)['on_missing']);

            return true;
        });
    }

    public function test_create_index_explains_that_indexes_live_in_the_dashboard(): void
    {
        $this->expectException(ConstructorException::class);
        $this->expectExceptionMessage('dashboard');

        $this->app->make(EngineManager::class)->engine('constructor')->createIndex('grills');
    }
}

/**
 * Test model: section from getConstructorSection(), default toSearchableArray
 * plus a computed URL.
 */
class Grill extends Model implements ConstructorSearchable
{
    use Searchable;

    protected $guarded = [];

    public $timestamps = false;

    public function getConstructorSection(): string
    {
        return 'Grills';
    }

    public function toConstructorItem(): array
    {
        return [
            'name' => $this->name,
            'price' => (string) $this->price,
            'brand' => $this->brand,
            'url' => "/grills/{$this->id}",
        ];
    }
}

/**
 * Test model with soft deletes and Scout's defaults (no ConstructorSearchable).
 */
class Smoker extends Model
{
    use Searchable;
    use SoftDeletes;

    protected $guarded = [];

    public $timestamps = false;

    public function toSearchableArray(): array
    {
        return ['name' => $this->name];
    }
}
