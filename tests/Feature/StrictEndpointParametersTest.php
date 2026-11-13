<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Some endpoints answer 400 for identity parameters their API reference
 * doesn't list (found against a live index, see ApiClient's
 * REJECTED_IDENTITY_PARAMETERS). Every other endpoint keeps the full set.
 */
class StrictEndpointParametersTest extends TestCase
{
    /** A 1×1 PNG, enough for the image search format check. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['response' => []])]);
        $this->actAsShopperRequest(['ConstructorioID_client_id' => 'client-1', 'ConstructorioID_session_id' => '3']);
    }

    private function search(): SearchService
    {
        return app(SearchService::class);
    }

    /**
     * @return array<string, list<string>>
     */
    private function sentQuery(string $path): array
    {
        foreach ($this->sentRequests() as $request) {
            if ($this->pathOf($request) === $path) {
                return $this->queryOf($request);
            }
        }

        $this->fail("No request was sent to {$path}.");
    }

    public function test_browse_metadata_endpoints_get_no_timestamp(): void
    {
        $this->search()->getBrowseGroups();
        $this->search()->getAvailableFacets();
        $this->search()->getFacetsWithOptions();
        $this->search()->getFacetValuesWithImages('brand', 0);
        $this->search()->getCollections();

        foreach (['/browse/groups', '/browse/facets', '/browse/facets_with_options', '/browse/facet_options', '/browse/collections'] as $path) {
            $query = $this->sentQuery($path);

            $this->assertArrayNotHasKey('_dt', $query, $path);
            // The rest of the identity is still sent.
            $this->assertSame(['client-1'], $query['i'] ?? null, $path);
            $this->assertSame(['3'], $query['s'] ?? null, $path);
            $this->assertArrayHasKey('origin_referrer', $query, $path);
        }
    }

    public function test_image_search_gets_no_timestamp_or_referrer(): void
    {
        $this->search()->searchByImage((string) base64_decode(self::PNG));

        $query = $this->sentQuery('/v2/image_search');
        $this->assertArrayNotHasKey('_dt', $query);
        $this->assertArrayNotHasKey('origin_referrer', $query);
        $this->assertSame(['client-1'], $query['i'] ?? null);
    }

    public function test_search_results_endpoints_keep_every_identity_parameter(): void
    {
        $this->search()->search('grill');
        $this->search()->browse('group_id', 'grills');

        foreach (['/v1/search/grill', '/browse/group_id/grills'] as $path) {
            $query = $this->sentQuery($path);

            $this->assertArrayHasKey('_dt', $query, $path);
            $this->assertArrayHasKey('origin_referrer', $query, $path);
        }
    }
}
