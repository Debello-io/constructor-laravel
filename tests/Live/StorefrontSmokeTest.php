<?php

namespace ConstructorIO\Laravel\Tests\Live;

use ConstructorIO\Laravel\Services\AgentService;
use ConstructorIO\Laravel\Services\QuizService;
use ConstructorIO\Laravel\Services\RecommendationService;
use ConstructorIO\Laravel\Services\SearchService;

/**
 * Read-only checks of every storefront call against a real index. Each test
 * proves that Constructor accepts the request the package builds (path,
 * encoding, credentials, identity) and that the response maps cleanly.
 */
class StorefrontSmokeTest extends LiveTestCase
{
    private function search(): SearchService
    {
        return app(SearchService::class);
    }

    private function searchTerm(): string
    {
        return self::env('CONSTRUCTOR_LIVE_QUERY') ?? 'grill';
    }

    public function test_search_returns_results_with_a_result_id(): void
    {
        $results = $this->search()->search($this->searchTerm(), [], ['per_page' => 5]);

        $this->assertGreaterThan(0, $results->total, "No results for \"{$this->searchTerm()}\": set CONSTRUCTOR_LIVE_QUERY to a term your index matches.");
        $this->assertNotNull($results->resultId, 'result_id is needed for tracking.');
        $this->assertNotEmpty($results->products[0]['id'] ?? null);
    }

    public function test_search_handles_reserved_characters_in_the_query(): void
    {
        // Must reach /v1/search as one encoded segment, not a different path.
        $results = $this->search()->search($this->searchTerm().' 1/2" #5 ?&%', [], ['per_page' => 1]);

        $this->assertNotNull($results->resultId);
    }

    public function test_search_with_filters_and_a_range(): void
    {
        $facets = $this->search()->search($this->searchTerm(), [], ['per_page' => 1])->facets;
        $facet = collect($facets)->first(fn (array $facet) => ($facet['type'] ?? null) !== 'range' && ! empty($facet['options']));

        if ($facet === null) {
            $this->markTestSkipped('The results have no facet with options to filter by.');
        }

        $value = (string) $facet['options'][0]['value'];
        $filtered = $this->search()->search($this->searchTerm(), [$facet['name'] => $value], [
            'per_page' => 1,
            'range_filters' => ['price' => ['min' => 0]],
        ]);

        $this->assertNotNull($filtered->resultId);
    }

    public function test_autocomplete(): void
    {
        $results = $this->search()->autocomplete(mb_substr($this->searchTerm(), 0, 3));

        $this->assertNotNull($results->resultId);
    }

    public function test_category_tree_and_browse(): void
    {
        $groups = $this->search()->getBrowseGroups(['limit' => 3, 'children_limit' => 2]);
        if ($groups === []) {
            $this->markTestSkipped('The index has no categories (item groups).');
        }

        $results = $this->search()->browse('group_id', (string) $groups[0]['id'], [], ['per_page' => 5]);

        $this->assertNotNull($results->resultId);
    }

    public function test_facets(): void
    {
        $this->assertNotEmpty($this->search()->getAvailableFacets(), 'The index has no facets configured.');
        $this->assertSame(1, $this->search()->getFacetsWithOptions(['per_page' => 2])['page']);
    }

    public function test_collections(): void
    {
        $this->search()->getCollections(['per_page' => 5]);

        // throw_exceptions is on: getting here means Constructor accepted the request.
        $this->addToAssertionCount(1);
    }

    public function test_browse_items_by_id(): void
    {
        $itemId = $this->requireEnv('CONSTRUCTOR_LIVE_ITEM_ID', 'browse by item ID');

        $results = $this->search()->browseItems([$itemId]);

        $this->assertSame($itemId, $results->products[0]['id'] ?? null);
    }

    public function test_natural_language_search(): void
    {
        $results = $this->optionalFeature('Natural language search', fn () => $this->search()
            ->naturalLanguageSearch("{$this->searchTerm()} under 500 dollars", [], ['per_page' => 3]));

        $this->assertNotNull($results->resultId);
    }

    public function test_recommendation_pod(): void
    {
        $podId = $this->requireEnv('CONSTRUCTOR_LIVE_POD_ID', 'recommendations');
        $options = ['limit' => 4];
        if (($itemId = self::env('CONSTRUCTOR_LIVE_ITEM_ID')) !== null) {
            $options['item_id'] = $itemId;
        }

        $results = app(RecommendationService::class)->getRecommendations($podId, $options);

        $this->assertSame($podId, $results->podId);
        $this->assertNotNull($results->resultId);
    }

    public function test_recommendation_page(): void
    {
        $pageId = $this->requireEnv('CONSTRUCTOR_LIVE_PAGE_ID', 'page recommendations');
        $options = ($itemId = self::env('CONSTRUCTOR_LIVE_ITEM_ID')) !== null ? ['item_id' => $itemId] : [];

        $page = $this->optionalFeature('Page recommendations', fn () => app(RecommendationService::class)->getPage($pageId, $options));

        $this->assertSame($pageId, $page->pageId);
    }

    public function test_quiz_first_question(): void
    {
        $quizId = $this->requireEnv('CONSTRUCTOR_LIVE_QUIZ_ID', 'quizzes');

        $question = $this->optionalFeature('Quizzes', fn () => app(QuizService::class)->getNextQuestion($quizId));

        $this->assertTrue($question->isAvailable());
        $this->assertNotNull($question->quizVersionId);
    }

    public function test_product_insights_questions(): void
    {
        $this->requireEnv('CONSTRUCTOR_AGENT_DOMAIN', 'the AI agents');
        $itemId = $this->requireEnv('CONSTRUCTOR_LIVE_ITEM_ID', 'Product Insights Agent questions');

        $questions = $this->optionalFeature('Product Insights Agent', fn () => app(AgentService::class)->getProductQuestions($itemId, ['limit' => 3]));

        $this->assertLessThanOrEqual(3, count($questions['questions']));
    }

    public function test_shopping_agent_streams_an_answer(): void
    {
        $this->requireEnv('CONSTRUCTOR_AGENT_DOMAIN', 'the AI Shopping Agent');

        $reply = $this->optionalFeature('AI Shopping Agent', fn () => app(AgentService::class)
            ->askShoppingAgent("I'm looking for a {$this->searchTerm()}"));

        $this->assertNotSame('', $reply['message']);
        $this->assertNotNull($reply['thread_id']);
    }
}
