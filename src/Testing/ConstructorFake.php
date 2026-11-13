<?php

namespace ConstructorIO\Laravel\Testing;

use Closure;
use ConstructorIO\Laravel\Constructor;
use ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationPageResults;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationResults;
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Services\AgentService;
use ConstructorIO\Laravel\Services\BehaviorService;
use ConstructorIO\Laravel\Services\CatalogBatchingService;
use ConstructorIO\Laravel\Services\CatalogService;
use ConstructorIO\Laravel\Services\QuizService;
use ConstructorIO\Laravel\Services\RecommendationService;
use ConstructorIO\Laravel\Services\SearchService;
use Illuminate\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;
use ReflectionMethod;

/**
 * What the Constructor facade resolves to after Constructor::fake().
 *
 *     $fake = Constructor::fake([
 *         'search' => new SearchResults(products: [...], total: 1, page: 1, perPage: 24),
 *         'getRecommendations' => fn (string $podId) => RecommendationResults::empty($podId),
 *     ]);
 *
 *     $this->get('/search?q=grill')->assertOk();
 *
 *     Constructor::assertSearched('grill');
 *     Constructor::assertRecommended('pdp-similar');
 *
 * How it behaves:
 *
 * - Every storefront call (search, browse, autocomplete, recommendations,
 *   facets, collections, recipes) is recorded for the assertions below.
 * - A stubbed call returns its stub: a value, or a Closure that receives the
 *   call's arguments (defaults filled in) and returns the result.
 * - An unstubbed call runs the real method against an offline API client
 *   (OfflineApiClient), so it returns that method's natural empty result
 *   (an empty DTO, [] or null) and still validates its arguments.
 * - Nothing is ever sent to Constructor, and no credentials are needed.
 * - The service accessors (agent(), catalog(), quizzes(), behavior(), ...)
 *   return the container's service, also wired to the offline client. To
 *   control what a service returns, mock it the Laravel way:
 *   `$this->mock(AgentService::class, fn ($mock) => ...)`; the accessor then
 *   returns your mock.
 *
 * The fake extends Constructor, so classes that type-hint Constructor or the
 * SearchProvider contract receive it too.
 *
 * Package contributors: every public Constructor method needs an override
 * here (a one-liner calling record()) or an entry in PASSTHROUGH_METHODS.
 * ConstructorFakeTest fails until it has one.
 */
class ConstructorFake extends Constructor
{
    /**
     * Public Constructor methods that aren't Constructor API calls, so they
     * aren't recorded and keep their real behaviour.
     */
    public const PASSTHROUGH_METHODS = [
        'resolveUserIdUsing', 'resolveConsentUsing',
        'supportsZeroState', 'supportsRecommendations', 'supportsBrowseGroups',
        'supportsCollections', 'supportsRecipes', 'getProviderName',
    ];

    /** @var array<string, mixed> Method name => value or Closure. */
    private array $stubs = [];

    /** @var list<array{method: string, arguments: list<mixed>}> */
    private array $calls = [];

    /**
     * Take Constructor offline in the given container and bind a fake in the
     * real client's place. Called by the facade's Constructor::fake().
     *
     * @param  array<string, mixed>  $stubs  Method name => value or Closure.
     */
    public static function install(Container $app, array $stubs = []): self
    {
        $app->instance(ApiClient::class, $app->make(OfflineApiClient::class));

        // Services resolved earlier still hold the real client. Forget them so
        // they are rebuilt on the offline one, including services this class
        // doesn't know by name.
        foreach (array_keys($app->getBindings()) as $abstract) {
            if (str_starts_with($abstract, 'ConstructorIO\\Laravel\\Services\\')) {
                $app->forgetInstance($abstract);
            }
        }
        $app->forgetInstance(Constructor::class);

        $fake = $app->make(self::class);
        foreach ($stubs as $method => $response) {
            $fake->stub($method, $response);
        }

        // SearchProvider, 'constructor' and the 1.x names are aliases of
        // Constructor::class, so they resolve to the fake too.
        $app->instance(Constructor::class, $fake);

        return $fake;
    }

