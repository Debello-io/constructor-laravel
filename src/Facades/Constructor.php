<?php

namespace ConstructorIO\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Static access to \ConstructorIO\Laravel\Constructor.
 *
 *     use ConstructorIO\Laravel\Facades\Constructor;
 *
 *     $results = Constructor::search('smoker');
 *
 * In tests, mock it like any facade:
 *
 *     Constructor::shouldReceive('search')->andReturn(SearchResults::empty());
 *
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults search(string $query, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults browse(string $filterName, string $filterValue, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults browseItems(list<string> $itemIds, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults autocomplete(string $query, array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults getZeroStateData(array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\RecommendationResults getRecommendations(string $podId, array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\RecommendationResults getItemRecommendations(string $podId, string|list<string> $itemId, array<string, mixed> $options = [])
 * @method static array<string, array<string, mixed>> getFacets(string $query, array<string, mixed> $filters = [])
 * @method static list<array<string, mixed>> getAvailableFacets()
 * @method static list<array<string, mixed>> getFacetValuesWithImages(string $facetName, int $maxItems = 10)
 * @method static list<array<string, mixed>> getBrowseGroups(array<string, mixed> $options = [])
 * @method static list<array<string, mixed>> getCollections(array<string, mixed> $options = [])
 * @method static array<string, mixed>|null getCollection(string $collectionId)
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults browseCollection(string $collectionId, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static string|null getFirstProductImageFromCollection(string $collectionId)
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults searchRecipes(string $query, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults browseRecipes(string $filterName, string $filterValue, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static array<string, mixed>|null getRecipe(string $recipeId)
 * @method static \ConstructorIO\Laravel\Services\SearchService searchService()
 * @method static \ConstructorIO\Laravel\Services\RecommendationService recommendations()
 * @method static \ConstructorIO\Laravel\Services\AgentService agent()
 * @method static \ConstructorIO\Laravel\Services\CatalogService catalog()
 * @method static void resolveUserIdUsing(\Closure|null $resolver)
 * @method static void resolveConsentUsing(\Closure|null $resolver)
 * @method static bool supportsZeroState()
 * @method static bool supportsRecommendations()
 * @method static bool supportsBrowseGroups()
 * @method static bool supportsCollections()
 * @method static bool supportsRecipes()
 * @method static string getProviderName()
 *
 * @see \ConstructorIO\Laravel\Constructor
 */
class Constructor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ConstructorIO\Laravel\Constructor::class;
    }
}
