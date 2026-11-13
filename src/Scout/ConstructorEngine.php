<?php

namespace ConstructorIO\Laravel\Scout;

use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Services\CatalogService;
use ConstructorIO\Laravel\Services\SearchService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
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
 * @see ConstructorSearchable for how models are turned into Constructor items.
 */
class ConstructorEngine extends Engine
{
    /** Scout's default page size when neither take() nor paginate() sets one. */
    private const DEFAULT_PER_PAGE = 24;

    public function __construct(
        protected readonly CatalogService $catalog,
        protected readonly SearchService $search,
        protected readonly bool $softDelete = false,
    ) {}

    /**
     * Create or replace the models' items in Constructor.
     *
     * Failures throw, so a queued Scout job is retried instead of silently
     * leaving the index out of date.
     *
     * @param  EloquentCollection<int, Model>  $models
     */
    public function update($models): void
    {
        $this->groupBySection($models)->each(function (Collection $sectionModels, string $section) {
            $items = $sectionModels
                ->map(fn (Model $model) => $this->toItem($model))
                ->filter()
                ->values()
                ->all();

            if ($items !== []) {
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
     * @param  Builder<Model>  $builder
     */
    public function search(Builder $builder): SearchResults
    {
        return $this->performSearch($builder, [
            'per_page' => $builder->limit ?? self::DEFAULT_PER_PAGE,
            'page' => 1,
        ]);
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
        return $this->performSearch($builder, [
            'per_page' => (int) $perPage,
            'page' => (int) $page,
        ]);
    }

    /**
     * The Constructor item IDs (Scout keys) in ranking order.
     *
     * @param  SearchResults  $results
     * @return Collection<int, string>
     */
    public function mapIds($results): Collection
    {
        return collect($results->products)->pluck('id')->filter()->values();
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
     * Translate the Scout builder into a SearchService call.
     *
     * @param  Builder<Model>  $builder
     * @param  array{per_page: int, page: int}  $paging
     */
    protected function performSearch(Builder $builder, array $paging): SearchResults
    {
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
     * Scout 10 stores wheres as [field => value]; Scout 11 as a list of
     * ['field', 'operator', 'value']. Accept both.
     *
     * @param  Builder<Model>  $builder
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    protected function normalizedWheres(Builder $builder): array
    {
        $wheres = [];

        foreach ($builder->wheres as $key => $where) {
            $wheres[] = is_array($where) && isset($where['field'])
                ? ['field' => (string) $where['field'], 'operator' => (string) ($where['operator'] ?? '='), 'value' => $where['value'] ?? null]
                : ['field' => (string) $key, 'operator' => '=', 'value' => $where];
        }

        return $wheres;
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

        // Scout's soft-delete flag lets ->withTrashed() / ->onlyTrashed()
        // filter on `__soft_deleted` (configure it as a facet in Constructor).
        if ($this->softDelete && method_exists($model, 'pushSoftDeleteMetadata')) {
            $item['data'] = array_merge((array) ($item['data'] ?? []), $model->scoutMetadata());
        }

        // The ID must be the Scout key so search results map back to rows.
        $item['id'] = $this->itemId($model);

        return $this->catalog->normalizeItem($item);
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
