<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Concerns\HandlesStorefrontFailures;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\ParameterBuilder;

/**
 * Product details: the enriched attributes and badges Constructor has worked
 * out for a product, for product detail pages.
 *
 *     $details = Constructor::productDetails()->getItem('TRG-780');
 *
 *     $details['badges'];             // [['type' => 'is_global_bestseller', 'display_name' => 'Bestseller', 'metadata' => [...]]]
 *     $details['attribute_values'];   // ['color' => ['black'], 'fuel_type' => ['pellet']]
 *
 * This is not the catalog record: name, price, image and your own fields
 * come from your database or from browseItems(). Use this service for what
 * Constructor adds on top: Attribute Enrichment results and badges such as
 * "Bestseller" or "Bought 63 times last week". To show badges on listing
 * pages, read `labels` on search, browse and recommendation results instead.
 *
 * These are storefront reads: they carry the shopper's identity and, by
 * default, return nothing instead of throwing (see throw_exceptions).
 *
 * @see https://docs.constructor.com/reference/v1-product-details-get-items
 * @see https://docs.constructor.com/reference/v1-product-details-get-variations
 * @see https://docs.constructor.com/docs/products-attribute-enrichment-learn-about-badges-implement-badges
 *
 * @phpstan-type ProductDetails array{
 *     id: string,
 *     variation_id: string|null,
 *     badges: list<array{type: string, display_name: string, metadata: array<string, string>}>,
 *     attributes: array<string, list<array{value: string, type: string}>>,
 *     attribute_values: array<string, list<string>>,
 *     _raw: array<string, mixed>
 * }
 */
class ProductDetailsService
{
    use HandlesStorefrontFailures;

    /** Constructor accepts 1-20 item IDs per /v1/items request. */
    public const MAX_ITEMS_PER_REQUEST = 20;

    /** Constructor accepts 1-100 variation IDs per /v1/variations request. */
    public const MAX_VARIATIONS_PER_REQUEST = 100;

    /** The only product detail field the API offers so far, and it is required. */
    private const DEFAULT_FIELDS = ['enriched_attributes'];

    protected readonly ConstructorConfig $config;

    public function __construct(protected readonly ApiClient $api)
    {
        $this->config = $api->config();
    }

    /**
     * Product details for one item, or null if Constructor is unavailable.
     *
     * @param  array<string, mixed>  $options  See getItems().
     * @return ProductDetails|null
     */
    public function getItem(string $itemId, array $options = []): ?array
    {
        return $this->getItems([$itemId], $options)[0] ?? null;
    }

    /**
     * Product details for up to 20 items, in the order requested. Constructor
     * returns one entry per requested ID, including repeats.
     *
     * Options:
     *   section  string  Index section (default from config)
     *   fields   list    Detail fields to return (default ['enriched_attributes'],
     *                    currently the only one)
     * plus identity overrides (see ParameterBuilder).
     *
     * @param  string|list<string>  $itemIds
     * @param  array<string, mixed>  $options
     * @return list<ProductDetails> Empty if Constructor is unavailable.
     *
     * @throws InvalidArgumentException With more than 20 IDs. Checked before
     *                                  any request, so it throws even when failures are logged.
     */
    public function getItems(string|array $itemIds, array $options = []): array
    {
        $itemIds = $this->ids($itemIds, self::MAX_ITEMS_PER_REQUEST, 'getItems()', 'item');
        if ($itemIds === []) {
            return [];
        }

        return $this->attempt('product details', function () use ($itemIds, $options) {
            $response = $this->fetch('/v1/items', ['item_id' => $itemIds], $options);

            return $this->toDetails($response['response']['items'] ?? [], 'id');
        }, fn () => []);
    }

    /**
     * Product details for up to 100 variations, in the order requested.
     * Each entry's `id` is the variation ID (also in `variation_id`).
     *
     * @param  string|list<string>  $variationIds
     * @param  array<string, mixed>  $options  See getItems().
     * @return list<ProductDetails> Empty if Constructor is unavailable.
     *
     * @throws InvalidArgumentException With more than 100 IDs.
     */
    public function getVariations(string|array $variationIds, array $options = []): array
    {
        $variationIds = $this->ids($variationIds, self::MAX_VARIATIONS_PER_REQUEST, 'getVariations()', 'variation');
        if ($variationIds === []) {
            return [];
        }

        return $this->attempt('variation details', function () use ($variationIds, $options) {
            $response = $this->fetch('/v1/variations', ['variation_id' => $variationIds], $options);

            return $this->toDetails($response['response']['variations'] ?? [], 'variation_id');
        }, fn () => []);
    }

