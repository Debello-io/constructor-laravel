<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\AuthenticationException;
use ConstructorIO\Laravel\Exceptions\ConnectionException;
use ConstructorIO\Laravel\Exceptions\InvalidCatalogDataException;
use ConstructorIO\Laravel\Exceptions\TaskTimeoutException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Http\Path;
use ConstructorIO\Laravel\Support\CatalogRecords;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Catalog management: file uploads, items, variations, item groups
 * (categories) and background tasks.
 *
 * Every call here is a private endpoint, authenticated with your API token
 * (CONSTRUCTOR_API_TOKEN) as the HTTP Basic username and an empty password.
 * Never call these from code that handles shopper traffic.
 *
 * Writes are processed asynchronously. They return a task ID, which you can
 * poll with getTaskStatus() or waitForTaskCompletion().
 *
 *     $catalog = Constructor::catalog();
 *
 *     $upload = $catalog->uploadCatalog(storage_path('catalog/items.jsonl'));
 *     $task = $catalog->waitForTaskCompletion($upload['task_id']);
 *
 *     $catalog->patchItems([['id' => 'GRILL-123', 'data' => ['price' => 899.00]]]);
 *     $catalog->upsertVariations([['id' => 'GRILL-123-BLK', 'item_id' => 'GRILL-123', 'color' => 'Black']]);
 *     $catalog->upsertItemGroups([['id' => 'pellet-grills', 'name' => 'Pellet Grills', 'parent_ids' => ['grills']]]);
 *
 * For frequent small updates (price, stock) without a task per change, see
 * CatalogBatchingService.
 *
 * @see https://docs.constructor.com/reference/main-authentication
 * @see https://docs.constructor.com/reference/v1-catalog-create-or-replace-catalog
 */
class CatalogService
{
    /** Replace the whole catalog (PUT /v1/catalog). */
    public const OPERATION_CREATE_OR_REPLACE = 'create_or_replace';

    /** Replace only the records in the uploaded files (PATCH /v1/catalog). */
    public const OPERATION_PATCH = 'patch';

    /**
     * Constructor accepts at most this many records per /v2/items,
     * /v2/variations or /v2/item_groups request.
     */
    public const MAX_ITEMS_PER_REQUEST = 10000;

    /** Largest page the /v2 list endpoints return. */
    private const MAX_LIST_PAGE_SIZE = 1000;

    /** Task statuses after which a task will not change again. */
    private const FINISHED_TASK_STATUSES = ['DONE', 'FAILED', 'CANCELED'];

    protected readonly ConstructorConfig $config;

    public function __construct(protected readonly ApiClient $api)
    {
        $this->config = $api->config();
    }

    /**
     * Check that the API key and API token are accepted.
     *
     * Constructor has no dedicated "verify" endpoint, so this makes the
     * cheapest documented authenticated call: list one item.
     *
     * @return bool True if the credentials work, false if Constructor rejects them (401/403).
     *
     * @throws ApiException For any other error status.
     * @throws ConnectionException If Constructor can't be reached.
     */
    public function verifyCredentials(): bool
    {
        try {
            $this->api->catalog('GET', '/v2/items', ['num_results_per_page' => 1]);

            return true;
        } catch (AuthenticationException) {
            return false;
        }
    }

