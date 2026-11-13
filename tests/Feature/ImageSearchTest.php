<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Support\RequestContext;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use SplFileInfo;

/**
 * SearchService::searchByImage() (POST /v2/image_search on image-search.cnstrc.com)
 * and SearchService::filterImageResults().
 *
 * @see https://docs.constructor.com/reference/v2-image-search-retrieve-search-results
 */
class ImageSearchTest extends TestCase
{
    /** The smallest byte string Image Search would recognise as a PNG. */
    private const PNG = "\x89PNG\r\n\x1A\n".'rest-of-the-image';

    private function service(): SearchService
    {
        return app(SearchService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function imageSearchResponse(): array
    {
        return [
            'request' => ['section' => 'Products'],
            'result_id' => 'img-result-1',
            'response' => [
                'total_num_results' => 2,
                'results' => [
                    ['value' => 'Genesis E-325', 'data' => ['id' => 'WEB-GEN-325', 'price' => 999]],
                    ['value' => 'Spirit E-310', 'data' => ['id' => 'WEB-SPI-310', 'price' => 599]],
                ],
                'facets' => [],
                'groups' => [],
                'sort_options' => [],
                'refined_content' => [],
                'browse_url' => 'https://ac.cnstrc.com/browse/items?key=key_test&ids=WEB-GEN-325&ids=WEB-SPI-310',
            ],
            'segmentation_used' => false,
            'whole_image_search_fallback' => false,
        ];
    }

    public function test_posts_the_raw_image_with_identity_and_backend_headers(): void
    {
        Http::fake(['image-search.cnstrc.com/*' => Http::response($this->imageSearchResponse())]);
        $this->actAsShopperRequest([RequestContext::CLIENT_ID_COOKIE => 'client-abc', RequestContext::SESSION_ID_COOKIE => '3']);

        $results = $this->service()->searchByImage(self::PNG);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://image-search.cnstrc.com/v2/image_search?')
                && $request->body() === self::PNG
                && $request->header('Content-Type') === ['application/octet-stream']
                && $query['key'] === ['key_test']
                && $query['section'] === ['Products']
                && $query['i'] === ['client-abc']
                && $query['s'] === ['3']
                && $query['c'] === ['cio-be-web-bbq-test-store']
                && $request->header('x-cnstrc-token') === ['backend_security_token']
                && $request->header('X-Forwarded-For') === ['203.0.113.42']
                // Storefront call: the secret API token must never be sent.
                && ! $request->hasHeader('Authorization');
        });

        $this->assertSame(['WEB-GEN-325', 'WEB-SPI-310'], array_column($results->products, 'id'));
        $this->assertSame(2, $results->total);
        $this->assertSame('img-result-1', $results->resultId);
        $this->assertSame(1, $results->page);
        $this->assertStringContainsString('/browse/items', $results->metadata['browse_url']);
        $this->assertFalse($results->metadata['segmentation_used']);
        $this->assertFalse($results->hasMore());
    }

    public function test_sends_documented_options_only(): void
    {
        Http::fake(['image-search.cnstrc.com/*' => Http::response($this->imageSearchResponse() + ['segments' => [['id' => 0, 'class_name' => 'grill']]])]);

        $results = $this->service()->searchByImage(self::PNG, [
            'section' => 'Accessories',
            'use_segmentation' => true,
            'return_segments' => true,
            'search_crop_type' => 'segment',
            'hidden_fields' => ['inventory_count'],
            'user_segments' => ['vip', 'texas'],
            'variations_map' => ['group_by' => [['name' => 'color', 'field' => 'data.color']], 'values' => [], 'dtype' => 'array'],
            // Not image-search options: must not be sent.
            'per_page' => 50,
            'sort_by' => 'price',
            'filters' => ['brand' => 'Weber'],
        ]);

        $query = $this->queryOf($this->sentRequests()[0]);
        $this->assertSame(['Accessories'], $query['section']);
        $this->assertSame(['true'], $query['use_segmentation']);
        $this->assertSame(['true'], $query['return_segments']);
        $this->assertSame(['segment'], $query['search_crop_type']);
        $this->assertSame(['inventory_count'], $query['fmt_options[hidden_fields]']);
        $this->assertSame(['vip', 'texas'], $query['us']);
        $this->assertSame(['{"group_by":[{"name":"color","field":"data.color"}],"values":[],"dtype":"array"}'], $query['variations_map']);
        $this->assertArrayNotHasKey('num_results_per_page', $query);
        $this->assertArrayNotHasKey('sort_by', $query);
        $this->assertArrayNotHasKey('filters[brand]', $query);

        $this->assertSame([['id' => 0, 'class_name' => 'grill']], $results->metadata['segments']);
    }

