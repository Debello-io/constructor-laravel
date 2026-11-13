<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Constructor as ConstructorClient;
use ConstructorIO\Laravel\Contracts\SearchProvider;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationResults;
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Services\AgentService;
use ConstructorIO\Laravel\Testing\ConstructorFake;
use ConstructorIO\Laravel\Testing\OfflineApiClient;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use PHPUnit\Framework\ExpectationFailedException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Constructor::fake(), used the way an application's test suite would.
 */
class ConstructorFakeTest extends TestCase
{
    private function grills(): SearchResults
    {
        return new SearchResults(
            products: [['id' => 'TRG-780', 'name' => 'Pro 780']],
            total: 1,
            page: 1,
            perPage: 24,
            resultId: 'test-result',
        );
    }

    public function test_a_page_renders_stubbed_results_and_the_search_can_be_asserted(): void
    {
        Route::get('/search', fn () => Constructor::search((string) request('q'), [], ['per_page' => 24])->products[0]['name'] ?? 'none');
        Constructor::fake(['search' => $this->grills()]);

        $this->get('/search?q=grill')->assertOk()->assertSee('Pro 780');

        Constructor::assertSearched('grill');
        Constructor::assertSearched(fn (string $query, array $filters, array $options) => $options['per_page'] === 24);
        Constructor::assertNotSearched('smoker');
        Constructor::assertCalledTimes('search', 1);
    }

    public function test_unstubbed_calls_return_natural_empty_results_without_any_http(): void
    {
        Http::fake();
        // No credentials: a faked app shouldn't need any.
        $this->reconfigure(['constructor.api_key' => null, 'constructor.api_token' => null]);
        Constructor::fake();

        $this->assertTrue(Constructor::search('grill')->isEmpty());
        $this->assertSame('pdp-similar', Constructor::getItemRecommendations('pdp-similar', 'TRG-780')->podId);
        $this->assertNull(Constructor::getCollection('summer'));
        $this->assertSame([], Constructor::getBrowseGroups());
        $this->assertSame([], Constructor::getFacetsWithOptions()['facets']);
        $this->assertSame([], Constructor::autocomplete('web')->products);

        Http::assertNothingSent();
    }

    public function test_closure_stubs_receive_the_arguments_with_defaults(): void
    {
        Constructor::fake([
            'getRecommendations' => fn (string $podId, array $options) => RecommendationResults::empty($podId, 'Limit '.($options['limit'] ?? 'default')),
        ]);

        $this->assertSame('Limit 4', Constructor::getRecommendations('home', ['limit' => 4])->title);
        $this->assertSame('Limit default', Constructor::getRecommendations('home')->title);
    }

    public function test_stubs_can_be_added_after_faking(): void
    {
        $fake = Constructor::fake();
        $fake->stub('search', fn (string $query) => $query === 'grill' ? $this->grills() : SearchResults::empty());

        $this->assertSame(1, Constructor::search('grill')->total);
        $this->assertSame(0, Constructor::search('kayak')->total);
    }

    public function test_type_hinted_injection_receives_the_fake(): void
    {
        $fake = Constructor::fake();

        $this->assertSame($fake, app(ConstructorClient::class));
        $this->assertSame($fake, app(SearchProvider::class));
        $this->assertSame($fake, app('constructor'));
        $this->assertSame($fake, app(SearchPage::class)->constructor);
    }

    public function test_browse_autocomplete_and_recommendation_assertions(): void
    {
        Constructor::fake();

        Constructor::browse('group_id', 'gas-grills', ['brand' => 'Weber']);
        Constructor::autocomplete('web');
        Constructor::getRecommendations('home-bestsellers');
        Constructor::getItemRecommendations('pdp-similar', ['TRG-780', 'WEB-1'], ['limit' => 6]);

        Constructor::assertBrowsed();
        Constructor::assertBrowsed('group_id', 'gas-grills');
        Constructor::assertBrowsed(fn (string $name, string $value, array $filters) => $filters === ['brand' => 'Weber']);
        Constructor::assertAutocompleted('web');
        Constructor::assertRecommended('home-bestsellers');
        Constructor::assertRecommended('pdp-similar');
        Constructor::assertRecommended(fn (string $podId, array $options, string|array|null $itemId) => $itemId === ['TRG-780', 'WEB-1'] && $options['limit'] === 6);
        Constructor::assertNotCalled('search');
        Constructor::assertCalled('browse');
        $this->assertCount(4, Constructor::calls());
        $this->assertSame('gas-grills', Constructor::calls('browse')[0]['arguments'][1]);
    }

