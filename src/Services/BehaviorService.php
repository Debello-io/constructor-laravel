<?php

namespace ConstructorIO\Laravel\Services;

use Carbon\CarbonImmutable;
use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConnectionException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use DateTimeInterface;
use Throwable;

/**
 * Offline behavioral actions: purchases and returns that happen outside the
 * website (in store, over the phone, through a sales rep), so Constructor
 * can learn from them as it does from beacon events.
 *
 *     Constructor::behavior()->recordOfflinePurchases([[
 *         'order_id' => 'POS-10293',
 *         'occurred_at' => $sale->completed_at,      // or `_dt` as Unix seconds
 *         'user_id' => $customer->id,                // the same `ui` your website sends
 *         'store_id' => 'austin-tx',
 *         'items' => [
 *             ['item_id' => 'TRG-780', 'price' => 999.99, 'count' => 1],
 *         ],
 *     ]]);
 *
 * Online behaviour (clicks, add to cart, website purchases) comes from the
 * Constructor beacon, not from this service.
 *
 * Like catalog management, this is a private endpoint authenticated with your
 * API token (CONSTRUCTOR_API_TOKEN). Run it from a queued job or a scheduled
 * command, never from shopper traffic. Writes throw on failure and are never
 * retried; actions are sent in batches of 100, and if a batch fails the
 * batches before it have already been accepted. Give every action an
 * `order_id`: Constructor merges events with the same order ID, so re-sending
 * a batch doesn't double-count it.
 *
 * @see https://docs.constructor.com/reference/v1-offline-behavioral-actions-create-actions
 */
class BehaviorService
{
    public const ACTION_PURCHASE = 'purchase';

    public const ACTION_RETURN = 'return';

    /** Constructor accepts at most this many actions per request. */
    public const MAX_ACTIONS_PER_REQUEST = 100;

    /** Constructor accepts at most this many items in one action's item list. */
    public const MAX_ITEMS_PER_ACTION = 500;

    /** Item lists on each action type, and whether the API requires them. */
    private const ITEM_LISTS = [
        self::ACTION_PURCHASE => ['items' => true],
        self::ACTION_RETURN => ['items_returned' => true, 'items_kept' => false, 'items_cross_sold' => false],
    ];

    /** Allowed values of the return action's enumerated fields. */
    private const RETURN_ENUMS = [
        'return_type' => ['full', 'partial'],
        'return_preference' => ['refund', 'replacement'],
    ];

    protected readonly ConstructorConfig $config;

    public function __construct(protected readonly ApiClient $api)
    {
        $this->config = $api->config();
    }

    /**
     * Record purchases made offline.
     *
     * Each purchase:
     *   occurred_at  DateTimeInterface|string|int  When it happened (required).
     *                Integers are Unix seconds. You can pass `_dt` instead.
     *   items        list   1-500 items: item_id or item_name (required),
     *                       variation_id, price, count
     *   order_id     string Recommended. Events with the same order ID are merged.
     *   user_id      string The signed-in user ID your website sends as `ui`
     *                       (hashed the same way if you use resolveUserIdUsing()).
     *                       You can pass `ui` instead.
     *   store_id, lat, lon, revenue
     *
     * @param  list<array<string, mixed>>  $purchases
     * @param  string|null  $section  Index section, default from config.
     * @return int The number of purchases sent.
     *
     * @throws InvalidArgumentException If a purchase is missing required fields.
     * @throws ApiException|ConnectionException
     */
    public function recordOfflinePurchases(array $purchases, ?string $section = null): int
    {
        return $this->recordOfflineActions(array_map(
            fn (mixed $data) => ['action' => self::ACTION_PURCHASE, 'data' => $data],
            $purchases,
        ), $section);
    }

    /**
     * Record returns made offline.
     *
     * Each return:
     *   occurred_at     When it happened (required). Or `_dt`.
     *   items_returned  list   1-500 items that came back (required)
     *   items_kept      list   Items from the same order the shopper kept
     *   items_cross_sold list  Items sold instead of the returned ones
     *   order_id, user_id (or `ui`), revenue, return_expenses, refund,
     *   return_shipping_cost, return_reason,
     *   return_type        "full" or "partial"
     *   return_preference  "refund" or "replacement"
     *   return_drop_off, lat, lon
     *
     * @param  list<array<string, mixed>>  $returns
     * @return int The number of returns sent.
     *
     * @throws InvalidArgumentException|ApiException|ConnectionException
     */
    public function recordOfflineReturns(array $returns, ?string $section = null): int
    {
        return $this->recordOfflineActions(array_map(
            fn (mixed $data) => ['action' => self::ACTION_RETURN, 'data' => $data],
            $returns,
        ), $section);
    }

