<?php

namespace ConstructorIO\Laravel\Tests;

use ConstructorIO\Laravel\Constructor;
use ConstructorIO\Laravel\ConstructorServiceProvider;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Services\AgentService;
use ConstructorIO\Laravel\Services\BehaviorService;
use ConstructorIO\Laravel\Services\CatalogBatchingService;
use ConstructorIO\Laravel\Services\CatalogService;
use ConstructorIO\Laravel\Services\PreferenceService;
use ConstructorIO\Laravel\Services\ProductDetailsService;
use ConstructorIO\Laravel\Services\QuizService;
use ConstructorIO\Laravel\Services\RecommendationService;
use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\RequestContext;
use ConstructorIO\Laravel\Support\ResponseCache;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case: boots the package with fake credentials and blocks real
 * HTTP, so every test must fake the Constructor responses it needs.
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        RequestContext::resolveUserIdUsing(null);
        RequestContext::resolveConsentUsing(null);
        ApiClient::flushState();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [ConstructorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'BBQ Test Store');
        $app['config']->set('constructor.api_key', 'key_test');
        $app['config']->set('constructor.api_token', 'tok_secret_api_token');
        $app['config']->set('constructor.backend_token', 'backend_security_token');
        $app['config']->set('constructor.user_profile_token', 'tok_user_profile');
        $app['config']->set('constructor.agent.domain', 'test-agent-domain');
        $app['config']->set('constructor.retry_delay', 0);
    }

    /**
     * Change package config after boot. Every package singleton is rebuilt on
     * next use, so the new values apply even if a service was resolved already.
     *
     * @param  array<string, mixed>  $values  e.g. ['constructor.throw_exceptions' => true]
     */
    protected function reconfigure(array $values): void
    {
        foreach ($values as $key => $value) {
            config()->set($key, $value);
        }

        foreach ([
            ConstructorConfig::class,
            RequestContext::class,
            ApiClient::class,
            ResponseCache::class,
            SearchService::class,
            RecommendationService::class,
            AgentService::class,
            CatalogService::class,
            CatalogBatchingService::class,
            QuizService::class,
            BehaviorService::class,
            ProductDetailsService::class,
            PreferenceService::class,
            Constructor::class,
        ] as $singleton) {
            app()->forgetInstance($singleton);
        }
    }

    /**
     * Make the container's current request look like a shopper's browser request.
     *
     * @param  array<string, string>  $cookies  Raw (unencrypted) cookies, e.g. the beacon's.
     * @param  array<string, string>  $server  Extra $_SERVER values (headers as HTTP_*).
     */
    protected function actAsShopperRequest(array $cookies = [], array $server = [], string $url = 'https://store.test/search?q=grill'): Request
    {
        $cookieHeader = implode('; ', array_map(
            fn (string $name, string $value) => "{$name}={$value}",
            array_keys($cookies),
            $cookies,
        ));

        $request = Request::create($url, 'GET', server: array_merge([
            'REMOTE_ADDR' => '203.0.113.42',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Shopper Browser)',
            'HTTP_REFERER' => 'https://store.test/',
            'HTTP_COOKIE' => $cookieHeader,
        ], $server));

        // Tests run "in the console"; a matched route is what marks a real
        // shopper request (see RequestContext::request()).
        $request->setRouteResolver(fn () => new Route('GET', 'search', fn () => null));

        app()->instance('request', $request);

        return $request;
    }

    /**
     * Parse the query string of a recorded request into [name => [values]],
     * keeping repeated keys (Constructor's deepObject style).
     *
     * @return array<string, list<string>>
     */
    protected function queryOf(HttpRequest $request): array
    {
        $query = (string) parse_url($request->url(), PHP_URL_QUERY);
        $parameters = [];

        foreach (array_filter(explode('&', $query)) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parameters[rawurldecode($name)][] = rawurldecode($value);
        }

        return $parameters;
    }

    /**
     * Spy on the log. The package logs through Log::channel(), so the channel
     * call returns the same spy.
     */
    protected function spyOnLog(): MockInterface
    {
        $log = Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();

        return $log;
    }

    /**
     * The requests sent while Http::fake() was active, oldest first.
     *
     * @return list<HttpRequest>
     */
    protected function sentRequests(): array
    {
        return array_values(Http::recorded()->map(fn (array $pair) => $pair[0])->all());
    }

    protected function pathOf(HttpRequest $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }
}
