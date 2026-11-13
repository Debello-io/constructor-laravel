<?php

namespace ConstructorIO\Laravel;

use ConstructorIO\Laravel\Collections\SearchResultCollection;
use ConstructorIO\Laravel\Contracts\SandboxSearchContract;
use ConstructorIO\Laravel\Contracts\SearchProvider;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Scout\ConstructorEngine;
use ConstructorIO\Laravel\Services\AgentService;
use ConstructorIO\Laravel\Services\BehaviorService;
use ConstructorIO\Laravel\Services\CatalogService;
use ConstructorIO\Laravel\Services\ConstructorAgentService;
use ConstructorIO\Laravel\Services\ConstructorService;
use ConstructorIO\Laravel\Services\QuizService;
use ConstructorIO\Laravel\Services\RecommendationService;
use ConstructorIO\Laravel\Services\Search\ConstructorSandboxSearch;
use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\RequestContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;

/**
 * Registers the package's services, the facade target, the published config
 * and (when Laravel Scout is installed) the "constructor" Scout engine.
 */
class ConstructorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/constructor.php', 'constructor');

        // Config is read once per application instance. Services are
        // singletons; per-shopper values are read from the current request on
        // every call (RequestContext), so this is safe on Octane too.
        $this->app->singleton(ConstructorConfig::class, fn (Application $app) => ConstructorConfig::fromArray(
            $app->make('config')->get('constructor', []),
            (string) $app->make('config')->get('app.name', 'laravel'),
        ));

        $this->app->singleton(RequestContext::class);
        $this->app->singleton(ApiClient::class);
        $this->app->singleton(SearchService::class);
        $this->app->singleton(RecommendationService::class);
        $this->app->singleton(AgentService::class);
        $this->app->singleton(CatalogService::class);
        $this->app->singleton(QuizService::class);
        $this->app->singleton(BehaviorService::class);
        $this->app->singleton(Constructor::class);

        $this->app->alias(Constructor::class, SearchProvider::class);
        $this->app->alias(Constructor::class, 'constructor');

        $this->registerDeprecatedAliases();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/constructor.php' => config_path('constructor.php'),
            ], 'constructor-config');
        }

        // The beacon's cookies are plain text and read by JavaScript. Stop
        // Laravel from encrypting the client ID cookie we may set for it.
        EncryptCookies::except([RequestContext::CLIENT_ID_COOKIE, RequestContext::SESSION_ID_COOKIE]);

        $this->registerScoutEngine();
    }

    /**
     * Add the "constructor" driver to Scout, if Scout is installed.
     */
    protected function registerScoutEngine(): void
    {
        if (! class_exists(EngineManager::class)) {
            return;
        }

        $this->callAfterResolving(EngineManager::class, function ($manager, Application $app) {
            $manager->extend('constructor', fn () => $app->make(ConstructorEngine::class, [
                'softDelete' => (bool) $app->make('config')->get('scout.soft_delete', false),
            ]));
        });
    }

    /**
     * 1.x service names that resolve from the container, and the 2.0
     * binding each one now points to.
     *
     * @var array<class-string, class-string>
     */
    private const DEPRECATED_SERVICES = [
        ConstructorSandboxSearch::class => Constructor::class,
        SandboxSearchContract::class => Constructor::class,
        ConstructorAgentService::class => AgentService::class,
        ConstructorService::class => CatalogService::class,
    ];

    /**
     * 1.x Scout class names. Only loaded when Scout is installed, because
     * their 2.0 classes extend Scout's.
     *
     * @var list<class-string>
     */
    private const DEPRECATED_SCOUT_CLASSES = [
        Search\ConstructorEngine::class,
        SearchResultCollection::class,
    ];

    /**
     * Keep 1.x class names working.
     *
     * Each old name is a stub file that calls class_alias(). PHP does not
     * autoload a class for a type-hint check or `instanceof`, so a stub that
     * was never autoloaded would make `function (ConstructorService $x)`
     * fail. Loading the stubs here, up front, avoids that. The container
     * aliases make app(OldName::class) return the same singleton as the new name.
     */
    protected function registerDeprecatedAliases(): void
    {
        foreach (self::DEPRECATED_SERVICES as $oldName => $binding) {
            $this->loadAlias($oldName);
            $this->app->alias($binding, $oldName);
        }

        if (class_exists(Engine::class)) {
            array_map(fn (string $oldName) => $this->loadAlias($oldName), self::DEPRECATED_SCOUT_CLASSES);
        }
    }

    /**
     * Autoload a stub file so its class_alias() runs.
     */
    private function loadAlias(string $oldName): void
    {
        class_exists($oldName) || interface_exists($oldName);
    }
}
