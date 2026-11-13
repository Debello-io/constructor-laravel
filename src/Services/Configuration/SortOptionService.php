<?php

namespace ConstructorIO\Laravel\Services\Configuration;

use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;

/**
 * Sort options shown on search and browse pages ("Price: Low to High").
 * Each one is identified by its sort_by + sort_order pair, which must be
 * unique in the section.
 *
 *     Constructor::configuration()->sortOptions()->create([
 *         'sort_by' => 'price',
 *         'sort_order' => 'ascending',
 *         'path_in_metadata' => 'data.price',
 *         'display_name' => 'Price: Low to High',
 *     ]);
 *
 * A sort option has `sort_by`, `sort_order` (ascending|descending) and
 * `path_in_metadata` (required), plus `display_name`, `position` and `hidden`.
 *
 * @see https://docs.constructor.com/reference/v1-sort-options-retrieve-sort-options
 */
class SortOptionService extends ConfigurationResource
{
    /** Constructor returns at most 200 sort options per page. */
    private const MAX_PER_PAGE = 200;

    /** replaceAll() accepts at most 50 sort options. */
    private const MAX_REPLACE = 50;

    /** delete() accepts at most 200 sort options per request. */
    private const MAX_DELETE = 200;

    private const SORT_ORDERS = ['ascending', 'descending'];

    /**
     * Options: section, sort_by (only options with this sort_by), page,
     * per_page (1-200, default 200), offset.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> ['sort_options' => [...], 'total_count' => int]
     */
    public function list(array $options = []): array
    {
        $response = $this->read('/v1/sort_options', array_merge(
            $this->section($options['section'] ?? null),
            ['sort_by' => $options['sort_by'] ?? null],
            $this->paging($options, self::MAX_PER_PAGE),
        ));

        return $this->listResponse($response, 'sort_options');
    }

    /**
     * @param  array<string, mixed>  $sortOption
     * @return array<string, mixed> The created sort option.
     */
    public function create(array $sortOption, ?string $section = null): array
    {
        return $this->write('POST', '/v1/sort_option', $this->section($section), $sortOption);
    }

    /**
     * Create or replace these sort options (at most 50). Others in the
     * section are left alone.
     *
     * @param  list<array<string, mixed>>  $sortOptions
     * @return list<array<string, mixed>> The sort options as saved.
     *
     * @throws InvalidArgumentException With more than 50 sort options.
     */
    public function replaceMany(array $sortOptions, ?string $section = null): array
    {
        if ($sortOptions === [] || count($sortOptions) > self::MAX_REPLACE) {
            throw new InvalidArgumentException('replaceMany() takes 1 to '.self::MAX_REPLACE.' sort options.');
        }

        $response = $this->write('PUT', '/v1/sort_options', $this->section($section), [
            'sort_options' => $sortOptions,
        ]);

        return array_values(array_filter((array) ($response['sort_options'] ?? []), 'is_array'));
    }

    /**
     * Overwrite one sort option. Fields you leave out are reset.
     *
     * @param  array<string, mixed>  $sortOption
     * @return array<string, mixed>
     */
    public function replace(string $sortBy, string $sortOrder, array $sortOption, ?string $section = null): array
    {
        return $this->write('PUT', $this->path($sortBy, $sortOrder), $this->section($section), $sortOption);
    }

    /**
     * Change display_name, path_in_metadata, position or hidden.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function update(string $sortBy, string $sortOrder, array $changes, ?string $section = null): array
    {
        return $this->write('PATCH', $this->path($sortBy, $sortOrder), $this->section($section), $changes);
    }

    /**
     * Delete sort options, sent 200 per request.
     *
     * @param  list<array<string, mixed>>  $sortOptions  Each ['sort_by' => ..., 'sort_order' => 'ascending'|'descending']
     */
    public function delete(array $sortOptions, ?string $section = null): void
    {
        $keys = array_map(function (array $option) {
            $sortBy = trim((string) ($option['sort_by'] ?? ''));
            if ($sortBy === '') {
                throw new InvalidArgumentException('Each sort option to delete needs a sort_by.');
            }

            return ['sort_by' => $sortBy, 'sort_order' => $this->sortOrder((string) ($option['sort_order'] ?? ''))];
        }, $sortOptions);

        foreach ($this->batches($keys, self::MAX_DELETE) as $batch) {
            $this->write('DELETE', '/v1/sort_options', $this->section($section), ['sort_options' => $batch]);
        }
    }

    private function path(string $sortBy, string $sortOrder): string
    {
        return '/v1/sort_option/'.$this->name($sortBy, 'sort_by').'/'.$this->sortOrder($sortOrder);
    }

    /**
     * @throws InvalidArgumentException For anything but ascending or descending.
     */
    private function sortOrder(string $sortOrder): string
    {
        if (! in_array($sortOrder, self::SORT_ORDERS, true)) {
            throw new InvalidArgumentException("sort_order must be 'ascending' or 'descending', not '{$sortOrder}'.");
        }

        return $sortOrder;
    }
}
