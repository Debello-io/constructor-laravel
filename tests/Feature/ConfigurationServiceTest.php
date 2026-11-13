<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Constructor;
use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Exceptions\RateLimitException;
use ConstructorIO\Laravel\Services\ConfigurationService;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The configuration (merchandising management) APIs: redirect rules,
 * synonyms, sort options, facets, facet options and refined queries.
 */
class ConfigurationServiceTest extends TestCase
{
    private function configuration(): ConfigurationService
    {
        return app(Constructor::class)->configuration();
    }

    private function assertCatalogAuth(Request $request): void
    {
        $this->assertSame(['Basic '.base64_encode('tok_secret_api_token:')], $request->header('Authorization'));
        $this->assertFalse($request->hasHeader('x-cnstrc-token'), 'The backend token is for storefront calls only');
        $this->assertSame(['key_test'], $this->queryOf($request)['key']);
    }

    public function test_redirect_rules_crud(): void
    {
        Http::fake([
            '*/v1/redirect_rules/404*' => Http::response(['message' => 'Not found'], 404),
            '*/v1/redirect_rules/*' => Http::response(['id' => 7, 'url' => '/gift-cards', 'matches' => []]),
            '*/v1/redirect_rules?*' => Http::sequence()
                ->push(['redirect_rules' => [['id' => 7, 'url' => '/gift-cards', 'matches' => []]], 'total_count' => 1])
                ->push(['id' => 8, 'url' => '/store-hours', 'matches' => []], 201),
        ]);
        $rules = $this->configuration()->redirectRules();

        $list = $rules->list(['query' => 'gift', 'status' => 'current', 'ids' => [7, 9], 'per_page' => 500, 'page' => 2]);
        $created = $rules->create(['url' => '/store-hours', 'matches' => [['pattern' => 'hours', 'match_type' => 'PHRASE']]]);
        $rules->update(7, ['url' => '/gift-cards-2']);
        $rules->replace('7', ['url' => '/gift-cards', 'matches' => [['pattern' => 'gift card', 'match_type' => 'EXACT']]]);
        $rules->delete(7);

        $this->assertSame(1, $list['total_count']);
        $this->assertSame(8, $created['id']);
        $this->assertNull($rules->get(404));

        [$listRequest, $create, $patch, $put, $delete] = $this->sentRequests();

        $this->assertSame('GET', $listRequest->method());
        $this->assertSame('/v1/redirect_rules', $this->pathOf($listRequest));
        $this->assertSame(['7', '9'], $this->queryOf($listRequest)['id']);
        $this->assertSame(['100'], $this->queryOf($listRequest)['num_results_per_page'], 'per_page is capped at 100');
        $this->assertSame(['2'], $this->queryOf($listRequest)['page']);
        $this->assertSame(['current'], $this->queryOf($listRequest)['status']);
        $this->assertArrayNotHasKey('section', $this->queryOf($listRequest), 'Redirect rules are index-wide');
        $this->assertCatalogAuth($listRequest);

        $this->assertSame('POST', $create->method());
        $this->assertSame('/v1/redirect_rules', $this->pathOf($create));
        $this->assertSame('/store-hours', $create->data()['url']);
        $this->assertCatalogAuth($create);

        $this->assertSame(['PATCH', '/v1/redirect_rules/7'], [$patch->method(), $this->pathOf($patch)]);
        $this->assertSame(['url' => '/gift-cards-2'], $patch->data());
        $this->assertSame(['PUT', '/v1/redirect_rules/7'], [$put->method(), $this->pathOf($put)]);
        $this->assertSame(['DELETE', '/v1/redirect_rules/7'], [$delete->method(), $this->pathOf($delete)]);
    }

