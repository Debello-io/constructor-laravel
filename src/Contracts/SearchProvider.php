<?php

namespace ConstructorIO\Laravel\Contracts;

use ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationResults;
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;

/**
 * The storefront operations of a product discovery provider.
 *
 * Type-hint this interface in your controllers (instead of the concrete
 * Constructor class) to keep them easy to test and to swap the provider:
 *
 *     public function __construct(private SearchProvider $search) {}
 *
 * Filters are ['facet_name' => value or [values]]. Options are documented
 * on \ConstructorIO\Laravel\Support\ParameterBuilder.
 */
interface SearchProvider
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function search(string $query, array $filters = [], array $options = []): SearchResults;

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function browse(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults;

    /**
     * @param  array<string, mixed>  $options
     */
    public function autocomplete(string $query, array $options = []): AutocompleteResults;

    /**
     * @param  array<string, mixed>  $options
     */
    public function getZeroStateData(array $options = []): AutocompleteResults;

    /**
     * @param  array<string, mixed>  $options
     */
    public function getRecommendations(string $podId, array $options = []): RecommendationResults;

    /**
     * @param  string|list<string>  $itemId
     * @param  array<string, mixed>  $options
     */
    public function getItemRecommendations(string $podId, string|array $itemId, array $options = []): RecommendationResults;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, array<string, mixed>>
     */
    public function getFacets(string $query, array $filters = []): array;

    /**
     * @return list<array{name: string, display_name: string, type: string}>
     */
    public function getAvailableFacets(): array;

    /**
     * @return list<array{value: string, display_name: string, count: int, image: string|null}>
     */
    public function getFacetValuesWithImages(string $facetName, int $maxItems = 10): array;

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function getBrowseGroups(array $options = []): array;

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function getCollections(array $options = []): array;

    /**
     * @return array<string, mixed>|null
     */
    public function getCollection(string $collectionId): ?array;

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function browseCollection(string $collectionId, array $filters = [], array $options = []): SearchResults;

    public function getFirstProductImageFromCollection(string $collectionId): ?string;

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function searchRecipes(string $query, array $filters = [], array $options = []): SearchResults;

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function browseRecipes(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults;

    /**
     * @return array<string, mixed>|null
     */
    public function getRecipe(string $recipeId): ?array;

    public function supportsZeroState(): bool;

    public function supportsRecommendations(): bool;

    public function supportsBrowseGroups(): bool;

    public function supportsCollections(): bool;

    public function supportsRecipes(): bool;

    public function getProviderName(): string;
}
