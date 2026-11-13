<?php

namespace ConstructorIO\Laravel\Services\Configuration;

/**
 * Facet configurations: which product attributes appear as filters, and how
 * (display name, type, sort order, range buckets, hidden or protected).
 * Facets aren't configured automatically from catalog data: each one must
 * exist here (or in the dashboard) to show on a results page.
 *
 *     Constructor::configuration()->facets()->create([
 *         'name' => 'fuel_type',
 *         'path_in_metadata' => 'facets.fuel_type',
 *         'type' => 'multiple',
 *         'display_name' => 'Fuel type',
 *     ]);
 *
 * A facet has `name`, `path_in_metadata` and `type` (multiple|range|
 * hierarchical). Range facets also need `range_format` (boundaries for a
 * slider, options for buckets), and options ranges need `range_limits`.
 * Optional: display_name, sort_order, sort_descending, range_type,
 * range_format, range_inclusive, range_limits, match_type, position, hidden,
 * protected, countable, options_limit and data. Facet options (values) are
 * managed with FacetOptionService.
 *
 * @see https://docs.constructor.com/reference/configuration-facets
 */
class FacetConfigurationService extends ConfigurationResource
{
    /** Constructor returns at most 1,000 facets per page. */
    private const MAX_PER_PAGE = 1000;

    /** PUT /v2/facets accepts at most 25 facets per request. */
    private const MAX_PER_REPLACE = 25;

    /** PATCH /v2/facets accepts at most 1,000 facets per request. */
    private const MAX_PER_UPDATE = 1000;

    /**
     * Options: section, page, per_page (1-1,000, default 100), offset.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> ['facets' => [...], 'total_count' => int]
     */
    public function list(array $options = []): array
    {
        $response = $this->read('/v2/facets', array_merge(
            $this->section($options['section'] ?? null),
            $this->paging($options, self::MAX_PER_PAGE),
        ));

        return $this->listResponse($response, 'facets');
    }

    /**
     * @return array<string, mixed>|null Null if no facet has this name.
     */
    public function get(string $facetName, ?string $section = null): ?array
    {
        return $this->find($this->path($facetName), $this->section($section));
    }

    /**
     * @param  array<string, mixed>  $facet  name, path_in_metadata and type are required (plus range_format for range facets).
     * @return array<string, mixed> The created facet.
     *
     * @see https://docs.constructor.com/reference/v2-facets-create-facet
     */
    public function create(array $facet, ?string $section = null): array
    {
        return $this->write('POST', '/v2/facets', $this->section($section), $facet);
    }

    /**
     * Create or replace facets, sent 25 per request (Constructor's limit).
     * Replacing resets every field you leave out; facet options are kept.
     *
     * @param  list<array<string, mixed>>  $facets
     * @return list<array<string, mixed>> The facets as saved.
     */
    public function replaceMany(array $facets, ?string $section = null): array
    {
        return $this->sendBatches('PUT', $facets, self::MAX_PER_REPLACE, $section);
    }

    /**
     * Change only the fields you pass on each facet (identified by `name`),
     * sent 1,000 per request.
     *
     * @param  list<array<string, mixed>>  $facets
     * @return list<array<string, mixed>> The facets as saved.
     */
    public function updateMany(array $facets, ?string $section = null): array
    {
        return $this->sendBatches('PATCH', $facets, self::MAX_PER_UPDATE, $section);
    }

    /**
     * Overwrite one facet. Fields you leave out are reset; options are kept.
     *
     * The body is a full facet, so it needs path_in_metadata and type too.
     * `name` is required in the body as well; it defaults to $facetName.
     *
     * @param  array<string, mixed>  $facet
     * @return array<string, mixed>
     *
     * @see https://docs.constructor.com/reference/v2-facets-replace-facet
     */
    public function replace(string $facetName, array $facet, ?string $section = null): array
    {
        return $this->write('PUT', $this->path($facetName), $this->section($section), $facet + ['name' => $facetName]);
    }

    /**
     * Change only the fields you pass (every field is optional).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     *
     * @see https://docs.constructor.com/reference/v2-facets-update-facet
     */
    public function update(string $facetName, array $changes, ?string $section = null): array
    {
        return $this->write('PATCH', $this->path($facetName), $this->section($section), $changes);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $facetName, ?string $section = null): array
    {
        return $this->write('DELETE', $this->path($facetName), $this->section($section));
    }

    private function path(string $facetName): string
    {
        return '/v2/facets/'.$this->name($facetName, 'facet name');
    }

    /**
     * @param  list<array<string, mixed>>  $facets
     * @param  int<1, max>  $batchSize
     * @return list<array<string, mixed>>
     */
    private function sendBatches(string $method, array $facets, int $batchSize, ?string $section): array
    {
        $saved = [];

        foreach ($this->batches($facets, $batchSize) as $batch) {
            $response = $this->write($method, '/v2/facets', $this->section($section), ['facets' => $batch]);
            $saved = array_merge($saved, array_values(array_filter((array) ($response['facets'] ?? []), 'is_array')));
        }

        return $saved;
    }
}
