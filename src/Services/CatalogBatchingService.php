<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConnectionException;
use ConstructorIO\Laravel\Exceptions\InvalidCatalogDataException;
use ConstructorIO\Laravel\Exceptions\RateLimitException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Support\CatalogRecords;
use ConstructorIO\Laravel\Support\ConstructorConfig;

/**
 * Constructor's Catalog Batching Service: frequent, small item and variation
 * updates, such as price or stock changes.
 *
 * Unlike CatalogService's /v2 writes, these requests don't return a task to
 * poll. Constructor answers 202 Accepted with the number of records it
 * accepted, and processes them asynchronously. Use this for a stream of
 * changes from an ERP, an inventory system or a queue listener; use
 * CatalogService for bulk loads whose outcome you want to track.
 *
 *     $batching = Constructor::catalogBatching();
 *
 *     $batching->patchItems([['id' => 'GRILL-123', 'name' => 'Pro 780', 'price' => 899]]);
 *     $batching->patchVariations([['id' => 'GRILL-123-LP', 'item_id' => 'GRILL-123', 'in_stock' => false]]);
 *     $batching->deleteItems(['DISCONTINUED-1']);
 *
 * Requests go to CONSTRUCTOR_CATALOG_BATCHING_BASE_URL and are authenticated
 * with the API token, like every catalog call. They are never retried
 * automatically; a RateLimitException (429) means: slow down and send again.
 *
 * @see https://docs.constructor.com/reference/v2-batching-items-update-items
 */
class CatalogBatchingService
{
    /** The service accepts 1 to 100 records per request. */
    public const MAX_RECORDS_PER_REQUEST = 100;

    protected readonly ConstructorConfig $config;

    public function __construct(protected readonly ApiClient $api)
    {
        $this->config = $api->config();
    }

    /**
     * Update items, creating any that don't exist yet.
     *
     * Every item needs an `id` and a `name`, even for a price-only change:
     * the service requires both on every record. Any other fields are moved
     * into `data`. Lists longer than 100 are sent in several requests.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{accepted: int, responses: list<array<string, mixed>>}
     *
     * @throws InvalidCatalogDataException If an item has no id or name (nothing is sent).
     * @throws RateLimitException|ApiException|ConnectionException
     *
     * @see https://docs.constructor.com/reference/v2-batching-items-update-items
     */
    public function patchItems(array $items, ?string $section = null): array
    {
        $items = array_map(fn (array $item) => CatalogRecords::item($item), $items);

        return $this->send('PATCH', 'items', $items, $section);
    }

    /**
     * Delete items by ID. Deletions are processed asynchronously.
     *
     * @param  list<string|int>  $itemIds
     * @return array{accepted: int, responses: list<array<string, mixed>>}
     *
     * @throws RateLimitException|ApiException|ConnectionException
     *
     * @see https://docs.constructor.com/reference/v2-batching-items-delete-items
     */
    public function deleteItems(array $itemIds, ?string $section = null): array
    {
        return $this->send('DELETE', 'items', CatalogRecords::ids($itemIds), $section);
    }

    /**
     * Update variations, creating any that don't exist yet.
     *
     * Every variation needs an `id` and the `item_id` of its parent item;
     * `name` is optional. Any other fields are moved into `data`.
     *
     * @param  list<array<string, mixed>>  $variations
     * @return array{accepted: int, responses: list<array<string, mixed>>}
     *
     * @throws InvalidCatalogDataException If a variation has no id or item_id (nothing is sent).
     * @throws RateLimitException|ApiException|ConnectionException
     *
     * @see https://docs.constructor.com/reference/v2-batching-variations-update-variations
     */
    public function patchVariations(array $variations, ?string $section = null): array
    {
        $variations = array_map(fn (array $variation) => CatalogRecords::variation($variation), $variations);

        return $this->send('PATCH', 'variations', $variations, $section);
    }

    /**
     * Delete variations by ID. Their parent items stay.
     *
     * @param  list<string|int>  $variationIds
     * @return array{accepted: int, responses: list<array<string, mixed>>}
     *
     * @throws RateLimitException|ApiException|ConnectionException
     *
     * @see https://docs.constructor.com/reference/v2-batching-variations-delete-variations
     */
    public function deleteVariations(array $variationIds, ?string $section = null): array
    {
        return $this->send('DELETE', 'variations', CatalogRecords::ids($variationIds), $section);
    }

    /**
     * Send records in requests of at most 100.
     *
     * The service's only `on_missing` strategy is CREATE, which is also its
     * default, so it isn't sent. If a request fails, its exception is thrown
     * and later requests aren't sent; earlier ones have already been accepted.
     *
     * @param  'items'|'variations'  $resource  Both the path segment and the request body key.
     * @param  list<array<string, mixed>>  $records
     * @return array{accepted: int, responses: list<array<string, mixed>>}
     */
    protected function send(string $method, string $resource, array $records, ?string $section): array
    {
        $accepted = 0;
        $responses = [];

        foreach (array_chunk($records, self::MAX_RECORDS_PER_REQUEST) as $batch) {
            $response = $this->api->catalog(
                $method,
                '/v2/'.$resource,
                // `section` is required by this service, so it is always sent.
                ['section' => $section ?? $this->config->defaultSection],
                [$resource => $batch],
                $this->config->catalogBatchingBaseUrl,
            );

            $accepted += (int) ($response['count'] ?? count($batch));
            $responses[] = $response;
        }

        return ['accepted' => $accepted, 'responses' => $responses];
    }
}
