<?php

namespace ConstructorIO\Laravel\Support;

/**
 * Turns Constructor's response shapes into the flat arrays this package
 * returns.
 *
 * Search, browse, autocomplete, recommendations and the AI agents all return
 * products in the same "searchandized result" shape, so they share this one
 * transformer instead of each keeping their own copy.
 *
 * @see https://docs.constructor.com/reference/constructor-concepts-results-response-structure
 */
class ResultTransformer
{
    /**
     * Flatten one result.
     *
     * Constructor returns:
     *     { value: "Name", data: { id, url, image_url, price, ... }, variations, labels, is_slotted, ... }
     *
     * This returns the common fields at the top level, and keeps every
     * catalog field (including your custom metadata) in `_raw`.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function product(array $result): array
    {
        // Agent events sometimes send the data object without the wrapper.
        $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : $result;
        $labels = is_array($result['labels'] ?? null) ? $result['labels'] : [];

        return [
            'id' => isset($data['id']) ? (string) $data['id'] : null,
            'name' => $result['value'] ?? $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'url' => $data['url'] ?? '',
            'image_url' => $data['image_url'] ?? null,
            'price' => $data['price'] ?? null,
            'original_price' => $data['original_price'] ?? null,
            'sku' => $data['sku'] ?? null,
            'brand' => $data['brand'] ?? null,
            'variation_id' => $data['variation_id'] ?? null,
            'categories' => $data['groups'] ?? $data['categories'] ?? [],
            'facets' => $data['facets'] ?? [],
            'metadata' => $data['metadata'] ?? [],
            'matched_terms' => $result['matched_terms'] ?? [],
            'variations' => $result['variations'] ?? [],
            'variations_map' => $result['variations_map'] ?? null,
            // True when a merchandising rule pinned this result in place.
            'is_slotted' => (bool) ($result['is_slotted'] ?? false),
            // Sponsored Listings: show a "Sponsored" badge when true, and put
            // the campaign ID and owner on the result element
            // (data-cnstrc-sl-campaign-id / -owner) so the beacon attributes it.
            // The Sponsored Listings guide uses labels.is_retail_media; the
            // response-structure pages still document labels.is_sponsored for
            // promoted slots. Either one means the shopper is seeing an ad.
            // @see https://docs.constructor.com/docs/products-retail-media-learn-about-sponsored-listings-implement-sponsored-listings
            'is_sponsored' => (bool) ($labels['is_retail_media'] ?? false) || (bool) ($labels['is_sponsored'] ?? false),
            'sponsored_campaign_id' => $labels['sl_campaign_id'] ?? null,
            'sponsored_campaign_owner' => $labels['sl_campaign_owner'] ?? null,
            'labels' => $labels,
            'variation_slice' => $result['variation_slice'] ?? null,
            // Recommendations only: the strategy that picked this item.
            'strategy' => $result['strategy'] ?? null,
            '_raw' => $data,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    public function products(array $results): array
    {
        return array_values(array_map(fn (array $result) => $this->product($result), array_filter($results, 'is_array')));
    }

    /**
     * Normalise facets, keyed by facet name.
     *
     * Each facet has:
     *   name      display name
     *   type      checkbox_list | single_select | range | hierarchical
     *   values    [option value => count]  (kept for 1.x compatibility)
     *   options   full option objects: value, display_name, count, selected, data, options (hierarchical)
     *   min, max  range facets only, plus `selected` with the chosen range
     *   hidden    true if the facet is configured as hidden
     *
     * Range facets have no options; they are kept anyway so price sliders work.
     *
     * @param  array<int, array<string, mixed>>  $facets
     * @return array<string, array<string, mixed>>
     */
    public function facets(array $facets): array
    {
        $formatted = [];

        foreach (array_filter($facets, 'is_array') as $facet) {
            $name = $facet['name'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }

            $type = self::facetType((string) ($facet['type'] ?? 'multiple'));
            $options = $this->facetOptions($facet['options'] ?? []);

            if ($type !== 'range' && $options === []) {
                continue;
            }

            $formatted[$name] = [
                'name' => $facet['display_name'] ?? $this->humanize($name),
                'type' => $type,
                'values' => array_column($options, 'count', 'value'),
                'options' => $options,
                'hidden' => (bool) ($facet['hidden'] ?? false),
                'data' => $facet['data'] ?? [],
            ];

            if ($type === 'range') {
                $formatted[$name]['min'] = $facet['min'] ?? null;
                $formatted[$name]['max'] = $facet['max'] ?? null;
                $formatted[$name]['selected'] = $facet['status'] ?? null;
            }
        }

        return $formatted;
    }

