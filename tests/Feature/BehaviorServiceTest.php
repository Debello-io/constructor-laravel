<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\Services\BehaviorService;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class BehaviorServiceTest extends TestCase
{
    private function behavior(): BehaviorService
    {
        return $this->app->make(BehaviorService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function purchase(string $orderId = 'POS-1'): array
    {
        return [
            'order_id' => $orderId,
            'occurred_at' => Carbon::parse('2026-07-04 12:30:00', 'UTC'),
            'user_id' => 42,
            'store_id' => 'austin-tx',
            'items' => [['item_id' => 'TRG-780', 'price' => 999.99, 'count' => 1]],
        ];
    }

    public function test_purchases_are_posted_with_basic_auth_and_the_documented_body(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(null, 204)]);
        $this->actAsShopperRequest(['ConstructorioID_client_id' => 'client-uuid']);

        $sent = $this->behavior()->recordOfflinePurchases([$this->purchase()]);

        $this->assertSame(1, $sent);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame('POST', $request->method());
            $this->assertSame('/v1/offline_behavioral_actions', $this->pathOf($request));
            $this->assertSame(['Basic '.base64_encode('tok_secret_api_token:')], $request->header('Authorization'));
            $this->assertSame([], $request->header('x-cnstrc-token'), 'The backend token is for storefront requests only');
            $this->assertSame(['key_test'], $query['key']);
            $this->assertSame(['Products'], $query['section']);
            $this->assertArrayNotHasKey('i', $query, 'Offline actions are not tied to the current web request');

            $this->assertSame(['behavioral_actions' => [[
                'action' => 'purchase',
                'data' => [
                    'order_id' => 'POS-1',
                    'store_id' => 'austin-tx',
                    'items' => [['item_id' => 'TRG-780', 'price' => 999.99, 'count' => 1]],
                    '_dt' => 1783168200,
                    'ui' => '42',
                ],
            ]]], $request->data());

            return true;
        });
    }

    public function test_returns_validate_and_send_their_item_lists(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(null, 204)]);

        Constructor::behavior()->recordOfflineReturns([[
            'order_id' => 'POS-1',
            '_dt' => 1783168200,
            'items_returned' => [['item_name' => 'Grill cover', 'price' => 49]],
            'items_kept' => [['item_id' => 'TRG-780']],
            'return_type' => 'partial',
            'return_preference' => 'refund',
        ]], 'Accessories');

        Http::assertSent(function (Request $request) {
            $action = $request->data()['behavioral_actions'][0];

            return $this->queryOf($request)['section'] === ['Accessories']
                && $action['action'] === 'return'
                && $action['data']['_dt'] === 1783168200
                && $action['data']['items_returned'] === [['item_name' => 'Grill cover', 'price' => 49]]
                && $action['data']['items_kept'] === [['item_id' => 'TRG-780']]
                && ! isset($action['data']['items_cross_sold']);
        });
    }

    public function test_actions_are_sent_in_batches_of_100(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(null, 204)]);

        $purchases = array_map(fn (int $n) => $this->purchase("POS-{$n}"), range(1, 250));

        $this->assertSame(250, $this->behavior()->recordOfflinePurchases($purchases));

        $batchSizes = [];
        Http::assertSent(function (Request $request) use (&$batchSizes) {
            $batchSizes[] = count($request->data()['behavioral_actions']);

            return true;
        });
        $this->assertSame([100, 100, 50], $batchSizes);
    }

    public function test_an_empty_list_sends_nothing(): void
    {
        Http::fake();

        $this->assertSame(0, $this->behavior()->recordOfflinePurchases([]));

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidActions(): array
    {
        return [
            'missing time' => [['action' => 'purchase', 'data' => ['items' => [['item_id' => 'A']]]], 'occurred_at'],
            'unreadable time' => [['action' => 'purchase', 'data' => ['occurred_at' => 'not a date', 'items' => [['item_id' => 'A']]]], 'unreadable'],
            'no items' => [['action' => 'purchase', 'data' => ['_dt' => 1, 'items' => []]], 'at least one item'],
            'item without id or name' => [['action' => 'purchase', 'data' => ['_dt' => 1, 'items' => [['price' => 5]]]], 'item_id or an item_name'],
            'return without returned items' => [['action' => 'return', 'data' => ['_dt' => 1]], 'items_returned'],
            'bad return type' => [['action' => 'return', 'data' => ['_dt' => 1, 'items_returned' => [['item_id' => 'A']], 'return_type' => 'some']], 'return_type'],
            'unknown action' => [['action' => 'click', 'data' => ['_dt' => 1]], '"purchase" or "return"'],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     */
    #[DataProvider('invalidActions')]
    public function test_invalid_actions_are_rejected_before_anything_is_sent(array $action, string $message): void
    {
        Http::fake();

        try {
            // A valid action first: nothing may be sent if any action is invalid.
            $this->behavior()->recordOfflineActions([['action' => 'purchase', 'data' => $this->purchase()], $action]);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_more_than_500_items_in_one_action_is_rejected(): void
    {
        $purchase = $this->purchase();
        $purchase['items'] = array_fill(0, 501, ['item_id' => 'A']);

        $this->expectException(InvalidArgumentException::class);

        $this->behavior()->recordOfflinePurchases([$purchase]);
    }

    public function test_failures_throw_and_are_not_retried(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['message' => 'invalid'], 400)]);

        try {
            $this->behavior()->recordOfflinePurchases([$this->purchase()]);
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringNotContainsString('invalid', $e->getMessage(), 'Response bodies stay out of messages');
        }

        Http::assertSentCount(1);
    }

    public function test_server_errors_are_not_retried_either(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response([], 503)]);

        try {
            $this->behavior()->recordOfflinePurchases([$this->purchase()]);
        } catch (ApiException) {
        }

        Http::assertSentCount(1);
    }

    public function test_the_api_token_is_required(): void
    {
        $this->reconfigure(['constructor.api_token' => null]);
        Http::fake();

        $this->expectException(ConfigurationException::class);

        $this->behavior()->recordOfflinePurchases([$this->purchase()]);
    }
}
