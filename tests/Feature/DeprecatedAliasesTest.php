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
        $controller = app(LegacyController::class);

        // Resolving the controller at all proves the old type-hints work; the
        // injected objects must be the package's own singletons.
        $this->assertSame(app(Constructor::class), $controller->search);
        $this->assertSame(app(AgentService::class), $controller->agent);
        $this->assertSame(app(CatalogService::class), $controller->catalog);
    }

    public function test_old_names_resolve_to_the_same_singletons(): void
    {
        $this->assertSame(app(Constructor::class), app(ConstructorSandboxSearch::class));
        $this->assertSame(app(Constructor::class), app(SandboxSearchContract::class));
        $this->assertSame(app(CatalogService::class), app(ConstructorService::class));
    }

    public function test_instanceof_works_with_old_names(): void
    {
        $this->assertTrue(app(Constructor::class) instanceof SandboxSearchContract);
        $this->assertTrue(app(AgentService::class) instanceof ConstructorAgentService);
    }

    public function test_old_scout_names_are_aliased(): void
    {
        $this->assertTrue(class_exists(ConstructorEngine::class, false));
        // An alias resolves to the new class itself, not a subclass.
        $this->assertSame(
            \ConstructorIO\Laravel\Scout\SearchResultCollection::class,
            (new \ReflectionClass(SearchResultCollection::class))->getName(),
        );
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
