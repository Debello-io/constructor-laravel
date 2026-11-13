<?php

namespace ConstructorIO\Laravel\Tests\Live;

use Closure;
use ConstructorIO\Laravel\ConstructorServiceProvider;
use ConstructorIO\Laravel\Exceptions\ApiException;
use Dotenv\Dotenv;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base class for the smoke tests that call a real Constructor index.
 *
 * Credentials come from .env.live in the package root (see .env.live.example)
 * or from the process environment. Without CONSTRUCTOR_API_KEY every live
 * test is skipped, so this suite is safe to run anywhere.
 *
 * Unlike the main suite, real HTTP is allowed, and throw_exceptions is on so
 * that a failure shows up as an error instead of an empty result.
 */
abstract class LiveTestCase extends Orchestra
{
    private const TEST_STORE_NAME = 'Constructor Laravel Smoke Test';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::env('CONSTRUCTOR_API_KEY') === null) {
            $this->markTestSkipped('Set CONSTRUCTOR_API_KEY in .env.live to run the live smoke tests.');
        }
    }

    protected function getPackageProviders($app): array
    {
        return [ConstructorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // safeLoad(): a missing .env.live is fine; values already in the
        // environment win, because the repository is immutable.
        Dotenv::createImmutable(dirname(__DIR__, 2), '.env.live')->safeLoad();

        $app['config']->set('app.name', self::TEST_STORE_NAME);
        $app['config']->set('constructor.api_key', self::env('CONSTRUCTOR_API_KEY'));
        $app['config']->set('constructor.backend_token', self::env('CONSTRUCTOR_BACKEND_TOKEN'));
        $app['config']->set('constructor.api_token', self::env('CONSTRUCTOR_API_TOKEN'));
        $app['config']->set('constructor.agent.domain', self::env('CONSTRUCTOR_AGENT_DOMAIN'));
        $app['config']->set('constructor.default_section', self::env('CONSTRUCTOR_SECTION') ?? 'Products');
        $app['config']->set('constructor.throw_exceptions', true);
        // Not a shopper's browser: don't try to set the beacon cookie.
        $app['config']->set('constructor.identity.generate_client_id', false);
    }

    /**
     * A setting from .env.live or the environment; empty strings count as unset.
     */
    protected static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Skip the test unless the named setting is present.
     */
    protected function requireEnv(string $name, string $enables): string
    {
        $value = self::env($name);
        if ($value === null) {
            $this->markTestSkipped("Set {$name} in .env.live to check {$enables}.");
        }

        return $value;
    }

    /**
     * Run a check for a feature that not every Constructor account has
     * (natural language search, page recommendations, quizzes, ...). A 4xx
     * answer skips the test with the reason instead of failing it.
     *
     * @template T
     *
     * @param  Closure(): T  $check
     * @return T
     */
    protected function optionalFeature(string $feature, Closure $check): mixed
    {
        try {
            return $check();
        } catch (ApiException $e) {
            $status = $e->getStatusCode();
            // 401 means bad credentials and 429 means throttling: real failures.
            // Other 4xx answers usually mean the feature isn't on this account.
            if ($status >= 400 && $status < 500 && ! in_array($status, [401, 429], true)) {
                $this->markTestSkipped("{$feature} answered {$status}; it may not be enabled for this index.");
            }

            throw $e;
        }
    }
}
