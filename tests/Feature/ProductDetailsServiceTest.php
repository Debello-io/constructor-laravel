<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\Services\ProductDetailsService;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class ProductDetailsServiceTest extends TestCase
{
    private function details(): ProductDetailsService
    {
        return app(ProductDetailsService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $idField, string $id): array
    {
        return [
            $idField => $id,
            'enriched_attributes' => [
                'badges' => [
                    ['type' => 'is_global_bestseller', 'display_name' => 'Bestseller', 'metadata' => []],
                    ['type' => 'times_was_bought_last_week', 'display_name' => 'Bought 63 times last week', 'metadata' => ['times_was_bought_last_week' => '63']],
                ],
                'attributes' => [
                    'color' => [['type' => 'standard', 'value' => 'black'], ['type' => 'extended', 'value' => 'matte black']],
                    'fuel_type' => [['type' => 'standard', 'value' => 'pellet']],
                ],
            ],
        ];
    }

    public function test_items_are_requested_from_the_product_details_host_with_identity(): void
    {
        Http::fake(['product-details.cnstrc.com/*' => Http::response([
            'trace_id' => 't-1',
            'response' => ['items' => [$this->entry('id', 'TRG-780'), $this->entry('id', 'a/b')]],
        ])]);
        $this->actAsShopperRequest(['ConstructorioID_client_id' => 'client-uuid', 'ConstructorioID_session_id' => '3']);

        $details = $this->details()->getItems(['TRG-780', 'a/b'], ['section' => 'Products']);

        $this->assertSame(['TRG-780', 'a/b'], array_column($details, 'id'));
        $this->assertSame(['black', 'matte black'], $details[0]['attribute_values']['color']);
        $this->assertSame('extended', $details[0]['attributes']['color'][1]['type']);
        $this->assertSame('63', $details[0]['badges'][1]['metadata']['times_was_bought_last_week']);
        $this->assertNull($details[0]['variation_id']);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return str_starts_with($request->url(), 'https://product-details.cnstrc.com/v1/items?')
                && $query['item_id'] === ['TRG-780', 'a/b']
                && $query['fields'] === ['enriched_attributes']
                && $query['section'] === ['Products']
                && $query['key'] === ['key_test']
                && $query['i'] === ['client-uuid']
                && $query['s'] === ['3']
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_get_item_returns_one_entry(): void
    {
        Http::fake(['product-details.cnstrc.com/*' => Http::response(['response' => ['items' => [$this->entry('id', 'TRG-780')]]])]);

        $item = $this->details()->getItem('TRG-780');

        $this->assertNotNull($item);
        $this->assertSame('TRG-780', $item['id']);
        Http::assertSent(fn (Request $request) => $this->queryOf($request)['section'] === ['Products']);
    }

    public function test_variations_use_their_own_endpoint_and_id_field(): void
    {
        Http::fake(['product-details.cnstrc.com/*' => Http::response(['response' => ['variations' => [$this->entry('variation_id', 'TRG-780-BLK')]]])]);

        $details = $this->details()->getVariations('TRG-780-BLK');

        $this->assertSame('TRG-780-BLK', $details[0]['id']);
        $this->assertSame('TRG-780-BLK', $details[0]['variation_id']);
        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/v1/variations'
            && $this->queryOf($request)['variation_id'] === ['TRG-780-BLK']);
    }

    public function test_more_ids_than_constructor_accepts_throw_before_any_request(): void
    {
        Http::fake();

        try {
            $this->details()->getItems(array_map(fn (int $i) => "SKU-{$i}", range(1, 21)));
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('at most 20', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_no_ids_means_no_request(): void
    {
        Http::fake();

        $this->assertSame([], $this->details()->getItems(['', ' ']));
        Http::assertNothingSent();
    }

    public function test_failures_return_nothing_by_default_and_throw_when_configured(): void
    {
        Http::fake(['product-details.cnstrc.com/*' => Http::response(['message' => 'boom'], 500)]);
        $this->spyOnLog();

        $this->assertSame([], $this->details()->getItems('TRG-780'));
        $this->assertNull($this->details()->getItem('TRG-780'));

        $this->reconfigure(['constructor.throw_exceptions' => true]);
        $this->expectException(ApiException::class);
        $this->details()->getItems('TRG-780');
    }

    public function test_malformed_entries_are_skipped(): void
    {
        Http::fake(['product-details.cnstrc.com/*' => Http::response(['response' => ['items' => [
            null,
            ['enriched_attributes' => []],
            ['id' => 'TRG-780', 'enriched_attributes' => ['badges' => [['display_name' => 'no type']], 'attributes' => ['color' => ['oops']]]],
        ]]])]);

        $details = $this->details()->getItems('TRG-780');

        $this->assertCount(1, $details);
        $this->assertSame([], $details[0]['badges']);
        $this->assertSame(['color' => []], $details[0]['attribute_values']);
    }

    public function test_the_facade_exposes_the_service(): void
    {
        $this->assertSame($this->details(), Constructor::productDetails());
    }
}
