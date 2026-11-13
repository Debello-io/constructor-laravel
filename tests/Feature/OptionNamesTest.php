<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Services\AgentService;
use ConstructorIO\Laravel\Services\RecommendationService;
use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The package-wide option names (page/per_page for paged lists, limit for
 * the rest) map to each endpoint's own parameter. The older names are
 * covered by the service tests, which still use them.
 */
class OptionNamesTest extends TestCase
{
    public function test_limit_sets_num_results_for_pods_pages_and_pod_overrides(): void
    {
        Http::fake(['*' => Http::response(['response' => ['pod' => ['id' => 'p'], 'results' => []]])]);
        $recommendations = app(RecommendationService::class);

        $recommendations->getRecommendations('home-bestsellers', ['limit' => 4]);
        $recommendations->getPage('pdp', ['limit' => 6, 'pod_overrides' => ['pdp-similar' => ['limit' => 12]]]);

        $requests = $this->sentRequests();
        $this->assertSame(['4'], $this->queryOf($requests[0])['num_results']);
        $this->assertSame(['6'], $this->queryOf($requests[1])['num_results']);
        $this->assertSame(['12'], $this->queryOf($requests[1])['pod_overrides[pdp-similar][num_results]']);
        $this->assertArrayNotHasKey('pod_overrides[pdp-similar][limit]', $this->queryOf($requests[1]));
    }

    public function test_limit_sets_num_results_for_product_questions(): void
    {
        Http::fake(['agent.cnstrc.com/*' => Http::response(['questions' => []])]);

        app(AgentService::class)->getProductQuestions('GRILL-1', ['limit' => 3]);

        Http::assertSent(fn (Request $request) => $this->queryOf($request)['num_results'] === ['3']
            && ! isset($this->queryOf($request)['limit']));
    }

    public function test_category_tree_uses_limit_and_children_limit(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['groups' => [[
            'group_id' => 'all', 'display_name' => 'All', 'children' => [
                ['group_id' => 'grills', 'display_name' => 'Grills', 'children' => [
                    ['group_id' => 'gas', 'display_name' => 'Gas'],
                    ['group_id' => 'pellet', 'display_name' => 'Pellet'],
                ]],
                ['group_id' => 'smokers', 'display_name' => 'Smokers'],
            ],
        ]]]])]);

        $groups = app(SearchService::class)->getBrowseGroups(['limit' => 1, 'children_limit' => 1]);

        $this->assertCount(1, $groups);
        $this->assertCount(1, $groups[0]['children']);
    }

    public function test_collections_use_per_page(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['collections' => []]])]);

        app(SearchService::class)->getCollections(['per_page' => 7, 'page' => 2]);

        Http::assertSent(fn (Request $request) => $this->queryOf($request)['num_results_per_page'] === ['7']
            && $this->queryOf($request)['page'] === ['2']);
    }
}