    /**
     * Record a mix of offline actions, each as ['action' => 'purchase'|'return', 'data' => [...]].
     *
     * Every action is checked before anything is sent, then the actions go
     * out in batches of 100.
     *
     * @param  list<array{action: string, data: array<string, mixed>}>  $actions
     * @return int The number of actions sent.
     *
     * @throws InvalidArgumentException|ApiException|ConnectionException
     */
    public function recordOfflineActions(array $actions, ?string $section = null): int
    {
        $normalized = [];
        foreach ($actions as $action) {
            $normalized[] = $this->normalizeAction($action, count($normalized));
        }

        foreach (array_chunk($normalized, self::MAX_ACTIONS_PER_REQUEST) as $batch) {
            $this->api->catalog(
                'POST',
                '/v1/offline_behavioral_actions',
                ['section' => $section ?? $this->config->defaultSection],
                ['behavioral_actions' => $batch],
            );
        }

        return count($normalized);
    }

    /**
     * Check one action against the documented schema and apply the friendly
     * field names (`occurred_at`, `user_id`).
     *
     * @return array{action: string, data: array<string, mixed>}
     *
     * @throws InvalidArgumentException
     */
    protected function normalizeAction(mixed $action, int $position): array
    {
        $type = is_array($action) ? ($action['action'] ?? null) : null;
        $data = is_array($action) ? ($action['data'] ?? null) : null;

        if (! is_string($type) || ! isset(self::ITEM_LISTS[$type])) {
            throw new InvalidArgumentException("Offline action #{$position} needs an \"action\" of \"purchase\" or \"return\".");
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException("Offline {$type} #{$position} needs a \"data\" array.");
        }

        $data['_dt'] = $this->timestamp($data['_dt'] ?? $data['occurred_at'] ?? null, $type, $position);
        unset($data['occurred_at']);

        if (array_key_exists('user_id', $data)) {
            $data['ui'] ??= $data['user_id'];
            unset($data['user_id']);
        }
        if (isset($data['ui'])) {
            $data['ui'] = (string) $data['ui'];
        }

        foreach (['order_id', 'store_id', 'lat', 'lon'] as $stringField) {
            if (isset($data[$stringField])) {
                $data[$stringField] = (string) $data[$stringField];
            }
        }

        foreach (self::ITEM_LISTS[$type] as $listName => $required) {
            if (! $required && ! isset($data[$listName])) {
                continue;
            }

            $data[$listName] = $this->items($data[$listName] ?? null, "Offline {$type} #{$position} {$listName}");
        }

        if ($type === self::ACTION_RETURN) {
            foreach (self::RETURN_ENUMS as $field => $allowed) {
                if (isset($data[$field]) && ! in_array($data[$field], $allowed, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Offline return #%d has an invalid %s; use "%s".',
                        $position,
                        $field,
                        implode('" or "', $allowed),
                    ));
                }
            }
        }

        return ['action' => $type, 'data' => $data];
    }

    /**
     * Validate an item list: 1-500 items, each with an item_id or item_name.
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    protected function items(mixed $items, string $label): array
    {
        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException("{$label} needs at least one item.");
        }

        if (count($items) > self::MAX_ITEMS_PER_ACTION) {
            throw new InvalidArgumentException(sprintf(
                '%s has %d items; Constructor accepts at most %d per action.',
                $label,
                count($items),
                self::MAX_ITEMS_PER_ACTION,
            ));
        }

        $normalized = [];
        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException("{$label} must be a list of item arrays.");
            }

            foreach (['item_id', 'item_name', 'variation_id'] as $field) {
                if (isset($item[$field])) {
                    $item[$field] = (string) $item[$field];
                }
            }

            if (($item['item_id'] ?? '') === '' && ($item['item_name'] ?? '') === '') {
                throw new InvalidArgumentException("Every item in {$label} needs an item_id or an item_name.");
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Unix seconds, from a date object, a parseable date string or an integer.
     *
     * @throws InvalidArgumentException
     */
    private function timestamp(mixed $value, string $type, int $position): int
    {
        try {
            return match (true) {
                $value instanceof DateTimeInterface => $value->getTimestamp(),
                is_int($value) || (is_string($value) && ctype_digit($value)) => (int) $value,
                is_string($value) && $value !== '' => CarbonImmutable::parse($value)->getTimestamp(),
                default => throw new InvalidArgumentException(
                    "Offline {$type} #{$position} needs \"occurred_at\" (or \"_dt\"): when it happened."
                ),
            };
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            throw new InvalidArgumentException("Offline {$type} #{$position} has an unreadable \"occurred_at\" date.");
        }
    }
}
