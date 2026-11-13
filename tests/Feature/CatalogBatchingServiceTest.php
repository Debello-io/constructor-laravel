<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\InvalidCatalogDataException;
use ConstructorIO\Laravel\Exceptions\RateLimitException;
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\Services\CatalogBatchingService;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class CatalogBatchingServiceTest extends TestCase
{
    private function batching(): CatalogBatchingService
    {
        return $this->app->make(CatalogBatchingService::class);
    }

    public function test_patch_items_goes_to_the_batching_host_with_basic_auth(): void
    {
        Http::fake(['batching.catalog.cnstrc.com/*' => Http::response(['message' => 'Accepted', 'count' => 2], 202)]);

        $result = Constructor::catalogBatching()->patchItems([
            ['id' => 'TRG-780', 'name' => 'Pro 780', 'price' => 899],
            ['id' => 'WEB-E325', 'name' => 'Spirit E-325', 'data' => ['in_stock' => false]],
        ]);

        $this->assertSame(2, $result['accepted']);
        $this->assertSame([['message' => 'Accepted', 'count' => 2]], $result['responses']);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame('PATCH', $request->method());
            $this->assertStringStartsWith('https://batching.catalog.cnstrc.com/v2/items?', $request->url());
            $this->assertSame(['Basic '.base64_encode('tok_secret_api_token:')], $request->header('Authorization'));
            $this->assertSame([], $request->header('x-cnstrc-token'));
            $this->assertSame(['key_test'], $query['key']);
            // The service requires a section, so the default is always sent.
            $this->assertSame(['Products'], $query['section']);
            $this->assertSame([
                ['id' => 'TRG-780', 'name' => 'Pro 780', 'data' => ['price' => 899]],
                ['id' => 'WEB-E325', 'name' => 'Spirit E-325', 'data' => ['in_stock' => false]],
            ], $request->data()['items']);

            return true;
        });
    }

    public function test_patch_items_requires_a_name_before_sending_anything(): void
    {
        Http::fake();

        try {
            $this->batching()->patchItems([['id' => 'TRG-780', 'price' => 899]]);
            $this->fail('Expected InvalidCatalogDataException');
        } catch (InvalidCatalogDataException $e) {
            $this->assertStringContainsString('needs a non-empty "name"', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_records_are_sent_in_requests_of_at_most_100(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'Accepted', 'count' => 100], 202)
            ->push(['message' => 'Accepted', 'count' => 100], 202)
            ->push(['message' => 'Accepted', 'count' => 50], 202)]);

        $ids = array_map(fn (int $n) => "SKU-{$n}", range(1, 250));

        $result = $this->batching()->deleteItems($ids, 'Products');

        $this->assertSame(250, $result['accepted']);
        Http::assertSentCount(3);

        $sizes = Http::recorded()->map(fn (array $pair) => count($pair[0]->data()['items']))->all();
        $this->assertSame([100, 100, 50], $sizes);

        [$first] = Http::recorded()[0];
        $this->assertSame('DELETE', $first->method());
        $this->assertSame(['id' => 'SKU-1'], $first->data()['items'][0]);
    }

    public function test_patch_and_delete_variations(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Accepted', 'count' => 1], 202)]);

        $this->batching()->patchVariations([['id' => 'TRG-780-LP', 'item_id' => 'TRG-780', 'in_stock' => true]], 'Variations Section');
        $this->batching()->deleteVariations(['TRG-780-NG']);

        [$patch] = Http::recorded()[0];
        [$delete] = Http::recorded()[1];

        $this->assertSame('PATCH', $patch->method());
        $this->assertSame('/v2/variations', $this->pathOf($patch));
        $this->assertSame(['Variations Section'], $this->queryOf($patch)['section']);
        $this->assertSame([['id' => 'TRG-780-LP', 'item_id' => 'TRG-780', 'data' => ['in_stock' => true]]], $patch->data()['variations']);

        $this->assertSame('DELETE', $delete->method());
        $this->assertSame([['id' => 'TRG-780-NG']], $delete->data()['variations']);
    }

    public function test_patch_variations_requires_an_item_id(): void
    {
        Http::fake();

        $this->expectException(InvalidCatalogDataException::class);
        $this->expectExceptionMessage('needs a non-empty "item_id"');

        $this->batching()->patchVariations([['id' => 'TRG-780-LP', 'in_stock' => true]]);
    }

    public function test_an_empty_list_sends_nothing(): void
    {
        Http::fake();

        $this->assertSame(['accepted' => 0, 'responses' => []], $this->batching()->deleteItems([]));
        Http::assertNothingSent();
    }

    public function test_rate_limits_are_typed_and_not_retried(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Too many requests'], 429)]);

        $this->expectException(RateLimitException::class);

        try {
            $this->batching()->deleteItems(['TRG-780']);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_validation_errors_keep_the_body_out_of_the_message(): void
    {
        Http::fake(['*' => Http::response(['detail' => [['loc' => ['body', 'items'], 'msg' => 'too long']]], 422)]);

        try {
            $this->batching()->deleteItems(['TRG-780']);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringNotContainsString('too long', $e->getMessage());
            $this->assertStringContainsString('too long', (string) $e->getResponseBody());
        }
    }

    public function test_the_host_is_configurable_and_must_be_https(): void
    {
        $this->reconfigure(['constructor.catalog_batching_base_url' => 'https://batching.example.test/']);
        Http::fake(['*' => Http::response(['message' => 'Accepted', 'count' => 1], 202)]);

        $this->batching()->deleteItems(['TRG-780']);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://batching.example.test/v2/items?'));

        $this->reconfigure(['constructor.catalog_batching_base_url' => 'http://batching.example.test']);

        $this->expectException(ConfigurationException::class);
        $this->batching();
    }
}