    /**
     * Upload catalog files.
     *
     * 'create_or_replace' (PUT) replaces the catalog with the files' contents.
     * 'patch' (PATCH) replaces only the records in the files; add
     * 'patch_delta' => true to update just the fields you send.
     *
     *     $catalog->uploadCatalog(storage_path('catalog/items.csv'), 'create_or_replace', [
     *         'variations_file' => storage_path('catalog/variations.csv'),
     *         'item_groups_file' => storage_path('catalog/item_groups.csv'),
     *     ]);
     *
     * Options:
     *   variations_file     string  Path to a variations file
     *   item_groups_file    string  Path to an item groups (categories) file
     *   format              string  'csv' or 'jsonl'. Default: from the file extension
     *   section             string  Index section. Default from config
     *   force               bool    Allow uploads that would remove over 50% of the catalog
     *   notification_email  string|list  Who Constructor emails if the task fails
     *   mode                string  PUT only: 'full' (default) or 'ids' (files list IDs to keep)
     *   patch_delta         bool    PATCH only: update only the fields you send
     *   on_missing          string  PATCH with patch_delta only: 'FAIL' (default), 'IGNORE' or 'CREATE'
     *
     * Uploads are not retried automatically: a file stream can only be sent once.
     *
     * The result's task_id is null when Constructor answers 202 Accepted (the
     * upload was queued before a task was created); `message` then explains.
     *
     * @param  string|null  $itemsFile  Path to the items file. May be null if you only upload variations or item groups.
     * @param  array<string, mixed>  $options
     * @return array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}
     *
     * @throws InvalidCatalogDataException If no file is given, a file is unreadable, or the operation is unknown.
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v1-catalog-update-catalog
     */
    public function uploadCatalog(?string $itemsFile, string $operation = self::OPERATION_CREATE_OR_REPLACE, array $options = []): array
    {
        $method = match ($operation) {
            self::OPERATION_CREATE_OR_REPLACE => 'PUT',
            self::OPERATION_PATCH => 'PATCH',
            default => throw new InvalidCatalogDataException(
                "Unknown catalog upload operation '{$operation}'. Use 'create_or_replace' or 'patch'."
            ),
        };

        $files = array_filter([
            'items' => $itemsFile,
            'variations' => $options['variations_file'] ?? null,
            'item_groups' => $options['item_groups_file'] ?? null,
        ]);

        if ($files === []) {
            throw new InvalidCatalogDataException('Pass at least one catalog file: items, variations_file or item_groups_file.');
        }

        $url = $this->api->catalogUrl('/v1/catalog', $this->uploadParameters($method, $files, $options));

        $attachments = [];

        try {
            foreach ($files as $fieldName => $path) {
                $attachments[$fieldName] = [$this->openFile($path), basename($path)];
            }

            $response = $this->api->uploadToCatalog($method, $url, $attachments, $this->config->uploadTimeout);
        } finally {
            foreach ($attachments as [$handle]) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        return $this->normalizeTaskResponse($response);
    }

    /**
     * Create items, or replace existing items completely (PUT /v2/items).
     *
     * Each item needs an `id` and a `name`. Any other fields are moved into
     * `data` for you, so both of these work:
     *
     *     ['id' => 'GRILL-123', 'name' => 'Pellet Grill', 'data' => ['price' => 899, 'url' => '/p/grill-123']]
     *     ['id' => 'GRILL-123', 'name' => 'Pellet Grill', 'price' => 899, 'url' => '/p/grill-123']
     *
     * Large lists are split into batches (CONSTRUCTOR_CATALOG_BATCH_SIZE, at most 10,000).
     *
     * Options: force, notification_email.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $options
     * @return list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}> One entry per batch.
     *
     * @throws InvalidCatalogDataException|ApiException
     *
     * @see https://docs.constructor.com/reference/v2-items-create-or-replace-items
     */
    public function upsertItems(array $items, ?string $section = null, array $options = []): array
    {
        $items = array_map(fn (array $item) => $this->normalizeItem($item), $items);

        return $this->sendInBatches('PUT', $items, $this->writeParameters($section, $options));
    }

    /**
     * Update fields of existing items (PATCH /v2/items). Only `id` is
     * required; the data you send is merged into the existing item.
     *
     * Options: force, notification_email, on_missing ('FAIL' default, 'IGNORE', 'CREATE').
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $options
     * @return list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}>
     *
     * @throws InvalidCatalogDataException|ApiException
     *
     * @see https://docs.constructor.com/reference/v2-items-update-items
     */
    public function patchItems(array $items, ?string $section = null, array $options = []): array
    {
        $items = array_map(fn (array $item) => $this->normalizeItem($item, requireName: false), $items);

        $parameters = $this->writeParameters($section, $options);
        if (isset($options['on_missing'])) {
            $parameters['on_missing'] = strtoupper((string) $options['on_missing']);
        }

        return $this->sendInBatches('PATCH', $items, $parameters);
    }

    /**
     * Delete items by ID (DELETE /v2/items).
     *
     * Options: force, notification_email.
     *
     * @param  list<string|int>  $itemIds
     * @param  array<string, mixed>  $options
     * @return list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}>
     *
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v2-items-delete-items
     */
    public function deleteItems(array $itemIds, ?string $section = null, array $options = []): array
    {
        return $this->sendInBatches('DELETE', CatalogRecords::ids($itemIds), $this->writeParameters($section, $options));
    }

    /**
     * One item as stored in the catalog, or null if it doesn't exist.
     *
     * @return array<string, mixed>|null
     *
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v2-items-retrieve-item
     */
    public function getItem(string $itemId, ?string $section = null): ?array
    {
        return $this->findOrNull('/v2/items/'.Path::segment($itemId), [
            'section' => $section ?? $this->config->defaultSection,
        ]);
    }

    /**
     * Items as stored in the catalog, a page at a time.
     *
     * Options: ids (list, up to 1,000), page, per_page (1-1,000, default 100),
     * next_page_cursor (for walking very large catalogs; omits total_count).
     *
     * @param  array<string, mixed>  $options
     * @return array{items: list<array<string, mixed>>, total_count: int|null, next_page_cursor: string|null}
     *
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v2-items-retrieve-items
     */
    public function listItems(?string $section = null, array $options = []): array
    {
        $response = $this->api->catalog('GET', '/v2/items', $this->listParameters($options, [
            'section' => $section ?? $this->config->defaultSection,
        ]));

        return [
            'items' => $response['items'] ?? [],
            'total_count' => isset($response['total_count']) ? (int) $response['total_count'] : null,
            'next_page_cursor' => $response['next_page_cursor'] ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Variations
    |--------------------------------------------------------------------------
    |
    | Variations are the purchasable versions of an item (sizes, colors,
    | fuel types). Each one belongs to an item through `item_id`.
    |
    */

    /**
     * Create variations, or replace existing variations completely (PUT /v2/variations).
     *
     * Each variation needs an `id` and the `item_id` of its parent item;
     * `name` is optional. Any other fields are moved into `data`:
     *
     *     ['id' => 'GRILL-123-LP', 'item_id' => 'GRILL-123', 'fuel_type' => 'Propane', 'price' => 899]
     *
     * Large lists are split into batches (CONSTRUCTOR_CATALOG_BATCH_SIZE, at most 10,000).
     *
     * Options: force, notification_email.
     *
     * @param  list<array<string, mixed>>  $variations
     * @param  array<string, mixed>  $options
     * @return list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}> One entry per batch.
     *
     * @throws InvalidCatalogDataException|ApiException
     *
     * @see https://docs.constructor.com/reference/v2-variations-create-or-replace-variations
     */
    public function upsertVariations(array $variations, ?string $section = null, array $options = []): array
    {
        $variations = array_map(fn (array $variation) => CatalogRecords::variation($variation), $variations);

        return $this->sendInBatches('PUT', $variations, $this->writeParameters($section, $options), 'variations');
    }

    /**
     * Update fields of existing variations (PATCH /v2/variations). Only `id`
     * is required; the data you send is merged into the existing variation.
     *
     * Options: force, notification_email, on_missing ('FAIL' default, 'IGNORE', 'CREATE').
     * Variations created through 'CREATE' still need an `item_id`.
     *
     * @param  list<array<string, mixed>>  $variations
     * @param  array<string, mixed>  $options
     * @return list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}>
     *
     * @throws InvalidCatalogDataException|ApiException
     *
     * @see https://docs.constructor.com/reference/v2-variations-update-variations
     */
    public function patchVariations(array $variations, ?string $section = null, array $options = []): array
    {
        $variations = array_map(fn (array $variation) => CatalogRecords::variation($variation, requireItemId: false), $variations);

        $parameters = $this->writeParameters($section, $options);
        if (isset($options['on_missing'])) {
            $parameters['on_missing'] = strtoupper((string) $options['on_missing']);
        }

        return $this->sendInBatches('PATCH', $variations, $parameters, 'variations');
    }

    /**
     * Delete variations by ID (DELETE /v2/variations). Their parent items stay.
     *
     * Options: force, notification_email.
     *
     * @param  list<string|int>  $variationIds
     * @param  array<string, mixed>  $options
     * @return list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}>
     *
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v2-variations-delete-variations
     */
    public function deleteVariations(array $variationIds, ?string $section = null, array $options = []): array
    {
        return $this->sendInBatches('DELETE', CatalogRecords::ids($variationIds), $this->writeParameters($section, $options), 'variations');
    }

    /**
     * One variation as stored in the catalog, or null if it doesn't exist.
     *
     * @return array<string, mixed>|null
     *
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v2-variations-retrieve-variation
     */
    public function getVariation(string $variationId, ?string $section = null): ?array
    {
        return $this->findOrNull('/v2/variations/'.Path::segment($variationId), [
            'section' => $section ?? $this->config->defaultSection,
        ]);
    }

    /**
     * Variations as stored in the catalog, a page at a time.
     *
     * Options: ids (variation IDs, up to 1,000), item_ids (every variation of
     * these items, up to 1,000), page, per_page (1-1,000, default 100),
     * next_page_cursor (for walking very large catalogs; omits total_count).
     *
     *     $catalog->listVariations(null, ['item_ids' => ['GRILL-123']]);
     *
     * @param  array<string, mixed>  $options
     * @return array{variations: list<array<string, mixed>>, total_count: int|null, next_page_cursor: string|null}
     *
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v2-variations-retrieve-variations
     */
    public function listVariations(?string $section = null, array $options = []): array
    {
        $response = $this->api->catalog('GET', '/v2/variations', $this->listParameters($options, [
            'section' => $section ?? $this->config->defaultSection,
            'item_id' => isset($options['item_ids']) ? array_values(array_map('strval', (array) $options['item_ids'])) : null,
        ]));

        return [
            'variations' => $response['variations'] ?? [],
            'total_count' => isset($response['total_count']) ? (int) $response['total_count'] : null,
            'next_page_cursor' => $response['next_page_cursor'] ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Item groups (categories)
    |--------------------------------------------------------------------------
    |
    | Item groups form the category tree. Items join a group through
    | `data.group_ids`, and category pages browse by the group's ID:
    | Constructor::browse('group_id', 'pellet-grills'). Item groups belong to
    | the whole index, so these calls take no section.
    |
    */

    /**
     * Create item groups, or replace existing ones completely (PUT /v2/item_groups).
     *
     * Each group needs an `id` and a `name`. `parent_ids` places it in the
     * tree; any other fields are moved into `data` (at most 5 kB per group):
     *
     *     ['id' => 'pellet-grills', 'name' => 'Pellet Grills', 'parent_ids' => ['grills'], 'image_url' => '/img/pellet.jpg']
     *
     * Large lists are split into batches (CONSTRUCTOR_CATALOG_BATCH_SIZE, at most 10,000).
     *
     * Options: force, notification_email.
     *
     * @param  list<array<string, mixed>>  $itemGroups
     * @param  array<string, mixed>  $options
     * @return list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}> One entry per batch.
     *
     * @throws InvalidCatalogDataException|ApiException
     *
     * @see https://docs.constructor.com/reference/v2-item-groups-create-or-replace-item-groups
     */
    public function upsertItemGroups(array $itemGroups, array $options = []): array
    {
        $itemGroups = array_map(fn (array $group) => CatalogRecords::itemGroup($group), $itemGroups);

        return $this->sendInBatches('PUT', $itemGroups, $this->itemGroupWriteParameters($options), 'item_groups');
    }

    /**
     * Update fields of existing item groups (PATCH /v2/item_groups). Only
     * `id` is required; the data you send is merged into the existing group.
     *
     * Unlike items and variations, this can't create groups: Constructor
     * always fails the task if a group doesn't exist (it accepts no
     * `on_missing`). Use upsertItemGroups() to create them.
     *
     * Options: force, notification_email.
     *
     * @param  list<array<string, mixed>>  $itemGroups
     * @param  array<string, mixed>  $options
     * @return list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}>
     *
     * @throws InvalidCatalogDataException|ApiException
     *
     * @see https://docs.constructor.com/reference/v2-item-groups-update-item-groups
     */
    public function patchItemGroups(array $itemGroups, array $options = []): array
    {
        $itemGroups = array_map(fn (array $group) => CatalogRecords::itemGroup($group, requireName: false), $itemGroups);

        return $this->sendInBatches('PATCH', $itemGroups, $this->itemGroupWriteParameters($options), 'item_groups');
    }

    /**
     * Delete item groups by ID (DELETE /v2/item_groups).
     *
     * Options: force, notification_email.
     *
     * @param  list<string|int>  $itemGroupIds
     * @param  array<string, mixed>  $options
     * @return list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}>
     *
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v2-item-groups-delete-item-groups
     */
    public function deleteItemGroups(array $itemGroupIds, array $options = []): array
    {
        return $this->sendInBatches('DELETE', CatalogRecords::ids($itemGroupIds), $this->itemGroupWriteParameters($options), 'item_groups');
    }

    /**
     * One item group as stored in the catalog (`id`, `name`, `parent_ids`,
     * `data`), or null if it doesn't exist.
     *
     * @return array<string, mixed>|null
     *
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v2-item-groups-retrieve-item-group
     */
    public function getItemGroup(string $itemGroupId): ?array
    {
        return $this->findOrNull('/v2/item_groups/'.Path::segment($itemGroupId));
    }

    /**
     * Item groups as stored in the catalog, a page at a time.
     *
     * Options: ids (up to 1,000), page, per_page (1-1,000; Constructor's
     * default is 20). This endpoint has no cursor pagination.
     *
     * @param  array<string, mixed>  $options
     * @return array{item_groups: list<array<string, mixed>>, total_count: int|null}
     *
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v2-item-groups-retrieve-item-groups
     */
    public function listItemGroups(array $options = []): array
    {
        $response = $this->api->catalog('GET', '/v2/item_groups', $this->listParameters(Arr::except($options, 'next_page_cursor')));

        return [
            'item_groups' => $response['item_groups'] ?? [],
            'total_count' => isset($response['total_count']) ? (int) $response['total_count'] : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Background tasks
    |--------------------------------------------------------------------------
    */

    /**
     * Current state of a background task.
     *
     * Constructor's statuses are QUEUED, IN_PROGRESS, DONE, FAILED and
     * CANCELED. For convenience this adds:
     *   completed  true once the task will not change again (DONE, FAILED, CANCELED)
     *   successful true only for DONE
     *   error      the failure details, if any
     *   warnings   ingestion warnings (e.g. a missing field), if any
     *   indexed    whether the search index has been rebuilt with the change
     *              (null if Constructor didn't say). A DONE task may not be
     *              visible in search results until this is true.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidCatalogDataException If the task ID is not a positive integer.
     * @throws ApiException
     *
     * @see https://docs.constructor.com/reference/v1-tasks-retrieve-task
     */
    public function getTaskStatus(int|string $taskId): array
    {
        $response = $this->api->catalog('GET', '/v1/tasks/'.$this->validTaskId($taskId));
        $status = (string) ($response['status'] ?? '');

        return array_merge($response, [
            'completed' => in_array($status, self::FINISHED_TASK_STATUSES, true),
            'successful' => $status === 'DONE',
            'error' => $response['error'] ?? null,
            'warnings' => $response['result']['ingestion_warnings'] ?? [],
            'indexed' => isset($response['result']['index_built']) ? (bool) $response['result']['index_built'] : null,
        ]);
    }

    /**
     * Poll a task until it finishes, then return its final status.
     *
     * Blocks the current process, so call it from a queued job or an Artisan
     * command, never during a web request.
     *
     * Note: in 1.x the second and third arguments were "max attempts" and
     * "delay seconds". They are now a timeout and a poll interval, both in
     * seconds, defaulting to CONSTRUCTOR_TASK_TIMEOUT and
     * CONSTRUCTOR_TASK_POLL_INTERVAL.
     *
     * @return array<string, mixed> Same shape as getTaskStatus(). Check `successful`.
     *
     * @throws TaskTimeoutException If the task is still running when the timeout is reached.
     * @throws ApiException
     */
    public function waitForTaskCompletion(int|string $taskId, ?int $timeoutSeconds = null, ?int $pollIntervalSeconds = null): array
    {
        $timeoutSeconds ??= $this->config->taskTimeout;
        $pollIntervalSeconds = max(1, $pollIntervalSeconds ?? $this->config->taskPollInterval);
        $deadline = Carbon::now()->addSeconds($timeoutSeconds);

        while (true) {
            $status = $this->getTaskStatus($taskId);

            if ($status['completed']) {
                return $status;
            }

            $secondsLeft = (int) ceil(Carbon::now()->diffInSeconds($deadline, absolute: false));

            if ($secondsLeft <= 0) {
                $state = $status['status'] ?? 'unknown';

                throw new TaskTimeoutException("Constructor task {$taskId} was still {$state} after {$timeoutSeconds} seconds.");
            }

            // Never sleep past the deadline, so the last check happens at the deadline.
            Sleep::for(min($pollIntervalSeconds, $secondsLeft))->seconds();
        }
    }

    /**
     * Records sent per /v2/items, /v2/variations or /v2/item_groups request
     * (CONSTRUCTOR_CATALOG_BATCH_SIZE, kept between 1 and the API's limit of 10,000).
     *
     * @return int<1, 10000>
     */
    public function batchSize(): int
    {
        return min(self::MAX_ITEMS_PER_REQUEST, max(1, $this->config->catalogBatchSize));
    }

    /**
     * The section used when a call doesn't name one (CONSTRUCTOR_SECTION).
     */
    public function defaultSection(): string
    {
        return $this->config->defaultSection;
    }

    /**
     * Shape one item the way /v2/items expects: id and name at the top level,
     * every other field inside `data`.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     *
     * @throws InvalidCatalogDataException
     */
    public function normalizeItem(array $item, bool $requireName = true): array
    {
        return CatalogRecords::item($item, $requireName);
    }

    /**
     * Send records to /v2/{resource} in batches the API accepts. Each batch
     * starts its own task.
     *
     * If a batch fails, its exception is thrown and later batches aren't
     * sent; earlier batches have already been accepted.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, mixed>  $parameters
     * @param  'items'|'variations'|'item_groups'  $resource  Both the path segment and the request body key.
     * @return list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}>
     */
    protected function sendInBatches(string $method, array $records, array $parameters, string $resource = 'items'): array
    {
        $results = [];

        foreach (array_chunk($records, $this->batchSize()) as $batch) {
            $response = $this->api->catalog($method, '/v2/'.$resource, $parameters, [$resource => $batch]);
            $results[] = $this->normalizeTaskResponse($response);
        }

        return $results;
    }

    /**
     * Query parameters shared by item writes.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function writeParameters(?string $section, array $options): array
    {
        return [
            'section' => $section ?? $this->config->defaultSection,
            'force' => (bool) ($options['force'] ?? $this->config->forceUpload),
            'notification_email' => $this->notificationEmails($options),
        ];
    }

    /**
     * Item groups belong to the whole index, so their writes take no `section`.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function itemGroupWriteParameters(array $options): array
    {
        return Arr::except($this->writeParameters(null, $options), 'section');
    }

    /**
     * Query parameters shared by the /v2 list endpoints.
     *
     * @param  array<string, mixed>  $options  ids, page, per_page, next_page_cursor
     * @param  array<string, mixed>  $extra  Endpoint-specific parameters; nulls are dropped.
     * @return array<string, mixed>
     */
    protected function listParameters(array $options, array $extra = []): array
    {
        return array_filter(array_merge($extra, [
            'id' => isset($options['ids']) ? array_values(array_map('strval', (array) $options['ids'])) : null,
            'num_results_per_page' => isset($options['per_page'])
                ? min(self::MAX_LIST_PAGE_SIZE, max(1, (int) $options['per_page']))
                : null,
            'next_page_cursor' => $options['next_page_cursor'] ?? null,
            // page and cursor can't be combined.
            'page' => isset($options['next_page_cursor']) ? null : ($options['page'] ?? null),
        ]), fn ($value) => $value !== null);
    }

    /**
     * GET one catalog record, or null if Constructor answers 404.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     *
     * @throws ApiException For any other error status.
     */
    protected function findOrNull(string $path, array $query = []): ?array
    {
        try {
            return $this->api->catalog('GET', $path, $query);
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Query parameters for a catalog file upload.
     *
     * @param  array<string, string>  $files
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function uploadParameters(string $method, array $files, array $options): array
    {
        $parameters = $this->writeParameters($options['section'] ?? null, $options);
        $parameters['format'] = $options['format'] ?? $this->detectFormat($files);

        if ($method === 'PUT' && isset($options['mode'])) {
            $parameters['mode'] = $options['mode'];
        }

        if ($method === 'PATCH') {
            $patchDelta = (bool) ($options['patch_delta'] ?? false);
            $parameters['patch_delta'] = $patchDelta;

            // The API only accepts on_missing together with patch_delta.
            if ($patchDelta && isset($options['on_missing'])) {
                $parameters['on_missing'] = strtoupper((string) $options['on_missing']);
            }
        }

        return $parameters;
    }

    /**
     * Constructor defaults to CSV, so JSONL files must say so explicitly.
     *
     * @param  array<string, string>  $files
     */
    private function detectFormat(array $files): string
    {
        foreach ($files as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, ['jsonl', 'ndjson'], true)) {
                return 'jsonl';
            }
        }

        return 'csv';
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>|null
     */
    private function notificationEmails(array $options): ?array
    {
        $emails = $options['notification_email'] ?? $this->config->notificationEmail;

        if ($emails === null || $emails === '' || $emails === []) {
            return null;
        }

        // Config allows a comma-separated list in one .env value.
        $emails = is_array($emails) ? $emails : explode(',', (string) $emails);

        return array_values(array_filter(array_map('trim', $emails)));
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}
     */
    private function normalizeTaskResponse(array $response): array
    {
        return [
            'task_id' => isset($response['task_id']) ? (int) $response['task_id'] : null,
            'task_status_path' => $response['task_status_path'] ?? null,
            'message' => $response['message'] ?? null,
            'response' => $response,
        ];
    }

    /**
     * Task IDs are integers in the API. Validating also stops a crafted
     * value from changing the authenticated request's path.
     */
    private function validTaskId(int|string $taskId): int
    {
        if (is_int($taskId) ? $taskId > 0 : (ctype_digit($taskId) && (int) $taskId > 0)) {
            return (int) $taskId;
        }

        throw new InvalidCatalogDataException("Invalid Constructor task ID '{$taskId}'. Task IDs are positive integers.");
    }

    /**
     * @return resource
     *
     * @throws InvalidCatalogDataException
     */
    private function openFile(string $path)
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            throw new InvalidCatalogDataException("Catalog file not found or not readable: {$path}");
        }

        return $handle;
    }
}