    public function test_failed_assertions_explain_what_happened(): void
    {
        Constructor::fake();
        Constructor::search('smoker');

        try {
            Constructor::assertSearched('grill');
            $this->fail('assertSearched() should have failed.');
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('called 1 time(s), none matching', $e->getMessage());
        }

        try {
            Constructor::assertNothingCalled();
            $this->fail('assertNothingCalled() should have failed.');
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('search', $e->getMessage());
        }

        $this->expectException(ExpectationFailedException::class);
        Constructor::assertRecommended('home-bestsellers');
    }

    public function test_arguments_are_still_validated(): void
    {
        Constructor::fake();

        $this->expectException(InvalidArgumentException::class);
        Constructor::browseItems(array_map(fn (int $i) => "SKU-{$i}", range(1, 101)));
    }

    public function test_unknown_methods_cannot_be_stubbed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Constructor::fake(['serach' => SearchResults::empty()]);
    }

    public function test_services_work_offline_and_can_be_mocked(): void
    {
        Http::fake();
        $this->reconfigure(['constructor.agent.domain' => 'bbq-test']);
        Constructor::fake();

        // Real services, offline: catalog writes and task polling finish at once.
        Constructor::catalog()->upsertItems([['id' => 'TRG-780', 'name' => 'Pro 780']]);
        $this->assertTrue(Constructor::catalog()->waitForTaskCompletion(42)['successful']);
        $this->assertSame('', Constructor::agent()->askShoppingAgent('A small smoker')['message']);
        Http::assertNothingSent();

        // A mock registered after fake() is what the accessor returns.
        $this->mock(AgentService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('getProductQuestions')->andReturn(['questions' => ['Can it cold smoke?'], '_raw' => []]));

        $this->assertSame(['Can it cold smoke?'], Constructor::agent()->getProductQuestions('TRG-780')['questions']);
    }

    /**
     * Guards the fake against new Constructor methods: each one must be
     * recorded (an override in ConstructorFake) or explicitly passed through.
     */
    public function test_every_constructor_method_is_handled_by_the_fake(): void
    {
        $unhandled = [];

        foreach ((new ReflectionClass(ConstructorClient::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            $overridden = (new ReflectionMethod(ConstructorFake::class, $name))->getDeclaringClass()->getName() === ConstructorFake::class;

            if (! $method->isConstructor() && ! $overridden && ! in_array($name, ConstructorFake::PASSTHROUGH_METHODS, true)) {
                $unhandled[] = $name;
            }
        }

        $this->assertSame([], $unhandled, 'Add a one-line override to src/Testing/ConstructorFake.php for each of these Constructor methods.');
    }

    public function test_every_sending_api_client_method_is_offline_while_faked(): void
    {
        // ApiClient methods that don't send anything.
        $doesNotSend = ['__construct', 'config', 'throwIfFailed', 'flushState'];
        $sendsForReal = [];

        foreach ((new ReflectionClass(ApiClient::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            $offline = (new ReflectionMethod(OfflineApiClient::class, $name))->getDeclaringClass()->getName() === OfflineApiClient::class;

            if (! $offline && ! in_array($name, $doesNotSend, true)) {
                $sendsForReal[] = $name;
            }
        }

        $this->assertSame([], $sendsForReal, 'Override these in src/Testing/OfflineApiClient.php, or a faked app would call Constructor for real.');
    }
}

/**
 * An application class that type-hints the concrete client.
 */
class SearchPage
{
    public function __construct(public readonly ConstructorClient $constructor) {}
}