    public function test_numeric_ids_are_validated_before_sending(): void
    {
        Http::fake();

        foreach (['../items', '7?key=other', '0', '-1', ''] as $badId) {
            try {
                $this->configuration()->redirectRules()->get($badId);
                $this->fail("Expected '{$badId}' to be rejected.");
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        Http::assertNothingSent();
    }

    public function test_synonym_groups_send_the_section_and_validate_phrases(): void
    {
        Http::fake([
            '*/v1/synonym_groups?*' => Http::sequence()
                ->push(['synonym_groups' => [['synonym_group_id' => 3, 'synonyms' => ['bbq', 'barbecue']]], 'total_count' => 1])
                ->push(['group_id' => 12]),
            '*/v1/synonym_groups/*' => Http::response(null, 204),
        ]);
        $groups = $this->configuration()->synonymGroups();

        $list = $groups->list(['phrase' => 'bbq', 'section' => 'Search Suggestions']);
        $groupId = $groups->create(['bbq', ' barbecue ', '', 'grill']);
        $groups->replace(12, ['bbq', 'barbecue']);
        $groups->delete(12);

        $this->assertSame(3, $list['synonym_groups'][0]['synonym_group_id']);
        $this->assertSame(12, $groupId);

        [$listRequest, $create, $put, $delete] = $this->sentRequests();
        $this->assertSame(['Search Suggestions'], $this->queryOf($listRequest)['section']);
        $this->assertSame(['bbq'], $this->queryOf($listRequest)['phrase']);
        $this->assertSame(['Products'], $this->queryOf($create)['section'], 'Defaults to CONSTRUCTOR_SECTION');
        $this->assertSame(['synonyms' => ['bbq', 'barbecue', 'grill']], $create->data());
        $this->assertSame(['PUT', '/v1/synonym_groups/12'], [$put->method(), $this->pathOf($put)]);
        $this->assertSame(['DELETE', '/v1/synonym_groups/12'], [$delete->method(), $this->pathOf($delete)]);
        $this->assertCatalogAuth($create);

        $this->expectException(InvalidArgumentException::class);
        $groups->create(array_fill(0, 21, 'grill'));
    }

    public function test_one_way_synonyms_wrap_child_phrases_and_encode_the_parent(): void
    {
        Http::fake([
            '*/v2/one_way_synonyms/*' => Http::response(null, 204),
            '*/v2/one_way_synonyms?*' => Http::response(['one_way_synonym_relations' => [], 'total_count' => 0]),
        ]);
        $synonyms = $this->configuration()->oneWaySynonyms();

        $synonyms->create('smoker / grill', ['pellet grill', 'offset smoker']);
        $synonyms->replace('smoker', ['pellet grill']);
        $synonyms->list(['parent_phrases' => ['smoker', 'grill']]);
        $synonyms->delete('..');

        [$create, $put, $list, $delete] = $this->sentRequests();
        $this->assertSame('POST', $create->method());
        $this->assertStringEndsWith('/v2/one_way_synonyms/smoker%20%2F%20grill', explode('?', $create->url())[0]);
        $this->assertSame(['child_phrases' => [['phrase' => 'pellet grill'], ['phrase' => 'offset smoker']]], $create->data());
        $this->assertSame('PUT', $put->method());
        $this->assertSame(['smoker', 'grill'], $this->queryOf($list)['parent_phrase']);
        $this->assertArrayNotHasKey('section', $this->queryOf($list), 'One-way synonyms are index-wide');
        $this->assertStringEndsWith('/v2/one_way_synonyms/%2E%2E', explode('?', $delete->url())[0]);
    }

    public function test_sort_options_validate_sort_order_and_batch_deletes(): void
    {
        Http::fake([
            '*/v1/sort_options?*' => Http::response(['sort_options' => [['sort_by' => 'price', 'sort_order' => 'ascending']]]),
            '*/v1/sort_option?*' => Http::response(['sort_by' => 'price']),
            '*/v1/sort_option/*' => Http::response(['sort_by' => 'price']),
        ]);
        $sortOptions = $this->configuration()->sortOptions();

        $sortOptions->create(['sort_by' => 'price', 'sort_order' => 'ascending', 'path_in_metadata' => 'data.price']);
        $saved = $sortOptions->replaceMany([['sort_by' => 'price', 'sort_order' => 'ascending', 'path_in_metadata' => 'data.price']]);
        $sortOptions->update('price', 'descending', ['display_name' => 'Price: High to Low']);
        $sortOptions->delete(array_fill(0, 201, ['sort_by' => 'price', 'sort_order' => 'ascending']));

        $this->assertSame('price', $saved[0]['sort_by']);

        [$create, $put, $patch, $firstDelete, $secondDelete] = $this->sentRequests();
        $this->assertSame(['POST', '/v1/sort_option'], [$create->method(), $this->pathOf($create)]);
        $this->assertSame(['PUT', '/v1/sort_options'], [$put->method(), $this->pathOf($put)]);
        $this->assertSame(['PATCH', '/v1/sort_option/price/descending'], [$patch->method(), $this->pathOf($patch)]);
        $this->assertSame(['display_name' => 'Price: High to Low'], $patch->data());
        $this->assertSame('DELETE', $firstDelete->method());
        $this->assertCount(200, $firstDelete->data()['sort_options']);
        $this->assertCount(1, $secondDelete->data()['sort_options']);
        $this->assertCatalogAuth($patch);
    }

    public function test_sort_option_arguments_are_checked_before_sending(): void
    {
        Http::fake();
        $sortOptions = $this->configuration()->sortOptions();

        $attempts = [
            fn () => $sortOptions->update('price', 'up', []),
            fn () => $sortOptions->delete([['sort_by' => '', 'sort_order' => 'ascending']]),
            fn () => $sortOptions->replaceMany([]),
            fn () => $sortOptions->replaceMany(array_fill(0, 51, ['sort_by' => 'price', 'sort_order' => 'ascending'])),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        Http::assertNothingSent();
    }

    public function test_facets_crud_and_bulk_writes_respect_batch_limits(): void
    {
        Http::fake([
            '*/v2/facets/missing*' => Http::response(['message' => 'Not found'], 404),
            '*/v2/facets/*' => Http::response(['name' => 'brand']),
            '*/v2/facets?*' => Http::response(['facets' => [['name' => 'brand']], 'total_count' => 1]),
        ]);
        $facets = $this->configuration()->facets();

        $facets->create(['name' => 'fuel_type', 'path_in_metadata' => 'facets.fuel_type', 'type' => 'multiple']);
        $saved = $facets->replaceMany(array_fill(0, 26, ['name' => 'brand', 'path_in_metadata' => 'facets.brand', 'type' => 'multiple']));
        $facets->updateMany([['name' => 'brand', 'display_name' => 'Brand']], 'Products');
        $facets->update('Cooking Area (sq in)', ['type' => 'range']);
        $facets->delete('brand');

        $this->assertNull($facets->get('missing'));
        $this->assertCount(2, $saved, 'One saved facet per batch in this fake');

        [$create, $putOne, $putTwo, $patchMany, $patchOne, $delete] = $this->sentRequests();
        $this->assertSame(['POST', '/v2/facets'], [$create->method(), $this->pathOf($create)]);
        $this->assertSame('fuel_type', $create->data()['name']);
        $this->assertSame('PUT', $putOne->method());
        $this->assertCount(25, $putOne->data()['facets']);
        $this->assertCount(1, $putTwo->data()['facets']);
        $this->assertSame('PATCH', $patchMany->method());
        $this->assertSame(['Products'], $this->queryOf($patchMany)['section']);
        $this->assertStringEndsWith('/v2/facets/Cooking%20Area%20%28sq%20in%29', explode('?', $patchOne->url())[0]);
        $this->assertSame(['DELETE', '/v2/facets/brand'], [$delete->method(), $this->pathOf($delete)]);
        $this->assertCatalogAuth($delete);
    }

    public function test_facet_options_send_a_json_array_and_encode_values(): void
    {
        Http::fake([
            '*/v1/facets/brand/options?*' => Http::response([['value' => 'weber', 'display_name' => 'Weber']]),
            '*/v1/facets/*' => Http::response(['value' => 'weber']),
        ]);
        $options = $this->configuration()->facetOptions();

        $saved = $options->updateMany('brand', [['value' => 'weber', 'display_name' => 'Weber']]);
        $options->list('brand', ['value_alias' => 'blue', 'per_page' => 50]);
        $options->update('brand', 'Char-Broil & Co', ['position' => 1]);

        $this->assertSame('Weber', $saved[0]['display_name']);

        [$patchMany, $list, $patchOne] = $this->sentRequests();
        $this->assertSame('PATCH', $patchMany->method());
        $this->assertSame([['value' => 'weber', 'display_name' => 'Weber']], $patchMany->data(), 'The body is a JSON array, per the API reference');
        $this->assertSame(['blue'], $this->queryOf($list)['filter[value_alias]']);
        $this->assertSame(['50'], $this->queryOf($list)['num_results_per_page']);
        $this->assertStringEndsWith('/v1/facets/brand/options/Char-Broil%20%26%20Co', explode('?', $patchOne->url())[0]);
    }

    public function test_refined_queries_send_response_section_and_validate_it(): void
    {
        Http::fake(['*' => Http::response(['id' => 5, 'query' => 'smoker'], 201)]);
        $refinedQueries = $this->configuration()->refinedQueries();

        $refinedQueries->create([
            'query' => 'smoker',
            'slot_rules' => [['rule' => ['item_id' => 'TRG-780', 'position' => 1]]],
        ]);
        $refinedQueries->update(5, ['allow_automatic_rules' => false], ['response_section' => 'facets']);
        $refinedQueries->list(['parameters' => ['query' => 'smoker', 'key' => 'key_attacker']]);

        [$create, $patch, $list] = $this->sentRequests();
        $this->assertSame(['POST', '/v1/refined_queries'], [$create->method(), $this->pathOf($create)]);
        $this->assertSame('TRG-780', $create->data()['slot_rules'][0]['rule']['item_id']);
        $this->assertArrayNotHasKey('response_section', $this->queryOf($create), 'Constructor defaults to results');
        $this->assertSame(['PATCH', '/v1/refined_queries/5'], [$patch->method(), $this->pathOf($patch)]);
        $this->assertSame(['facets'], $this->queryOf($patch)['response_section']);
        $this->assertSame(['smoker'], $this->queryOf($list)['query']);
        $this->assertSame(['key_test'], $this->queryOf($list)['key'], 'parameters can never replace the API key');

        $this->expectException(InvalidArgumentException::class);
        $refinedQueries->get(5, ['response_section' => 'banners']);
    }

    public function test_reads_are_retried_but_writes_are_not(): void
    {
        Http::fake([
            '*/v2/facets?*' => Http::sequence()->push('busy', 503)->push(['facets' => [], 'total_count' => 0]),
            '*/v1/sort_option?*' => Http::response('busy', 503),
        ]);

        $this->configuration()->facets()->list();
        Http::assertSentCount(2);

        try {
            $this->configuration()->sortOptions()->create(['sort_by' => 'price', 'sort_order' => 'ascending', 'path_in_metadata' => 'data.price']);
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        Http::assertSentCount(3);
    }

    public function test_errors_throw_typed_exceptions(): void
    {
        Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

        $this->expectException(RateLimitException::class);

        $this->configuration()->synonymGroups()->list();
    }

    public function test_a_missing_api_token_throws_before_sending(): void
    {
        $this->reconfigure(['constructor.api_token' => null]);
        Http::fake();

        try {
            $this->configuration()->redirectRules()->list();
            $this->fail('Expected a ConfigurationException.');
        } catch (ConfigurationException) {
            Http::assertNothingSent();
        }
    }
}
