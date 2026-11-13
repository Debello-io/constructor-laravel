<?php

namespace ConstructorIO\Laravel;

use Closure;
use ConstructorIO\Laravel\Contracts\SearchProvider;
use ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationPageResults;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationResults;
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Services\AgentService;
use ConstructorIO\Laravel\Services\BehaviorService;
use ConstructorIO\Laravel\Services\CatalogBatchingService;
use ConstructorIO\Laravel\Services\CatalogService;
use ConstructorIO\Laravel\Services\PreferenceService;
use ConstructorIO\Laravel\Services\ProductDetailsService;
use ConstructorIO\Laravel\Services\QuizService;
use ConstructorIO\Laravel\Services\RecommendationService;
use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Support\RequestContext;
use Illuminate\Http\Request;
use SensitiveParameter;
use SplFileInfo;

/**
 * The package's entry point, and what the Constructor facade resolves to.
 *
 * Storefront methods (search, browse, autocomplete, recommendations...) are
 * available directly. The AI agents, quizzes, product details, shopper
 * preferences, catalog management and offline behavioral actions have their
 * own services:
 *
 *     Constructor::search('pellet grill');
 *     Constructor::getRecommendations('home-bestsellers');
 *     Constructor::agent()->askShoppingAgent('A grill for a small balcony');
 *     Constructor::catalog()->uploadCatalog(storage_path('catalog/items.jsonl'));
 *     Constructor::quizzes()->getNextQuestion('grill-finder');
 *     Constructor::behavior()->recordOfflinePurchases($storeSales);
 *     Constructor::productDetails()->getItem('TRG-780');
 *     Constructor::preferences()->setForCurrentUser('fuel_type', ['pellet']);
 *
 * Each method here only delegates, so its full documentation lives on the
 * service it calls.
 */
