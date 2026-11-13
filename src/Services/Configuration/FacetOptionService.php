<?php

namespace ConstructorIO\Laravel\Services\Configuration;

/**
 * Facet options: how individual facet values are shown, such as display
 * names ("weber" as "Weber"), positions, swatch data, aliases that merge
 * similar values, or hiding a value.
 *
 *     Constructor::configuration()->facetOptions()->updateMany('brand', [
 *         ['value' => 'weber', 'display_name' => 'Weber'],
 *         ['value' => 'traeger', 'display_name' => 'Traeger', 'position' => 1],
 *     ]);
 *
 * An option has `value` (required), plus value_alias, replace_value_alias,
 * display_name, position, data and hidden.
 *
 * @see https://docs.constructor.com/reference/v1-facet-options-retrieve-facet-options
 */
class FacetOptionService extends ConfigurationResource
{
    /** Constructor returns at most 1,000 options per page. */
    private const MAX_PER_PAGE = 1000;

    /** PATCH .../options accepts at most 1,000 options per request. */
    private const MAX_PER_UPDATE = 1000;

    /**
     * Options: section, value_alias (only options with this alias), page,
     * per_page (1-1,000, default 100), offset.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> ['facet_options' => [...], 'total_count' => int]
     */
    public function list(string $facetName, array $options = []): array
    {
        $response = $this->read($this->basePath($facetName), array_merge(
            $this->section($options['section'] ?? null),
            ['filter' => isset($options['value_alias']) ? ['value_alias' => (string) $options['value_alias']] : null],
            $this->paging($options, self::MAX_PER_PAGE),
        ));

        return $this->listResponse($response, 'facet_options');
    }

    /**
     * @return array<string, mixed>|null Null if the facet has no option with this value.
     */
    public function get(string $facetName, string $value, ?string $section = null): ?array
    {
        return $this->find($this->path($facetName, $value), $this->section($section));
    }

    /**
     * @param  array<string, mixed>  $option  value is required.
     * @return array<string, mixed>
     */
    public function create(string $facetName, array $option, ?string $section = null): array
    {
        return $this->write('POST', $this->basePath($facetName), $this->section($section), $option);
    }

    /**
     * Create options that don't exist and partially update those that do,
     * sent 1,000 per request.
     *
     * The API reference documents the body as a JSON array of options. (The
     * Facets guide shows an {"options": [...]} wrapper; the reference wins.)
     *
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>> The options as saved.
     */
    public function updateMany(string $facetName, array $options, ?string $section = null): array
    {
        $saved = [];

        foreach ($this->batches($options, self::MAX_PER_UPDATE) as $batch) {
            $response = $this->write('PATCH', $this->basePath($facetName), $this->section($section), $batch);
            $saved = array_merge($saved, array_values(array_filter($response, 'is_array')));
        }

        return $saved;
    }

    /**
     * Overwrite one option. Fields you leave out are reset.
     *
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    public function replace(string $facetName, string $value, array $option, ?string $section = null): array
    {
        return $this->write('PUT', $this->path($facetName, $value), $this->section($section), $option);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function update(string $facetName, string $value, array $changes, ?string $section = null): array
    {
        return $this->write('PATCH', $this->path($facetName, $value), $this->section($section), $changes);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $facetName, string $value, ?string $section = null): array
    {
        return $this->write('DELETE', $this->path($facetName, $value), $this->section($section));
    }

    private function basePath(string $facetName): string
    {
        return '/v1/facets/'.$this->name($facetName, 'facet name').'/options';
    }

    private function path(string $facetName, string $value): string
    {
        return $this->basePath($facetName).'/'.$this->name($value, 'facet option value');
    }
}
