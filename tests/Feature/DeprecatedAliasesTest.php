<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Collections\SearchResultCollection;
use ConstructorIO\Laravel\Constructor;
use ConstructorIO\Laravel\Contracts\SandboxSearchContract;
use ConstructorIO\Laravel\Search\ConstructorEngine;
use ConstructorIO\Laravel\Services\AgentService;
use ConstructorIO\Laravel\Services\CatalogService;
use ConstructorIO\Laravel\Services\ConstructorAgentService;
use ConstructorIO\Laravel\Services\ConstructorService;
use ConstructorIO\Laravel\Services\Search\ConstructorSandboxSearch;
use ConstructorIO\Laravel\Tests\TestCase;

/**
 * 1.x code must keep working: type-hints, instanceof and container lookups
 * with the old class names.
 */
class DeprecatedAliasesTest extends TestCase
{
    public function test_old_names_can_be_type_hinted(): void
    {
        $controller = $this->app->make(LegacyController::class);

        $this->assertInstanceOf(Constructor::class, $controller->search);
        $this->assertInstanceOf(AgentService::class, $controller->agent);
        $this->assertInstanceOf(CatalogService::class, $controller->catalog);
    }

    public function test_old_names_resolve_to_the_same_singletons(): void
    {
        $this->assertSame($this->app->make(Constructor::class), $this->app->make(ConstructorSandboxSearch::class));
        $this->assertSame($this->app->make(Constructor::class), $this->app->make(SandboxSearchContract::class));
        $this->assertSame($this->app->make(CatalogService::class), $this->app->make(ConstructorService::class));
    }

    public function test_instanceof_works_with_old_names(): void
    {
        $this->assertTrue($this->app->make(Constructor::class) instanceof SandboxSearchContract);
        $this->assertTrue($this->app->make(AgentService::class) instanceof ConstructorAgentService);
    }

    public function test_old_scout_names_are_aliased(): void
    {
        $this->assertTrue(class_exists(ConstructorEngine::class, false));
        $this->assertTrue(is_a(
            SearchResultCollection::class,
            \ConstructorIO\Laravel\Scout\SearchResultCollection::class,
            true,
        ));
    }
}

class LegacyController
{
    public function __construct(
        public ConstructorSandboxSearch $search,
        public ConstructorAgentService $agent,
        public ConstructorService $catalog,
    ) {}
}