class Constructor implements SearchProvider
{
    public function __construct(
        protected readonly SearchService $search,
        protected readonly RecommendationService $recommendations,
        protected readonly AgentService $agent,
        protected readonly CatalogService $catalog,
        protected readonly CatalogBatchingService $catalogBatching,
        protected readonly QuizService $quizzes,
        protected readonly BehaviorService $behavior,
        protected readonly ProductDetailsService $productDetails,
        protected readonly PreferenceService $preferences,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    */

    public function searchService(): SearchService
    {
        return $this->search;
    }

    public function recommendations(): RecommendationService
    {
        return $this->recommendations;
    }

    /**
     * AI Shopping Agent and Product Insights Agent.
     */
    public function agent(): AgentService
    {
        return $this->agent;
    }

    /**
     * Catalog uploads, items, variations, item groups and task status.
     * Requires CONSTRUCTOR_API_TOKEN.
     */
    public function catalog(): CatalogService
    {
        return $this->catalog;
    }

    /**
     * The Catalog Batching Service, for frequent small item and variation
     * updates (price, stock). Requires CONSTRUCTOR_API_TOKEN.
     */
    public function catalogBatching(): CatalogBatchingService
    {
        return $this->catalogBatching;
    }

    /**
     * Quizzes: next question, results and results page settings.
     */
    public function quizzes(): QuizService
    {
        return $this->quizzes;
    }

    /**
     * Offline purchases and returns (in store, by phone). Requires CONSTRUCTOR_API_TOKEN.
     */
    public function behavior(): BehaviorService
    {
        return $this->behavior;
    }

    /**
     * Product details: enriched attributes and badges for product pages.
     */
    public function productDetails(): ProductDetailsService
    {
        return $this->productDetails;
    }

    /**
     * Shopper preferences (User profile API). Needs a token with the
     * user_profile.user_preferences(w) scope.
     */
    public function preferences(): PreferenceService
    {
        return $this->preferences;
    }

    /*
    |--------------------------------------------------------------------------
    | Shopper identity hooks
    |--------------------------------------------------------------------------
    */

    /**
     * @param  (Closure(Request|null): (string|int|null))|null  $resolver
     *
     * @see RequestContext::resolveUserIdUsing()
     */
    public static function resolveUserIdUsing(?Closure $resolver): void
    {
        RequestContext::resolveUserIdUsing($resolver);
    }

    /**
     * @param  (Closure(Request|null): bool)|null  $resolver
     *
     * @see RequestContext::resolveConsentUsing()
     */
    public static function resolveConsentUsing(?Closure $resolver): void
    {
        RequestContext::resolveConsentUsing($resolver);
    }

    /*
    |--------------------------------------------------------------------------
    | Search & browse (SearchService)
    |--------------------------------------------------------------------------
    */

    public function search(string $query, array $filters = [], array $options = []): SearchResults
    {
        return $this->search->search($query, $filters, $options);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options  See ParameterBuilder.
     */
    public function naturalLanguageSearch(string $query, array $filters = [], array $options = []): SearchResults
    {
        return $this->search->naturalLanguageSearch($query, $filters, $options);
    }

    /**
     * Products that look like a photo. $image is an UploadedFile, a trusted
     * file (new SplFileInfo($path)) or the image bytes; a string is never read
     * as a path. See SearchService::searchByImage().
     *
     * @param  array<string, mixed>  $options
     */
    public function searchByImage(#[SensitiveParameter] SplFileInfo|string $image, array $options = []): SearchResults
    {
        return $this->search->searchByImage($image, $options);
    }

    /**
     * Narrow searchByImage() results with filters.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options  See ParameterBuilder.
     */
    public function filterImageResults(SearchResults $imageResults, array $filters = [], array $options = []): SearchResults
    {
        return $this->search->filterImageResults($imageResults, $filters, $options);
    }

    public function browse(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults
    {
        return $this->search->browse($filterName, $filterValue, $filters, $options);
    }

    /**
     * @param  list<string>  $itemIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function browseItems(array $itemIds, array $filters = [], array $options = []): SearchResults
    {
        return $this->search->browseItems($itemIds, $filters, $options);
    }

    public function autocomplete(string $query, array $options = []): AutocompleteResults
    {
        return $this->search->autocomplete($query, $options);
    }

    public function getZeroStateData(array $options = []): AutocompleteResults
    {
        return $this->search->getZeroStateData($options);
    }

    public function getFacets(string $query, array $filters = []): array
    {
        return $this->search->getFacets($query, $filters);
    }

    /**
     * @param  array<string, mixed>  $options  section, fmt_options (e.g. show_hidden_facets)
     */
    public function getAvailableFacets(array $options = []): array
    {
        return $this->search->getAvailableFacets($options);
    }

    /**
     * @param  array<string, mixed>  $options  section, page, offset, per_page (max 10), fmt_options, user_segments
     * @return array{facets: array<string, array<string, mixed>>, total: int, page: int, per_page: int, has_more: bool, result_id: string|null}
     */
    public function getFacetsWithOptions(array $options = []): array
    {
        return $this->search->getFacetsWithOptions($options);
    }

    /**
     * @param  array<string, mixed>  $options  cache (false skips the response cache)
     * @return list<array{value: string, display_name: string, count: int, image: string|null}>
     */
    public function getFacetValuesWithImages(string $facetName, int $maxItems = 10, array $options = []): array
    {
        return $this->search->getFacetValuesWithImages($facetName, $maxItems, $options);
    }

    public function getBrowseGroups(array $options = []): array
    {
        return $this->search->getBrowseGroups($options);
    }

    public function getCollections(array $options = []): array
    {
        return $this->search->getCollections($options);
    }

    /**
     * @param  array<string, mixed>  $options  cache (false skips the response cache)
     * @return array<string, mixed>|null
     */
    public function getCollection(string $collectionId, array $options = []): ?array
    {
        return $this->search->getCollection($collectionId, $options);
    }

    /**
     * Make every cached category tree, facet list and collection stale, e.g.
     * after changing them in the dashboard. See the `cache` settings.
     */
    public function flushCache(): void
    {
        $this->search->flushCache();
    }

    public function browseCollection(string $collectionId, array $filters = [], array $options = []): SearchResults
    {
        return $this->search->browseCollection($collectionId, $filters, $options);
    }

    public function getFirstProductImageFromCollection(string $collectionId): ?string
    {
        return $this->search->getFirstProductImageFromCollection($collectionId);
    }

    public function searchRecipes(string $query, array $filters = [], array $options = []): SearchResults
    {
        return $this->search->searchRecipes($query, $filters, $options);
    }

    public function browseRecipes(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults
    {
        return $this->search->browseRecipes($filterName, $filterValue, $filters, $options);
    }

    /**
     * @param  array<string, mixed>  $options  section (default "Recipes")
     */
    public function getRecipe(string $recipeId, array $options = []): ?array
    {
        return $this->search->getRecipe($recipeId, $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Recommendations (RecommendationService)
    |--------------------------------------------------------------------------
    */

    public function getRecommendations(string $podId, array $options = []): RecommendationResults
    {
        return $this->recommendations->getRecommendations($podId, $options);
    }

    public function getItemRecommendations(string $podId, string|array $itemId, array $options = []): RecommendationResults
    {
        return $this->recommendations->getItemRecommendations($podId, $itemId, $options);
    }

    /**
     * Every pod on a recommendation page, in one request (beta endpoint).
     *
     * @param  array<string, mixed>  $options  See RecommendationService::getPage().
     */
    public function getRecommendationPage(string $pageId, array $options = []): RecommendationPageResults
    {
        return $this->recommendations->getPage($pageId, $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Feature detection
    |--------------------------------------------------------------------------
    |
    | Part of the SearchProvider contract so code written against several
    | providers can check what is available. Constructor supports all of them.
    |
    */

    public function supportsZeroState(): bool
    {
        return true;
    }

    public function supportsRecommendations(): bool
    {
        return true;
    }

    public function supportsBrowseGroups(): bool
    {
        return true;
    }

    public function supportsCollections(): bool
    {
        return true;
    }

    public function supportsRecipes(): bool
    {
        return true;
    }

    public function getProviderName(): string
    {
        return 'Constructor';
    }
}