    /**
     * Normalise browse groups (categories) and their children, recursively.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @param  int|null  $maxChildren  Limit children per group (null = all).
     * @return list<array<string, mixed>>
     */
    public function groups(array $groups, ?int $maxChildren = null): array
    {
        $formatted = [];

        foreach (array_filter($groups, 'is_array') as $group) {
            $children = is_array($group['children'] ?? null) ? $group['children'] : [];
            if ($maxChildren !== null) {
                $children = array_slice($children, 0, $maxChildren);
            }

            $id = (string) ($group['group_id'] ?? $group['id'] ?? '');

            $formatted[] = [
                'id' => $id,
                'group_id' => $id,
                'name' => $group['display_name'] ?? $group['name'] ?? '',
                'count' => $group['count'] ?? null,
                'image' => $group['data']['image_url'] ?? null,
                'data' => $group['data'] ?? [],
                'parents' => $group['parents'] ?? [],
                'has_children' => $children !== [],
                'children' => $this->groups($children, $maxChildren),
            ];
        }

        return $formatted;
    }

    /**
     * Response details every result DTO carries in `metadata`.
     *
     * @param  array<string, mixed>  $response
     *                                          `request` is Constructor's interpretation of the request. The beacon
     *                                          needs it, with `result_id`, in the page's cnstrc-data script tag on
     *                                          search and browse pages (see SearchResults::trackingData()).
     * @return array{request: array<string, mixed>|null, request_id: string|null, result_id: string|null, experiments: list<array<string, mixed>>}
     */
    public function metadata(array $response): array
    {
        $request = is_array($response['request'] ?? null) ? $response['request'] : null;

        return [
            'request' => $request,
            'request_id' => $request['request_id'] ?? null,
            'result_id' => $response['result_id'] ?? null,
            // Search, browse and recommendations nest experiments in
            // `response`; autocomplete has them at the top level.
            'experiments' => $response['response']['experiments'] ?? $response['experiments'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $collection
     * @return array<string, mixed>
     */
    public function collection(array $collection): array
    {
        $data = $collection['data'] ?? [];

        return [
            'id' => (string) ($collection['id'] ?? ''),
            'name' => $collection['display_name'] ?? '',
            'description' => $data['description'] ?? null,
            'image' => $data['image_url'] ?? null,
            'data' => $data,
            'start_time' => $collection['start_time'] ?? null,
            'end_time' => $collection['end_time'] ?? null,
            // Content scheduling: the collection currently shown in this one's place.
            'active_overriding_collection' => $collection['active_overriding_collection'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    private function facetOption(array $option): array
    {
        return [
            'value' => (string) ($option['value'] ?? ''),
            'display_name' => $option['display_name'] ?? (string) ($option['value'] ?? ''),
            'count' => (int) ($option['count'] ?? 0),
            'selected' => ($option['status'] ?? '') === 'selected',
            'data' => $option['data'] ?? [],
            'range' => $option['range'] ?? null,
            'options' => $this->facetOptions($option['options'] ?? []),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function facetOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return array_values(array_map(fn (array $option) => $this->facetOption($option), array_filter($options, 'is_array')));
    }

    /**
     * Map Constructor's facet types to UI-friendly names.
     */
    public static function facetType(string $constructorType): string
    {
        return match ($constructorType) {
            'single' => 'single_select',
            'range' => 'range',
            'hierarchical' => 'hierarchical',
            default => 'checkbox_list',
        };
    }

    private function humanize(string $name): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $name));
    }
}
