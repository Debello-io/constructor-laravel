<?php

namespace ConstructorIO\Laravel\Facades;

use ConstructorIO\Laravel\Testing\ConstructorFake;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * Static access to \ConstructorIO\Laravel\Constructor.
 *
 *     use ConstructorIO\Laravel\Facades\Constructor;
 *
 *     $results = Constructor::search('smoker');
 *
 * In tests, fake it: nothing is sent to Constructor, calls are recorded,
 * and unstubbed calls return empty results.
 *
 *     Constructor::fake(['search' => $results]);
 *     // ... exercise your app ...
 *     Constructor::assertSearched('grill');
 *
 * Mocking works too: Constructor::shouldReceive('search')->andReturn(...).
 *
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults search(string $query, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults naturalLanguageSearch(string $query, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults searchByImage(\SplFileInfo|string $image, array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults filterImageResults(\ConstructorIO\Laravel\DataTransferObjects\SearchResults $imageResults, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults browse(string $filterName, string $filterValue, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults browseItems(list<string> $itemIds, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults autocomplete(string $query, array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults getZeroStateData(array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\RecommendationResults getRecommendations(string $podId, array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\RecommendationResults getItemRecommendations(string $podId, string|list<string> $itemId, array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\RecommendationPageResults getRecommendationPage(string $pageId, array<string, mixed> $options = [])
 * @method static array<string, array<string, mixed>> getFacets(string $query, array<string, mixed> $filters = [])
 * @method static list<array<string, mixed>> getAvailableFacets(array<string, mixed> $options = [])
 * @method static array{facets: array<string, array<string, mixed>>, total: int, page: int, per_page: int, has_more: bool, result_id: string|null} getFacetsWithOptions(array<string, mixed> $options = [])
 * @method static list<array<string, mixed>> getFacetValuesWithImages(string $facetName, int $maxItems = 10, array<string, mixed> $options = [])
 * @method static list<array<string, mixed>> getBrowseGroups(array<string, mixed> $options = [])
 * @method static list<array<string, mixed>> getCollections(array<string, mixed> $options = [])
 * @method static array<string, mixed>|null getCollection(string $collectionId, array<string, mixed> $options = [])
 * @method static void flushCache()
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults browseCollection(string $collectionId, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static string|null getFirstProductImageFromCollection(string $collectionId)
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults searchRecipes(string $query, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\DataTransferObjects\SearchResults browseRecipes(string $filterName, string $filterValue, array<string, mixed> $filters = [], array<string, mixed> $options = [])
 * @method static array<string, mixed>|null getRecipe(string $recipeId, array<string, mixed> $options = [])
 * @method static \ConstructorIO\Laravel\Services\SearchService searchService()
 * @method static \ConstructorIO\Laravel\Services\RecommendationService recommendations()
 * @method static \ConstructorIO\Laravel\Services\AgentService agent()
 * @method static \ConstructorIO\Laravel\Services\CatalogService catalog()
 * @method static \ConstructorIO\Laravel\Services\CatalogBatchingService catalogBatching()
 * @method static \ConstructorIO\Laravel\Services\QuizService quizzes()
 * @method static \ConstructorIO\Laravel\Services\BehaviorService behavior()
 * @method static \ConstructorIO\Laravel\Services\ProductDetailsService productDetails()
 * @method static \ConstructorIO\Laravel\Services\PreferenceService preferences()
 * @method static void resolveUserIdUsing(\Closure|null $resolver)
 * @method static void resolveConsentUsing(\Closure|null $resolver)
 * @method static bool supportsZeroState()
 * @method static bool supportsRecommendations()
 * @method static bool supportsBrowseGroups()
 * @method static bool supportsCollections()
 * @method static bool supportsRecipes()
 * @method static string getProviderName()
 * @method static \ConstructorIO\Laravel\Testing\ConstructorFake stub(string $method, mixed $response)
 * @method static list<array{method: string, arguments: list<mixed>}> calls(string|null $method = null)
 * @method static void assertCalled(string $method, \Closure|null $callback = null)
 * @method static void assertNotCalled(string $method, \Closure|null $callback = null)
 * @method static void assertCalledTimes(string $method, int $times)
 * @method static void assertNothingCalled()
 * @method static void assertSearched(string|\Closure|null $query = null)
 * @method static void assertNotSearched(string|\Closure|null $query = null)
 * @method static void assertBrowsed(string|\Closure|null $filterName = null, string|null $filterValue = null)
 * @method static void assertAutocompleted(string|\Closure|null $query = null)
 * @method static void assertRecommended(string|\Closure|null $podId = null)
 *
 * @see \ConstructorIO\Laravel\Constructor
 */
class Constructor extends Facade
{
    /**
     * Replace Constructor with a recording fake for the rest of the test.
     *
     * Nothing is sent to Constructor and no credentials are needed. Stubs are
     * keyed by method name; each is a return value or a Closure that receives
     * the call's arguments. Unstubbed calls return the method's normal empty
     * result. The assert* methods (assertSearched(), assertCalled(), ...)
     * become available on the facade.
     *
     * @param  array<string, mixed>  $stubs  e.g. ['search' => $results, 'getRecommendations' => fn (string $podId) => ...]
     */
    public static function fake(array $stubs = []): ConstructorFake
    {
        $app = static::getFacadeApplication();

        if (! $app instanceof Container) {
            throw new RuntimeException('Constructor::fake() needs a Laravel application. Call it from a test that boots the framework.');
        }

        $fake = ConstructorFake::install($app, $stubs);
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \ConstructorIO\Laravel\Constructor::class;
    }
}