    public function test_accepts_an_uploaded_file_and_a_trusted_file(): void
    {
        Http::fake(['image-search.cnstrc.com/*' => Http::response($this->imageSearchResponse())]);
        $upload = UploadedFile::fake()->createWithContent('photo.png', self::PNG);

        $this->service()->searchByImage($upload);
        Constructor::searchByImage(new SplFileInfo((string) $upload->getRealPath()));

        $this->assertSame([self::PNG, self::PNG], array_map(fn (Request $request) => $request->body(), $this->sentRequests()));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function supportedImages(): array
    {
        return [
            'jpeg' => ["\xFF\xD8\xFF\xE0jfif"],
            'png' => [self::PNG],
            'gif' => ['GIF89a...'],
            'webp' => ['RIFF'."\x10\x00\x00\x00".'WEBPVP8 '],
        ];
    }

    #[DataProvider('supportedImages')]
    public function test_supported_formats_are_recognised_by_their_contents(string $bytes): void
    {
        Http::fake(['image-search.cnstrc.com/*' => Http::response($this->imageSearchResponse())]);

        $this->service()->searchByImage($bytes);

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedImages(): array
    {
        return [
            'empty' => ['', 'empty'],
            'not an image' => ['<?php echo "hi";', 'JPEG, PNG, WebP or GIF'],
            'HEIC' => ["\x00\x00\x00\x18ftypheic", 'JPEG, PNG, WebP or GIF'],
            // A string is image bytes, never a path to read.
            'a server path' => ['/etc/passwd', 'JPEG, PNG, WebP or GIF'],
            'too large' => ["\xFF\xD8\xFF".str_repeat('x', SearchService::MAX_IMAGE_BYTES), 'up to 6 MB'],
        ];
    }

    #[DataProvider('rejectedImages')]
    public function test_bad_images_throw_before_anything_is_sent(string $bytes, string $message): void
    {
        Http::fake();

        try {
            $this->service()->searchByImage($bytes);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_oversized_file_is_rejected_without_being_read(): void
    {
        Http::fake();
        $path = (string) tempnam(sys_get_temp_dir(), 'cnstrc-image');
        file_put_contents($path, "\xFF\xD8\xFF".str_repeat('x', SearchService::MAX_IMAGE_BYTES));

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('up to 6 MB');

            $this->service()->searchByImage(new SplFileInfo($path));
        } finally {
            unlink($path);
            Http::assertNothingSent();
        }
    }

    public function test_a_missing_file_and_a_failed_upload_are_rejected(): void
    {
        Http::fake();

        try {
            $this->service()->searchByImage(new SplFileInfo('/nonexistent/photo.png'));
            $this->fail('Expected an InvalidArgumentException for a missing file.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }

        $failedUpload = new UploadedFile(__FILE__, 'photo.png', 'image/png', UPLOAD_ERR_PARTIAL, true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('upload failed');

        $this->service()->searchByImage($failedUpload);
    }

    public function test_an_unknown_crop_type_is_rejected(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bbox');

        $this->service()->searchByImage(self::PNG, ['search_crop_type' => 'circle']);
    }

    public function test_failures_return_empty_results_by_default(): void
    {
        $this->spyOnLog();
        Http::fake(['image-search.cnstrc.com/*' => Http::response(['message' => 'boom'], 400)]);

        $results = $this->service()->searchByImage(self::PNG);

        $this->assertTrue($results->isEmpty());
        // 400 can't succeed on a retry.
        Http::assertSentCount(1);
    }

    public function test_server_errors_are_retried_and_can_throw(): void
    {
        $this->reconfigure(['constructor.throw_exceptions' => true]);
        Http::fake(['image-search.cnstrc.com/*' => Http::response(['message' => 'unavailable'], 503)]);

        try {
            $this->service()->searchByImage(self::PNG);
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        // retry_attempts defaults to 2 total attempts; the image is resent in full.
        $this->assertSame([self::PNG, self::PNG], array_map(fn (Request $request) => $request->body(), $this->sentRequests()));
    }

    public function test_filter_image_results_browses_the_matched_items_with_filters(): void
    {
        Http::fake(['ac.cnstrc.com/browse/items*' => Http::response(['result_id' => 'browse-1', 'response' => [
            'total_num_results' => 1,
            'results' => [['value' => 'Genesis E-325', 'data' => ['id' => 'WEB-GEN-325']]],
        ]])]);
        $matches = new SearchResults(
            products: [['id' => 'WEB-GEN-325'], ['id' => 'WEB-SPI-310'], ['id' => '']],
            total: 3,
            page: 1,
            perPage: 3,
        );

        $filtered = Constructor::filterImageResults($matches, ['brand' => 'Weber'], ['per_page' => 12]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $request->method() === 'GET'
                && $this->pathOf($request) === '/browse/items'
                && $query['ids'] === ['WEB-GEN-325', 'WEB-SPI-310']
                && $query['filters[brand]'] === ['Weber']
                && $query['num_results_per_page'] === ['12'];
        });
        $this->assertSame('browse-1', $filtered->resultId);
    }

    public function test_filtering_empty_image_results_sends_nothing(): void
    {
        Http::fake();

        $filtered = $this->service()->filterImageResults(SearchResults::empty(), ['brand' => 'Weber']);

        $this->assertTrue($filtered->isEmpty());
        Http::assertNothingSent();
    }

    public function test_the_image_search_host_must_be_https(): void
    {
        $this->expectExceptionMessage('image_search_base_url');

        $this->reconfigure(['constructor.image_search_base_url' => 'http://image-search.example.com']);
        $this->service()->searchByImage(self::PNG);
    }
}
