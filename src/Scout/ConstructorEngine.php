<?php

namespace ConstructorIO\Laravel\Scout;

use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Services\CatalogService;
use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

/**
 * Laravel Scout engine for Constructor (SCOUT_DRIVER=constructor).
 *
 * Indexing goes through CatalogService (/v2/items, API token). Searching
 * goes through SearchService, so Scout searches carry the same shopper
 * identity and backend-integration headers as Constructor::search().
 * Matching models are then loaded from your database by Scout key, in
 * Constructor's ranking order.
 *
 *     Product::search('pellet grill')
 *         ->where('brand', 'Traeger')
 *         ->whereIn('fuel_type', ['Pellet', 'Wood'])
 *         ->where('price', '<=', 1000)
 *         ->orderBy('price', 'asc')
 *         ->paginate(24);
 *
 * Constructor needs a search term: an empty query returns no results. Use
 * Constructor::browse() for category and listing pages.
 *
 * Saving a model sends it according to CONSTRUCTOR_SCOUT_SYNC_MODE:
 *   replace (default)  PUT /v2/items: the item becomes exactly what the model sends.
 *   patch              PATCH /v2/items with on_missing=CREATE: only the fields the
 *                      model sends change, so fields maintained by other feeds
 *                      (inventory, merchandising data) are kept.
 *
 * Soft deletes (scout.soft_delete = true): each item gets
 * data.__soft_deleted = "0" or "1", and searches filter on it the way Scout
 * expects (withTrashed() / onlyTrashed()). Configure `__soft_deleted` as a
 * hidden facet in the Constructor dashboard so it can be filtered on.
 *
 * Requires Laravel Scout 11 (where() with an operator).
 *
 * @see ConstructorSearchable for how models are turned into Constructor items.
 */
class ConstructorEngine extends Engine
{
    /** Scout's default page size when neither take() nor paginate() sets one. */
    private const DEFAULT_PER_PAGE = 24;

    /** Constructor returns at most this many results per request. */
    private const MAX_PER_REQUEST = 200;

    public function __construct(
        protected readonly CatalogService $catalog,
        protected readonly SearchService $search,
        protected readonly ConstructorConfig $config,
        protected readonly bool $softDelete = false,
    ) {}

    /**
     * Send the models' items to Constructor (replace or patch, see the class docblock).
     *
     * Failures throw, so a queued Scout job is retried instead of silently
     * leaving the index out of date.
     *
     * @param  EloquentCollection<int, Model>  $models
     */
    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        // Scout's built-in engines do this too: it sets the __soft_deleted
        // metadata that toItem() copies into the item.
        if ($this->softDelete && $this->usesSoftDelete($models->first())) {
            foreach ($models as $model) {
                // pushSoftDeleteMetadata() comes from Scout's Searchable trait.
                if (method_exists($model, 'pushSoftDeleteMetadata')) {
                    $model->pushSoftDeleteMetadata();
                }
            }
        }