    /**
     * @param  array<string, list<string>>  $ids
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function fetch(string $path, array $ids, array $options): array
    {
        return $this->api->getStorefront(
            $path,
            $ids + [
                'section' => $options['section'] ?? $this->config->defaultSection,
                'fields' => array_values(array_map('strval', (array) ($options['fields'] ?? self::DEFAULT_FIELDS))),
            ],
            ParameterBuilder::identity($options),
            $this->config->productDetailsBaseUrl,
        );
    }

    /**
     * Non-empty IDs as strings, at most $limit of them.
     *
     * @param  string|array<mixed>  $ids
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function ids(string|array $ids, int $limit, string $method, string $noun): array
    {
        $ids = array_values(array_filter(
            array_map(fn (mixed $id) => is_scalar($id) ? trim((string) $id) : '', (array) $ids),
            fn (string $id) => $id !== '',
        ));

        if (count($ids) > $limit) {
            throw new InvalidArgumentException(sprintf(
                '%s accepts at most %d %s IDs per call (Constructor\'s limit); got %d. Split the list.',
                $method,
                $limit,
                $noun,
                count($ids),
            ));
        }

        return $ids;
    }

    /**
     * @param  mixed  $entries  The `items` or `variations` list from the response.
     * @param  'id'|'variation_id'  $idField  Where the response puts each entry's ID.
     * @return list<ProductDetails>
     */
    private function toDetails(mixed $entries, string $idField): array
    {
        $details = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry) || ! is_scalar($entry[$idField] ?? null)) {
                continue;
            }

            $enriched = is_array($entry['enriched_attributes'] ?? null) ? $entry['enriched_attributes'] : [];
            $attributes = $this->attributes($enriched['attributes'] ?? null);

            $details[] = [
                'id' => (string) $entry[$idField],
                'variation_id' => $idField === 'variation_id' ? (string) $entry[$idField] : null,
                'badges' => $this->badges($enriched['badges'] ?? null),
                'attributes' => $attributes,
                'attribute_values' => array_map(
                    fn (array $values) => array_column($values, 'value'),
                    $attributes,
                ),
                '_raw' => $entry,
            ];
        }

        return $details;
    }

    /**
     * @return list<array{type: string, display_name: string, metadata: array<string, string>}>
     */
    private function badges(mixed $badges): array
    {
        $result = [];

        foreach (is_array($badges) ? $badges : [] as $badge) {
            if (! is_array($badge) || ! is_scalar($badge['type'] ?? null)) {
                continue;
            }

            $metadata = is_array($badge['metadata'] ?? null) ? $badge['metadata'] : [];

            $result[] = [
                'type' => (string) $badge['type'],
                'display_name' => is_scalar($badge['display_name'] ?? null) ? (string) $badge['display_name'] : (string) $badge['type'],
                'metadata' => array_map('strval', array_filter($metadata, 'is_scalar')),
            ];
        }

        return $result;
    }

    /**
     * Attributes keyed by name, each a list of {value, type}. Type is
     * "standard" or "extended" (an enriched value outside your own taxonomy).
     *
     * @return array<string, list<array{value: string, type: string}>>
     */
    private function attributes(mixed $attributes): array
    {
        $result = [];

        foreach (is_array($attributes) ? $attributes : [] as $name => $values) {
            $result[(string) $name] = [];

            foreach (is_array($values) ? $values : [] as $value) {
                if (is_array($value) && is_scalar($value['value'] ?? null)) {
                    $result[(string) $name][] = [
                        'value' => (string) $value['value'],
                        'type' => is_scalar($value['type'] ?? null) ? (string) $value['type'] : 'standard',
                    ];
                }
            }
        }

        return $result;
    }
}
