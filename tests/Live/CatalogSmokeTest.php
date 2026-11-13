<?php

namespace ConstructorIO\Laravel\Tests\Live;

use ConstructorIO\Laravel\Services\CatalogService;

/**
 * Catalog checks against a real index. Reads need CONSTRUCTOR_API_TOKEN.
 * The write test is off unless CONSTRUCTOR_LIVE_ALLOW_WRITES=true and
 * CONSTRUCTOR_LIVE_WRITE_SECTION are both set; it creates one clearly named
 * item and deletes it again.
 */
class CatalogSmokeTest extends LiveTestCase
{
    private const TEST_ITEM_ID = 'cio-laravel-smoke-test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireEnv('CONSTRUCTOR_API_TOKEN', 'catalog management');
    }

    private function catalog(): CatalogService
    {
        return app(CatalogService::class);
    }

    public function test_the_api_token_is_accepted(): void
    {
        $this->assertTrue($this->catalog()->verifyCredentials(), 'Constructor rejected CONSTRUCTOR_API_TOKEN (401/403).');
    }

    public function test_items_can_be_listed_and_read(): void
    {
        $list = $this->catalog()->listItems(null, ['per_page' => 2]);

        $itemId = self::env('CONSTRUCTOR_LIVE_ITEM_ID') ?? ($list['items'][0]['id'] ?? null);
        if (! is_string($itemId)) {
            $this->markTestSkipped('The section has no items to read.');
        }

        $item = $this->catalog()->getItem($itemId);
        $this->assertNotNull($item);
        $this->assertSame($itemId, $item['id']);
    }

    public function test_an_item_can_be_written_and_deleted(): void
    {
        if (self::env('CONSTRUCTOR_LIVE_ALLOW_WRITES') !== 'true') {
            $this->markTestSkipped('Writes are off. Set CONSTRUCTOR_LIVE_ALLOW_WRITES=true and CONSTRUCTOR_LIVE_WRITE_SECTION to test them, on a test index only.');
        }
        $section = $this->requireEnv('CONSTRUCTOR_LIVE_WRITE_SECTION', 'catalog writes');

        try {
            $created = $this->catalog()->upsertItems([[
                'id' => self::TEST_ITEM_ID,
                'name' => 'Constructor Laravel smoke test item (safe to delete)',
                'data' => ['url' => 'https://example.com/smoke-test'],
            ]], $section);
            $this->waitForEach($created);
        } finally {
            // Clean up even if the upsert's task failed.
            $deleted = $this->catalog()->deleteItems([self::TEST_ITEM_ID], $section);
        }

        $this->waitForEach($deleted);
    }

    /**
     * @param  list<array{task_id: int|null, task_status_path: string|null, message: string|null, response: array<string, mixed>}>  $batches
     */
    private function waitForEach(array $batches): void
    {
        foreach ($batches as $batch) {
            if ($batch['task_id'] === null) {
                continue;
            }

            $status = $this->catalog()->waitForTaskCompletion($batch['task_id'], 300, 5);
            $this->assertTrue($status['successful'], 'Task '.$batch['task_id'].' finished as '.($status['status'] ?? 'unknown').'.');
        }
    }
}
