<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\AuthenticationException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\InvalidCatalogDataException;
use ConstructorIO\Laravel\Exceptions\TaskTimeoutException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Services\CatalogService;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class CatalogServiceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function catalog(): CatalogService
    {
        return $this->app->make(CatalogService::class);
    }

    private function temporaryFile(string $extension, string $contents): string
    {
        $path = sys_get_temp_dir().'/cio-test-'.uniqid().'.'.$extension;
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function assertBasicAuthWithApiToken(Request $request): void
    {
        $this->assertSame(['Basic '.base64_encode('tok_secret_api_token:')], $request->header('Authorization'));
        $this->assertStringNotContainsString('Bearer', implode(' ', $request->header('Authorization')));
        // The storefront security token never goes on catalog requests.
        $this->assertSame([], $request->header('x-cnstrc-token'));
    }

    public function test_upload_uses_basic_auth_with_the_api_token_and_detects_jsonl(): void
    {
        Http::fake(['*' => Http::response(['task_id' => 42, 'task_status_path' => '/v1/tasks/42'])]);

        $result = $this->catalog()->uploadCatalog($this->temporaryFile('jsonl', '{"id":"1","name":"Grill"}'));

        $this->assertSame(42, $result['task_id']);
        $this->assertSame('/v1/tasks/42', $result['task_status_path']);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame('PUT', $request->method());
            $this->assertSame('/v1/catalog', $this->pathOf($request));
            $this->assertBasicAuthWithApiToken($request);
            $this->assertSame(['key_test'], $query['key']);
            $this->assertSame(['jsonl'], $query['format']);
            $this->assertSame(['Products'], $query['section']);
            $this->assertSame(['false'], $query['force']);
            $this->assertSame(['cio-be-web-bbq-test-store'], $query['c']);
            $this->assertTrue($request->isMultipart());
            $this->assertSame(['items'], array_column($request->data(), 'name'));

            return true;
        });
    }

    public function test_upload_sends_variations_and_item_groups_files_and_csv_format(): void
    {
        Http::fake(['*' => Http::response(['task_id' => 7, 'task_status_path' => '/v1/tasks/7'])]);

        $this->catalog()->uploadCatalog($this->temporaryFile('csv', "id,name\n1,Grill"), 'create_or_replace', [
            'variations_file' => $this->temporaryFile('csv', "id,item_id\nv1,1"),
            'item_groups_file' => $this->temporaryFile('csv', "id,name\ngrills,Grills"),
            'mode' => 'full',
            'notification_email' => 'ops@example.com, catalog@example.com',
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame(['items', 'variations', 'item_groups'], array_column($request->data(), 'name'));
            $this->assertSame(['csv'], $query['format']);
            $this->assertSame(['full'], $query['mode']);
            $this->assertSame(['ops@example.com', 'catalog@example.com'], $query['notification_email']);

            return true;
        });
    }

    public function test_patch_upload_sends_patch_delta_and_on_missing(): void
    {
        Http::fake(['*' => Http::response(['task_id' => 9, 'task_status_path' => '/v1/tasks/9'])]);

        $this->catalog()->uploadCatalog($this->temporaryFile('csv', "id,price\n1,10"), 'patch', [
            'patch_delta' => true,
            'on_missing' => 'ignore',
            'mode' => 'ids', // PUT only: must not be sent with PATCH
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame('PATCH', $request->method());
            $this->assertSame(['true'], $query['patch_delta']);
            $this->assertSame(['IGNORE'], $query['on_missing']);
            $this->assertArrayNotHasKey('mode', $query);

            return true;
        });
    }

    public function test_upload_accepted_without_a_task_id_returns_null_task_id(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Upload queued'], 202)]);

        $result = $this->catalog()->uploadCatalog($this->temporaryFile('csv', 'id,name'));

        $this->assertNull($result['task_id']);
        $this->assertSame('Upload queued', $result['message']);
    }

    public function test_upload_validates_files_and_operation(): void
    {
        Http::fake();

        try {
            $this->catalog()->uploadCatalog(null);
            $this->fail('Expected an exception for no files.');
        } catch (InvalidCatalogDataException $e) {
            $this->assertStringContainsString('at least one catalog file', $e->getMessage());
        }

        try {
            $this->catalog()->uploadCatalog('/does/not/exist.csv');
            $this->fail('Expected an exception for a missing file.');
        } catch (InvalidCatalogDataException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        $this->expectException(InvalidCatalogDataException::class);
        $this->catalog()->uploadCatalog($this->temporaryFile('csv', 'id'), 'merge');
    }

    public function test_upload_is_not_retried(): void
    {
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);

        try {
            $this->catalog()->uploadCatalog($this->temporaryFile('csv', 'id,name'));
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        Http::assertSentCount(1);
    }

    public function test_upsert_items_moves_extra_fields_into_data_and_casts_ids(): void
    {
        Http::fake(['*' => Http::response(['task_id' => 1, 'task_status_path' => '/v1/tasks/1'])]);

        $this->catalog()->upsertItems([
            ['id' => 123, 'name' => 'Pellet Grill', 'price' => 899, 'url' => '/p/123', 'suggested_score' => 10],
            ['id' => 'SMOKER-1', 'name' => 'Smoker', 'data' => ['price' => 499]],
        ], 'Products', ['force' => true]);

        Http::assertSent(function (Request $request) {
            $this->assertSame('PUT', $request->method());
            $this->assertSame('/v2/items', $this->pathOf($request));
            $this->assertBasicAuthWithApiToken($request);
            $this->assertSame(['true'], $this->queryOf($request)['force']);
            $this->assertSame([
                ['id' => '123', 'name' => 'Pellet Grill', 'suggested_score' => 10, 'data' => ['price' => 899, 'url' => '/p/123']],
                ['id' => 'SMOKER-1', 'name' => 'Smoker', 'data' => ['price' => 499]],
            ], $request->data()['items']);

            return true;
        });
    }

    public function test_upsert_items_requires_id_and_name(): void
    {
        Http::fake();

        $this->expectException(InvalidCatalogDataException::class);
        $this->expectExceptionMessage('needs a non-empty "name"');

        $this->catalog()->upsertItems([['id' => 'X']]);
    }

    public function test_items_are_sent_in_batches(): void
    {
        $this->app->make('config')->set('constructor.catalog.batch_size', 2);
        $this->app->forgetInstance(ConstructorConfig::class);
        $this->app->forgetInstance(ApiClient::class);
        $this->app->forgetInstance(CatalogService::class);

        Http::fake(['*' => Http::sequence()
            ->push(['task_id' => 1, 'task_status_path' => '/v1/tasks/1'])
            ->push(['task_id' => 2, 'task_status_path' => '/v1/tasks/2'])
            ->push(['task_id' => 3, 'task_status_path' => '/v1/tasks/3'])]);

        $results = $this->catalog()->deleteItems(['a', 'b', 'c', 'd', 'e']);

        $this->assertSame([1, 2, 3], array_column($results, 'task_id'));
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->data()['items'] === [['id' => 'a'], ['id' => 'b']]);
    }

    public function test_patch_items_only_require_an_id_and_send_on_missing(): void
    {
        Http::fake(['*' => Http::response(['task_id' => 5, 'task_status_path' => '/v1/tasks/5'])]);

        $this->catalog()->patchItems([['id' => 'GRILL-1', 'data' => ['price' => 799]]], null, ['on_missing' => 'create']);

        Http::assertSent(function (Request $request) {
            $this->assertSame('PATCH', $request->method());
            $this->assertSame(['CREATE'], $this->queryOf($request)['on_missing']);
            $this->assertSame([['id' => 'GRILL-1', 'data' => ['price' => 799]]], $request->data()['items']);

            return true;
        });
    }

    public function test_get_item_encodes_the_id_and_returns_null_when_missing(): void
    {
        Http::fake([
            '*/v2/items/a%2Fb*' => Http::response(['id' => 'a/b', 'name' => 'Grate']),
            '*' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->assertSame('Grate', $this->catalog()->getItem('a/b')['name']);
        $this->assertNull($this->catalog()->getItem('missing'));
    }

    public function test_list_items(): void
    {
        Http::fake(['*' => Http::response(['items' => [['id' => '1', 'name' => 'Grill']], 'total_count' => 1])]);

        $page = $this->catalog()->listItems(null, ['ids' => ['1', '2'], 'per_page' => 5000]);

        $this->assertSame(1, $page['total_count']);
        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);
            $this->assertSame(['1', '2'], $query['id']);
            $this->assertSame(['1000'], $query['num_results_per_page']);

            return true;
        });
    }

    public function test_verify_credentials(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['items' => [], 'total_count' => 0])
            ->push(['message' => 'Unauthorized'], 401)]);

        $this->assertTrue($this->catalog()->verifyCredentials());
        $this->assertFalse($this->catalog()->verifyCredentials());
        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/v2/items');
    }

    public function test_task_status_flags_cover_every_documented_status(): void
    {
        $statuses = ['QUEUED' => [false, false], 'IN_PROGRESS' => [false, false], 'DONE' => [true, true], 'FAILED' => [true, false], 'CANCELED' => [true, false]];

        $responses = Http::sequence();
        foreach (array_keys($statuses) as $status) {
            $responses->push([
                'id' => 1,
                'status' => $status,
                'result' => ['ingestion_warnings' => [['code' => 'MISSING_FIELD', 'message' => 'price missing']]],
            ]);
        }
        Http::fake(['*' => $responses]);

        foreach ($statuses as $status => [$completed, $successful]) {
            $task = $this->catalog()->getTaskStatus('1');

            $this->assertSame($completed, $task['completed'], $status);
            $this->assertSame($successful, $task['successful'], $status);
            $this->assertSame('MISSING_FIELD', $task['warnings'][0]['code']);
        }
    }

    public function test_task_id_must_be_a_positive_integer(): void
    {
        Http::fake();

        $this->expectException(InvalidCatalogDataException::class);
        $this->catalog()->getTaskStatus('1/../../v2/items');
    }

    public function test_wait_for_task_completion_polls_until_finished(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::sequence()
            ->push(['id' => 3, 'status' => 'QUEUED'])
            ->push(['id' => 3, 'status' => 'IN_PROGRESS'])
            ->push(['id' => 3, 'status' => 'DONE'])]);

        $task = $this->catalog()->waitForTaskCompletion(3, timeoutSeconds: 60, pollIntervalSeconds: 5);

        $this->assertTrue($task['successful']);
        Sleep::assertSleptTimes(2);
    }

    public function test_wait_for_task_completion_times_out(): void
    {
        Sleep::fake(syncWithCarbon: true);
        Http::fake(['*' => Http::response(['id' => 3, 'status' => 'IN_PROGRESS'])]);

        $this->expectException(TaskTimeoutException::class);
        $this->catalog()->waitForTaskCompletion(3, timeoutSeconds: 20, pollIntervalSeconds: 10);
    }

    public function test_authentication_errors_are_typed(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Forbidden'], 403)]);

        $this->expectException(AuthenticationException::class);
        $this->catalog()->getTaskStatus(1);
    }

    public function test_missing_api_token_is_a_configuration_error(): void
    {
        $this->app->make('config')->set('constructor.api_token', null);
        $this->app->forgetInstance(ConstructorConfig::class);
        $this->app->forgetInstance(ApiClient::class);
        $this->app->forgetInstance(CatalogService::class);
        Http::fake();

        $this->expectException(ConfigurationException::class);
        $this->catalog()->getTaskStatus(1);
    }

    public function test_item_writes_are_not_retried_after_a_server_error(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response('unavailable', 503)]);

        try {
            $this->app->make(CatalogService::class)->upsertItems([['id' => 'TRG-780', 'name' => 'Pro 780']]);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        // A retried write could create a duplicate ingestion task.
        Http::assertSentCount(1);
    }

    public function test_catalog_reads_are_retried_after_a_server_error(): void
    {
        Http::fakeSequence('ac.cnstrc.com/*')
            ->push('unavailable', 503)
            ->push(['id' => 'TRG-780', 'name' => 'Pro 780']);

        $this->assertSame('TRG-780', $this->app->make(CatalogService::class)->getItem('TRG-780')['id']);
        Http::assertSentCount(2);
    }

    public function test_waiting_for_a_task_checks_one_last_time_at_the_deadline(): void
    {
        Sleep::fake();
        Http::fakeSequence('ac.cnstrc.com/*')
            ->push(['id' => 7, 'status' => 'IN_PROGRESS'])
            ->push(['id' => 7, 'status' => 'DONE', 'result' => ['index_built' => true]]);

        $status = $this->app->make(CatalogService::class)->waitForTaskCompletion(7, timeoutSeconds: 15, pollIntervalSeconds: 10);

        $this->assertTrue($status['successful']);
        $this->assertTrue($status['indexed']);
    }

    public function test_upsert_variations_requires_an_item_id_and_moves_extra_fields_into_data(): void
    {
        Http::fake(['*' => Http::response(['task_id' => 8, 'task_status_path' => '/v1/tasks/8'])]);

        $results = $this->catalog()->upsertVariations([
            ['id' => 'TRG-780-BLK', 'item_id' => 'TRG-780', 'name' => 'Pro 780 Black', 'color' => 'Black', 'data' => ['price' => 999]],
        ], 'Products', ['notification_email' => 'catalog@example.com']);

        $this->assertSame(8, $results[0]['task_id']);
        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame('PUT', $request->method());
            $this->assertSame('/v2/variations', $this->pathOf($request));
            $this->assertBasicAuthWithApiToken($request);
            $this->assertSame(['Products'], $query['section']);
            $this->assertSame(['catalog@example.com'], $query['notification_email']);
            $this->assertSame([
                ['id' => 'TRG-780-BLK', 'item_id' => 'TRG-780', 'name' => 'Pro 780 Black', 'data' => ['color' => 'Black', 'price' => 999]],
            ], $request->data()['variations']);

            return true;
        });

        $this->expectException(InvalidCatalogDataException::class);
        $this->expectExceptionMessage('needs a non-empty "item_id"');
        $this->catalog()->upsertVariations([['id' => 'ORPHAN']]);
    }

    public function test_patch_and_delete_variations(): void
    {
        Http::fake(['*' => Http::response(['task_id' => 9, 'task_status_path' => '/v1/tasks/9'])]);

        $this->catalog()->patchVariations([['id' => 'TRG-780-BLK', 'in_stock' => false]], null, ['on_missing' => 'ignore']);
        $this->catalog()->deleteVariations(['TRG-780-BLK', '', 'TRG-780-RED']);

        $sent = Http::recorded();
        [$patch] = $sent[0];
        [$delete] = $sent[1];

        $this->assertSame('PATCH', $patch->method());
        $this->assertSame('/v2/variations', $this->pathOf($patch));
        $this->assertSame(['IGNORE'], $this->queryOf($patch)['on_missing']);
        $this->assertSame([['id' => 'TRG-780-BLK', 'data' => ['in_stock' => false]]], $patch->data()['variations']);

        $this->assertSame('DELETE', $delete->method());
        $this->assertSame([['id' => 'TRG-780-BLK'], ['id' => 'TRG-780-RED']], $delete->data()['variations']);
    }

    public function test_variations_are_sent_in_batches(): void
    {
        $this->reconfigure(['constructor.catalog.batch_size' => 2]);
        Http::fake(['*' => Http::response(['task_id' => 1, 'task_status_path' => '/v1/tasks/1'])]);

        $results = $this->catalog()->upsertVariations([
            ['id' => 'v1', 'item_id' => 'i'], ['id' => 'v2', 'item_id' => 'i'], ['id' => 'v3', 'item_id' => 'i'],
        ]);

        $this->assertCount(2, $results);
        Http::assertSentCount(2);
    }

    public function test_get_and_list_variations(): void
    {
        Http::fake([
            '*/v2/variations/TRG%2F780-BLK*' => Http::response(['id' => 'TRG/780-BLK', 'item_id' => 'TRG/780']),
            '*/v2/variations/missing*' => Http::response(['message' => 'Not found'], 404),
            '*/v2/variations?*' => Http::response(['variations' => [['id' => 'v1', 'item_id' => 'TRG-780']], 'total_count' => 1]),
        ]);

        $this->assertSame('TRG/780', $this->catalog()->getVariation('TRG/780-BLK')['item_id']);
        $this->assertNull($this->catalog()->getVariation('missing'));

        $page = $this->catalog()->listVariations('Products', ['item_ids' => ['TRG-780', 'TRG-575'], 'per_page' => 50, 'page' => 2]);

        $this->assertSame('v1', $page['variations'][0]['id']);
        $this->assertSame(1, $page['total_count']);
        $this->assertNull($page['next_page_cursor']);

        Http::assertSent(function (Request $request) {
            if ($this->pathOf($request) !== '/v2/variations') {
                return false;
            }

            $query = $this->queryOf($request);
            $this->assertSame(['TRG-780', 'TRG-575'], $query['item_id']);
            $this->assertSame(['50'], $query['num_results_per_page']);
            $this->assertSame(['2'], $query['page']);
            $this->assertSame(['Products'], $query['section']);

            return true;
        });
    }

    public function test_upsert_item_groups_sends_no_section_and_keeps_parent_ids(): void
    {
        Http::fake(['*' => Http::response(['task_id' => 11, 'task_status_path' => '/v1/tasks/11'])]);

        $this->catalog()->upsertItemGroups([
            ['id' => 'grills', 'name' => 'Grills'],
            ['id' => 'pellet-grills', 'name' => 'Pellet Grills', 'parent_ids' => 'grills', 'image_url' => '/img/pellet.jpg'],
        ], ['force' => true]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame('PUT', $request->method());
            $this->assertSame('/v2/item_groups', $this->pathOf($request));
            $this->assertBasicAuthWithApiToken($request);
            // Item groups belong to the whole index; the endpoint has no section parameter.
            $this->assertArrayNotHasKey('section', $query);
            $this->assertSame(['true'], $query['force']);
            $this->assertSame([
                ['id' => 'grills', 'name' => 'Grills'],
                ['id' => 'pellet-grills', 'name' => 'Pellet Grills', 'parent_ids' => ['grills'], 'data' => ['image_url' => '/img/pellet.jpg']],
            ], $request->data()['item_groups']);

            return true;
        });
    }

    public function test_upsert_item_groups_requires_a_name(): void
    {
        Http::fake();

        $this->expectException(InvalidCatalogDataException::class);
        $this->expectExceptionMessage('Item group "grills" needs a non-empty "name"');

        $this->catalog()->upsertItemGroups([['id' => 'grills']]);
    }

    public function test_patch_item_groups_only_needs_an_id_and_sends_no_on_missing(): void
    {
        Http::fake(['*' => Http::response(['task_id' => 12, 'task_status_path' => '/v1/tasks/12'])]);

        // An empty parent list moves the group to the top of the tree, so it is kept.
        $this->catalog()->patchItemGroups([['id' => 'pellet-grills', 'parent_ids' => []]], ['on_missing' => 'CREATE']);
        $this->catalog()->deleteItemGroups(['old-category']);

        [$patch] = Http::recorded()[0];
        [$delete] = Http::recorded()[1];

        $this->assertSame('PATCH', $patch->method());
        $this->assertArrayNotHasKey('on_missing', $this->queryOf($patch));
        $this->assertArrayNotHasKey('section', $this->queryOf($patch));
        $this->assertSame([['id' => 'pellet-grills', 'parent_ids' => []]], $patch->data()['item_groups']);

        $this->assertSame('DELETE', $delete->method());
        $this->assertSame('/v2/item_groups', $this->pathOf($delete));
        $this->assertSame([['id' => 'old-category']], $delete->data()['item_groups']);
    }

    public function test_get_and_list_item_groups(): void
    {
        Http::fake([
            '*/v2/item_groups/grills%2Fgas*' => Http::response(['id' => 'grills/gas', 'name' => 'Gas Grills', 'parent_ids' => ['grills']]),
            '*/v2/item_groups/missing*' => Http::response(['message' => 'Not found'], 404),
            '*/v2/item_groups?*' => Http::response(['item_groups' => [['id' => 'grills', 'name' => 'Grills']], 'total_count' => 1]),
        ]);

        $this->assertSame('Gas Grills', $this->catalog()->getItemGroup('grills/gas')['name']);
        $this->assertNull($this->catalog()->getItemGroup('missing'));

        $page = $this->catalog()->listItemGroups(['ids' => ['grills'], 'per_page' => 5000, 'next_page_cursor' => 'ignored']);

        $this->assertSame(['item_groups' => [['id' => 'grills', 'name' => 'Grills']], 'total_count' => 1], $page);

        Http::assertSent(function (Request $request) {
            if ($this->pathOf($request) !== '/v2/item_groups') {
                return false;
            }

            $query = $this->queryOf($request);
            $this->assertSame(['grills'], $query['id']);
            $this->assertSame(['1000'], $query['num_results_per_page']);
            $this->assertArrayNotHasKey('section', $query);
            // Item groups have no cursor pagination.
            $this->assertArrayNotHasKey('next_page_cursor', $query);

            return true;
        });
    }

    public function test_item_group_writes_are_not_retried(): void
    {
        Http::fake(['*' => Http::response('unavailable', 503)]);

        try {
            $this->catalog()->upsertItemGroups([['id' => 'grills', 'name' => 'Grills']]);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        Http::assertSentCount(1);
    }

    public function test_a_task_without_a_status_times_out_cleanly(): void
    {
        Sleep::fake();
        Http::fake(['ac.cnstrc.com/*' => Http::response(['id' => 7])]);

        $this->expectException(TaskTimeoutException::class);
        $this->expectExceptionMessage('was still unknown');

        $this->app->make(CatalogService::class)->waitForTaskCompletion(7, timeoutSeconds: 1, pollIntervalSeconds: 1);
    }
}
