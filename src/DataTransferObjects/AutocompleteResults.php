<?php

namespace ConstructorIO\Laravel\DataTransferObjects;

/**
 * Results from autocomplete() and getZeroStateData().
 *
 *     $results = Constructor::autocomplete('web');
 *
 *     foreach ($results->suggestions as $suggestion) {
 *         echo $suggestion['term'];            // "weber grill"
 *     }
 *     foreach ($results->products as $product) {
 *         echo $product['name'], $product['image_url'];
 *     }
 *
 *     // Search box focused but empty
 *     $zeroState = Constructor::getZeroStateData(['recommendation_pod_id' => 'zero-state-bestsellers']);
 *
 *     return response()->json($results->toArray());
 */
class AutocompleteResults
{
    /**
     * @param  list<array<string, mixed>>  $suggestions  Search terms: ['term' => ..., 'matched_terms' => [...], 'data' => [...]].
     * @param  list<array<string, mixed>>  $products  Product previews (see ResultTransformer::product()).
     * @param  list<array<string, mixed>>  $categories  Category suggestions: ['id', 'name', 'path', 'count'].
     * @param  list<array<string, mixed>>  $trending  Trending searches (zero state). Not populated by Constructor today.
     * @param  list<array<string, mixed>>  $popularProducts  Products from a recommendation pod (zero state).
     * @param  list<array<string, mixed>>  $topCategories  Top-level categories (zero state).
     * @param  array<string, mixed>  $metadata  request_id and result_id. Pass result_id to the page for tracking.
     * @param  array<string, list<array<string, mixed>>>  $sections  Every section Constructor returned, unmodified, keyed by
     *                                                               section name. Use it for custom sections such as "Recipes".
     */
    public function __construct(
        public readonly array $suggestions = [],
        public readonly array $products = [],
        public readonly array $categories = [],
        public readonly array $trending = [],
        public readonly array $popularProducts = [],
        public readonly array $topCategories = [],
        public readonly array $metadata = [],
        public readonly array $sections = [],
    ) {}

    public function hasSuggestions(): bool
    {
        return $this->suggestions !== [];
    }

    public function hasProducts(): bool
    {
        return $this->products !== [];
    }

    public function hasCategories(): bool
    {
        return $this->categories !== [];
    }

    public function hasTrending(): bool
    {
        return $this->trending !== [];
    }

    public function hasPopularProducts(): bool
    {
        return $this->popularProducts !== [];
    }

    public function hasTopCategories(): bool
    {
        return $this->topCategories !== [];
    }

    /**
     * True if any zero-state content (trending, popular products, top categories) is present.
     */
    public function hasZeroStateData(): bool
    {
        return $this->hasTrending() || $this->hasPopularProducts() || $this->hasTopCategories();
    }

    /**
     * True when there is nothing at all to show.
     */
    public function isEmpty(): bool
    {
        return $this->isAutocompleteEmpty() && ! $this->hasZeroStateData();
    }

    /**
     * True when there are no suggestions, products or categories (ignores zero-state content).
     */
    public function isAutocompleteEmpty(): bool
    {
        return ! $this->hasSuggestions() && ! $this->hasProducts() && ! $this->hasCategories();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'suggestions' => $this->suggestions,
            'products' => $this->products,
            'categories' => $this->categories,
            'trending' => $this->trending,
            'popularProducts' => $this->popularProducts,
            'topCategories' => $this->topCategories,
            'metadata' => $this->metadata,
            'sections' => $this->sections,
        ];
    }

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @param  list<array<string, mixed>>  $trending
     * @param  list<array<string, mixed>>  $popularProducts
     * @param  list<array<string, mixed>>  $topCategories
     * @param  array<string, mixed>  $metadata
     */
    public static function zeroState(
        array $trending = [],
        array $popularProducts = [],
        array $topCategories = [],
        array $metadata = [],
    ): self {
        return new self(
            trending: $trending,
            popularProducts: $popularProducts,
            topCategories: $topCategories,
            metadata: $metadata,
        );
    }
}