    /**
     * Set what a method returns: a value, or a Closure that receives the
     * call's arguments and returns the result.
     *
     *     Constructor::stub('search', fn (string $query) => $query === 'grill' ? $grills : SearchResults::empty());
     */
    public function stub(string $method, mixed $response): static
    {
        if (! method_exists(Constructor::class, $method) || in_array($method, self::PASSTHROUGH_METHODS, true)) {
            throw new InvalidArgumentException("Constructor::{$method}() can't be stubbed: it isn't a Constructor API call.");
        }

        $this->stubs[$method] = $response;

        return $this;
    }

    /**
     * Every recorded call, oldest first, optionally only for one method.
     *
     * @return list<array{method: string, arguments: list<mixed>}>
     */
    public function calls(?string $method = null): array
    {
        return array_values(array_filter(
            $this->calls,
            fn (array $call) => $method === null || $call['method'] === $method,
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Assertions
    |--------------------------------------------------------------------------
    */

    /**
     * Assert that a method was called, optionally with arguments the callback
     * accepts. The callback receives the call's arguments, defaults included.
     */
    public function assertCalled(string $method, ?Closure $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->matching($method, $callback),
            $callback === null
                ? "Expected Constructor::{$method}() to be called, but it wasn't."
                : "Expected Constructor::{$method}() to be called with matching arguments. It was called {$this->count($method)} time(s), none matching.",
        );
    }

    public function assertNotCalled(string $method, ?Closure $callback = null): void
    {
        PHPUnit::assertEmpty(
            $this->matching($method, $callback),
            "Constructor::{$method}() was called unexpectedly.",
        );
    }

    public function assertCalledTimes(string $method, int $times): void
    {
        PHPUnit::assertSame(
            $times,
            $this->count($method),
            "Expected Constructor::{$method}() to be called {$times} time(s), but it was called {$this->count($method)} time(s).",
        );
    }

    public function assertNothingCalled(): void
    {
        $methods = implode(', ', array_unique(array_column($this->calls, 'method')));

        PHPUnit::assertEmpty($this->calls, "Expected no Constructor calls, but these were made: {$methods}.");
    }

    /**
     * Assert that search() ran: for any query, for this exact query, or for
     * a call the callback accepts (it receives $query, $filters, $options).
     */
    public function assertSearched(string|Closure|null $query = null): void
    {
        $this->assertCalled('search', $this->firstArgumentIs($query));
    }

    public function assertNotSearched(string|Closure|null $query = null): void
    {
        $this->assertNotCalled('search', $this->firstArgumentIs($query));
    }

    /**
     * Assert that browse() ran, optionally for one filter and value
     * (assertBrowsed('group_id', 'gas-grills')), or for a call the callback
     * accepts (it receives $filterName, $filterValue, $filters, $options).
     */
    public function assertBrowsed(string|Closure|null $filterName = null, ?string $filterValue = null): void
    {
        $callback = $filterName instanceof Closure ? $filterName : fn (string $name, string $value) => ($filterName === null || $name === $filterName)
            && ($filterValue === null || $value === $filterValue);

        $this->assertCalled('browse', $callback);
    }

    /**
     * Assert that autocomplete() ran: for any prefix, this exact prefix, or a
     * call the callback accepts (it receives $query, $options).
     */
    public function assertAutocompleted(string|Closure|null $query = null): void
    {
        $this->assertCalled('autocomplete', $this->firstArgumentIs($query));
    }

    /**
     * Assert that a recommendation pod was requested, through either
     * getRecommendations() or getItemRecommendations(). The callback receives
     * $podId, $options and the item ID(s) (null for getRecommendations()).
     */
    public function assertRecommended(string|Closure|null $podId = null): void
    {
        $requests = array_merge(
            array_map(fn (array $arguments) => [$arguments[0], $arguments[1], null], $this->matching('getRecommendations')),
            array_map(fn (array $arguments) => [$arguments[0], $arguments[2], $arguments[1]], $this->matching('getItemRecommendations')),
        );

        $matches = array_filter($requests, fn (array $request) => match (true) {
            $podId === null => true,
            $podId instanceof Closure => (bool) $podId(...$request),
            default => $request[0] === $podId,
        });

        PHPUnit::assertNotEmpty(
            $matches,
            $podId === null || $podId instanceof Closure
                ? 'Expected a matching recommendation pod to be requested, but none was.'
                : "Expected recommendation pod \"{$podId}\" to be requested, but it wasn't.",
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Recorded Constructor methods
    |--------------------------------------------------------------------------
    |
    | Each override records the call and returns the stub, or the real
    | method's result on the offline client.
    |
    */

    public function search(string $query, array $filters = [], array $options = []): SearchResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function naturalLanguageSearch(string $query, array $filters = [], array $options = []): SearchResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function browse(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function browseItems(array $itemIds, array $filters = [], array $options = []): SearchResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function autocomplete(string $query, array $options = []): AutocompleteResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getZeroStateData(array $options = []): AutocompleteResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getFacets(string $query, array $filters = []): array
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getAvailableFacets(array $options = []): array
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getFacetsWithOptions(array $options = []): array
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getFacetValuesWithImages(string $facetName, int $maxItems = 10, array $options = []): array
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getBrowseGroups(array $options = []): array
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getCollections(array $options = []): array
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getCollection(string $collectionId, array $options = []): ?array
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function flushCache(): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function browseCollection(string $collectionId, array $filters = [], array $options = []): SearchResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getFirstProductImageFromCollection(string $collectionId): ?string
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function searchRecipes(string $query, array $filters = [], array $options = []): SearchResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function browseRecipes(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getRecipe(string $recipeId, array $options = []): ?array
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getRecommendations(string $podId, array $options = []): RecommendationResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getItemRecommendations(string $podId, string|array $itemId, array $options = []): RecommendationResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getRecommendationPage(string $pageId, array $options = []): RecommendationPageResults
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    /*
    |--------------------------------------------------------------------------
    | Service accessors
    |--------------------------------------------------------------------------
    |
    | Resolved on each call, so a service mocked after fake() with
    | $this->mock(...) is what the accessor returns.
    |
    */

    public function searchService(): SearchService
    {
        return app(SearchService::class);
    }

    public function recommendations(): RecommendationService
    {
        return app(RecommendationService::class);
    }

    public function agent(): AgentService
    {
        return app(AgentService::class);
    }

    public function catalog(): CatalogService
    {
        return app(CatalogService::class);
    }

    public function catalogBatching(): CatalogBatchingService
    {
        return app(CatalogBatchingService::class);
    }

    public function quizzes(): QuizService
    {
        return app(QuizService::class);
    }

    public function behavior(): BehaviorService
    {
        return app(BehaviorService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Record a call, then return its stub or the real (offline) result.
     *
     * @param  list<mixed>  $arguments  As passed; defaults are filled in here.
     */
    private function record(string $method, array $arguments): mixed
    {
        $arguments = $this->withDefaults($method, $arguments);
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];

        if (array_key_exists($method, $this->stubs)) {
            $stub = $this->stubs[$method];

            return $stub instanceof Closure ? $stub(...$arguments) : $stub;
        }

        return parent::$method(...$arguments);
    }

    /**
     * Complete an argument list with the method's default values, so
     * assertion callbacks always receive every parameter.
     *
     * @param  list<mixed>  $arguments
     * @return list<mixed>
     */
    private function withDefaults(string $method, array $arguments): array
    {
        foreach ((new ReflectionMethod(Constructor::class, $method))->getParameters() as $position => $parameter) {
            if (! array_key_exists($position, $arguments) && $parameter->isDefaultValueAvailable()) {
                $arguments[$position] = $parameter->getDefaultValue();
            }
        }

        return array_values($arguments);
    }

    /**
     * Argument lists of the recorded calls to a method that the callback accepts.
     *
     * @return list<list<mixed>>
     */
    private function matching(string $method, ?Closure $callback = null): array
    {
        $matches = [];

        foreach ($this->calls($method) as $call) {
            if ($callback === null || $callback(...$call['arguments'])) {
                $matches[] = $call['arguments'];
            }
        }

        return $matches;
    }

    private function count(string $method): int
    {
        return count($this->calls($method));
    }

    /**
     * A callback matching calls by their first argument (a query or prefix),
     * or the caller's own callback.
     */
    private function firstArgumentIs(string|Closure|null $expected): ?Closure
    {
        return match (true) {
            $expected === null => null,
            $expected instanceof Closure => $expected,
            default => fn (mixed $first) => $first === $expected,
        };
    }
}