        $this->groupBySection($models)->each(function (Collection $sectionModels, string $section) {
            $items = $sectionModels
                ->map(fn (Model $model) => $this->toItem($model))
                ->filter()
                ->values()
                ->all();

            if ($items === []) {
                return;
            }

            if ($this->config->scoutSyncMode === 'patch') {
                $this->catalog->patchItems($items, $section, ['on_missing' => 'CREATE']);
            } else {
                $this->catalog->upsertItems($items, $section);
            }
        });
    }

    /**
     * Remove the models' items from Constructor.
     *
     * @param  EloquentCollection<int, Model>  $models
     */
    public function delete($models): void
    {
        $this->groupBySection($models)->each(function (Collection $sectionModels, string $section) {
            $ids = $sectionModels->map(fn (Model $model) => $this->itemId($model))->all();

            if ($ids !== []) {
                $this->catalog->deleteItems($ids, $section);
            }
        });
    }

    /**
     * Remove every record of this model from Constructor (scout:flush).
     *
     * Constructor deletes items by ID, so this walks the table in chunks and
     * deletes the IDs it finds. Items whose database rows are already gone
     * are not touched; to wipe a section, upload a replacement catalog with
     * CatalogService::uploadCatalog().
     *
     * @param  Model  $model
     */
    public function flush($model): void
    {
        // Include soft-deleted rows: they may still be in the index.
        $model->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->chunkById(
            $this->catalog->batchSize(),
            fn (EloquentCollection $models) => $this->delete($models),
        );
    }

    /**
     * Run the search and return Constructor's results.
     *
     * take() above Constructor's 200-per-request limit is served by fetching
     * consecutive pages (up to 10,000 results). Scout relies on this when it
     * counts results for a paginator with a ->query() callback.
     *
     * @param  Builder<Model>  $builder
     */
    public function search(Builder $builder): SearchResults
    {
        return $this->fetchWindow($builder, offset: 0, count: (int) ($builder->limit ?? self::DEFAULT_PER_PAGE));
    }

    /**
     * Run the search for one page.
     *
     * @param  Builder<Model>  $builder
     * @param  int  $perPage
     * @param  int  $page
     */
    public function paginate(Builder $builder, $perPage, $page): SearchResults
    {
        $perPage = max(1, (int) $perPage);
        $page = max(1, (int) $page);

        return $this->fetchWindow($builder, offset: ($page - 1) * $perPage, count: $perPage);
    }

    /**
     * The Constructor item IDs (Scout keys) in ranking order.
     *
     * @param  SearchResults  $results
     * @return Collection<int, string>
     */
    public function mapIds($results): Collection
    {
        // Compare explicitly: "0" is a valid ID that filter() would drop.
        return collect($results->products)
            ->pluck('id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->values();
    }

    /**
     * Load the matching models from the database, in Constructor's order,
     * with the response details (total, facets, sort options, result ID).
     *
     * @param  Builder<Model>  $builder
     * @param  SearchResults  $results
     * @param  Model  $model
     * @return SearchResultCollection<int, Model>
     */
    public function map(Builder $builder, $results, $model): SearchResultCollection
    {
        $ids = $this->mapIds($results)->all();
        $positions = array_flip($ids);

        $models = $ids === []
            ? []
            : $model->getScoutModelsByIds($builder, $ids)
                ->filter(fn (Model $found) => isset($positions[(string) $found->getScoutKey()]))
                ->sortBy(fn (Model $found) => $positions[(string) $found->getScoutKey()])
                ->values()
                ->all();

        return (new SearchResultCollection($models))
            ->setTotal($results->total)
            ->setFacets($results->facets)
            ->setSortOptions($results->sortOptions)
            ->setResultId($results->resultId);
    }

    /**
     * Same as map(), but streams the models with a database cursor.
     *
     * @param  Builder<Model>  $builder
     * @param  SearchResults  $results
     * @param  Model  $model
     * @return LazyCollection<int, Model>
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        $ids = $this->mapIds($results)->all();
        if ($ids === []) {
            return LazyCollection::empty();
        }

        $positions = array_flip($ids);

        return $model->queryScoutModelsByIds($builder, $ids)
            ->cursor()
            ->filter(fn (Model $found) => isset($positions[(string) $found->getScoutKey()]))
            ->sortBy(fn (Model $found) => $positions[(string) $found->getScoutKey()])
            ->values();
    }

    /**
     * @param  SearchResults  $results
     */
    public function getTotalCount($results): int
    {
        return $results->total;
    }

    /**
     * Constructor indexes are created in the Constructor dashboard, not via
     * the API, so `scout:index` cannot create one.
     *
     * @param  string  $name
     * @param  array<string, mixed>  $options
     *
     * @throws ConstructorException
     */
    public function createIndex($name, array $options = []): never
    {
        throw new ConstructorException(
            'Constructor indexes are created in the Constructor dashboard. Set CONSTRUCTOR_API_KEY to the index key instead of running scout:index.'
        );
    }

    /**
     * Constructor indexes cannot be deleted via the API.
     *
     * @param  string  $name
     *
     * @throws ConstructorException
     */
    public function deleteIndex($name): never
    {
        throw new ConstructorException(
            'Constructor indexes cannot be deleted via the API. Use scout:flush to remove a model\'s items, or manage the index in the Constructor dashboard.'
        );
    }

    /**
     * Fetch `count` results starting at `offset`.
     *
     * One request when the window is a normal page; otherwise consecutive
     * requests of up to 200 results, merged into one SearchResults.
     *
     * @param  Builder<Model>  $builder
     */
    protected function fetchWindow(Builder $builder, int $offset, int $count): SearchResults
    {
        $count = max(1, min($count, SearchResults::MAX_RETRIEVABLE_RESULTS - $offset));

        if ($offset >= SearchResults::MAX_RETRIEVABLE_RESULTS) {
            return SearchResults::empty();
        }

        // Fits in one request and lines up with a page boundary.
        if ($count <= self::MAX_PER_REQUEST && $offset % $count === 0) {
            return $this->performSearch($builder, ['per_page' => $count, 'page' => intdiv($offset, $count) + 1]);
        }

        // A custom callback decides the request itself, so it can't be paged here.
        if ($builder->callback !== null) {
            return $this->performSearch($builder, ['per_page' => min($count, self::MAX_PER_REQUEST), 'offset' => $offset]);
        }

        $first = null;
        $products = [];

        while (count($products) < $count) {
            $batchSize = min(self::MAX_PER_REQUEST, $count - count($products));
            $batch = $this->performSearch($builder, ['per_page' => $batchSize, 'offset' => $offset + count($products)]);

            $first ??= $batch;
            $products = array_merge($products, $batch->products);

            // A short batch means there are no more results.
            if (count($batch->products) < $batchSize) {
                break;
            }
        }

        return new SearchResults(
            products: $products,
            total: $first->total,
            page: intdiv($offset, $count) + 1,
            perPage: $count,
            facets: $first->facets,
            metadata: $first->metadata,
            groups: $first->groups,
            resultId: $first->resultId,
            redirect: $first->redirect,
            sortOptions: $first->sortOptions,
            refinedContent: $first->refinedContent,
            relatedSearches: $first->relatedSearches,
            relatedBrowsePages: $first->relatedBrowsePages,
        );
    }

    /**
     * Translate the Scout builder into a SearchService call.
     *
     * @param  Builder<Model>  $builder
     * @param  array<string, int>  $paging  per_page plus page or offset.
     */
    protected function performSearch(Builder $builder, array $paging): SearchResults
    {
        // whereIn('field', []) can match nothing; sending no filter would match everything.
        foreach ($builder->whereIns as $values) {
            if ((array) $values === []) {
                return SearchResults::empty();
            }
        }

        $query = trim((string) $builder->query);
        $derived = $this->rangeAndExclusionOptions($builder);
        $options = array_merge(
            ['section' => $this->sectionOf($builder->model)],
            $this->sortOptions($builder),
            $derived,
            $builder->options,
            $paging,
        );

        // Keep both a pre_filter_expression passed via ->options() and the
        // one built from != / whereNotIn: shoppers must match both.
        if (isset($derived['pre_filter_expression'], $builder->options['pre_filter_expression'])) {
            $custom = $builder->options['pre_filter_expression'];
            $options['pre_filter_expression'] = ['and' => [
                is_string($custom) ? json_decode($custom, true, flags: JSON_THROW_ON_ERROR) : $custom,
                $derived['pre_filter_expression'],
            ]];
        }
        $filters = $this->equalityFilters($builder);

        // Builder::search('...', fn (SearchService $search, string $query, array $filters, array $options) => ...)
        if ($builder->callback !== null) {
            return call_user_func($builder->callback, $this->search, $query, $filters, $options);
        }

        return $this->search->search($query, $filters, $options);
    }

    /**
     * where('brand', 'Weber') and whereIn('brand', [...]) become `filters`.
     * Values for the same field are ORed, different fields are ANDed.
     *
     * @param  Builder<Model>  $builder
     * @return array<string, list<mixed>>
     */
    protected function equalityFilters(Builder $builder): array
    {
        $filters = [];

        foreach ($this->normalizedWheres($builder) as $where) {
            if ($where['operator'] === '=') {
                $filters[$where['field']][] = $where['value'];
            }
        }

        foreach ($builder->whereIns as $field => $values) {
            $filters[$field] = array_merge($filters[$field] ?? [], array_values((array) $values));
        }

        return $filters;
    }

    /**
     * Comparison wheres become range filters, and != / whereNotIn become a
     * pre_filter_expression with "not".
     *
     * Constructor's ranges are inclusive, so > and >= (and < and <=) behave
     * the same.
     *
     *
     * @see https://docs.constructor.com/reference/constructor-concepts-filter-expressions
     *
     * @param  Builder<Model>  $builder
     * @return array<string, mixed>
     */
    protected function rangeAndExclusionOptions(Builder $builder): array
    {
        $ranges = [];
        $exclusions = [];

        foreach ($this->normalizedWheres($builder) as $where) {
            match ($where['operator']) {
                '>', '>=' => $ranges[$where['field']]['min'] = $where['value'],
                '<', '<=' => $ranges[$where['field']]['max'] = $where['value'],
                '!=', '<>' => $exclusions[] = ['name' => $where['field'], 'value' => $where['value']],
                default => null,
            };
        }

        foreach ($builder->whereNotIns as $field => $values) {
            foreach ((array) $values as $value) {
                $exclusions[] = ['name' => $field, 'value' => $value];
            }
        }

        $options = [];

        if ($ranges !== []) {
            $options['range_filters'] = $ranges;
        }

        if ($exclusions !== []) {
            $options['pre_filter_expression'] = ['not' => ['or' => $exclusions]];
        }

        return $options;
    }

    /**
     * @param  Builder<Model>  $builder
     * @return array<string, string>
     */
    protected function sortOptions(Builder $builder): array
    {
        $order = $builder->orders[0] ?? null;
        if ($order === null) {
            return [];
        }

        return [
            'sort_by' => (string) $order['column'],
            'sort_order' => strtolower((string) $order['direction']) === 'asc' ? 'ascending' : 'descending',
        ];
    }

    /**
     * Scout 11 stores each where() as ['field' => ..., 'operator' => ..., 'value' => ...].
     *
     * @param  Builder<Model>  $builder
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    protected function normalizedWheres(Builder $builder): array
    {
        return array_values(array_map(fn (array $where) => [
            'field' => (string) $where['field'],
            'operator' => (string) ($where['operator'] ?? '='),
            'value' => $where['value'] ?? null,
        ], $builder->wheres));
    }

    /**
     * Build the Constructor item for a model, or null to skip it (Scout's
     * convention for an empty searchable array).
     *
     * @return array<string, mixed>|null
     */
    protected function toItem(Model $model): ?array
    {
        $item = method_exists($model, 'toConstructorItem')
            ? $model->toConstructorItem()
            : $model->toSearchableArray();

        if ($item === []) {
            return null;
        }

        // Set by pushSoftDeleteMetadata() in update(). Sent as "0" / "1":
        // Constructor facet values are strings, and Scout filters with
        // where('__soft_deleted', 0|1), which is sent as filters[__soft_deleted]=0|1.
        $metadata = $model->scoutMetadata();
        if (isset($metadata['__soft_deleted'])) {
            $item['data'] = array_merge((array) ($item['data'] ?? []), [
                '__soft_deleted' => (string) (int) $metadata['__soft_deleted'],
            ]);
        }

        // The ID must be the Scout key so search results map back to rows.
        $item['id'] = $this->itemId($model);

        return $this->catalog->normalizeItem($item);
    }

    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    protected function itemId(Model $model): string
    {
        return (string) $model->getScoutKey();
    }

    /**
     * @param  EloquentCollection<int, Model>  $models
     * @return Collection<string, Collection<int, Model>>
     */
    protected function groupBySection(EloquentCollection $models): Collection
    {
        return $models->toBase()->groupBy(fn (Model $model) => $this->sectionOf($model));
    }

    protected function sectionOf(Model $model): string
    {
        return method_exists($model, 'getConstructorSection')
            ? $model->getConstructorSection()
            : $this->catalog->defaultSection();
    }
}
