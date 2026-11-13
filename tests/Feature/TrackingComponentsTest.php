<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\DataTransferObjects\QuizResults;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationResults;
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Support\ResultTransformer;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\View\ViewException;

/**
 * The <x-constructor::...> components render exactly the data-cnstrc-*
 * attributes the beacon reads, escaped.
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
class TrackingComponentsTest extends TestCase
{
    /**
     * A product in the shape every results DTO uses.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra  Top-level result fields (labels, strategy).
     * @return array<string, mixed>
     */
    private function product(array $data = [], array $extra = []): array
    {
        return app(ResultTransformer::class)->product($extra + [
            'value' => 'Pro 780 Pellet Grill',
            'data' => $data + ['id' => 'TRG-780', 'price' => 999.99],
        ]);
    }

    private function searchResults(?string $resultId = 'res-1', int $total = 42): SearchResults
    {
        return new SearchResults(
            products: [$this->product()],
            total: $total,
            page: 2,
            perPage: 24,
            metadata: ['request' => ['term' => 'pellet grill', 'section' => 'Products']],
            resultId: $resultId,
        );
    }

    /**
     * Render Blade and collapse whitespace, so assertions can match tags.
     *
     * @param  array<string, mixed>  $data
     */
    private function render(string $template, array $data = []): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $this->blade($template, $data));
    }

    public function test_search_container_renders_every_documented_attribute_and_the_tracking_script(): void
    {
        $html = $this->render(
            '<x-constructor::search-results :results="$results" term="pellet grill" class="grid">Items</x-constructor::search-results>',
            ['results' => $this->searchResults()],
        );

        $this->assertStringContainsString(
            '<div data-cnstrc-search="" data-cnstrc-search-term="pellet grill" data-cnstrc-section="Products" data-cnstrc-result-id="res-1" data-cnstrc-num-results="42" data-cnstrc-result-page="2" class="grid">Items</div>',
            $html,
        );
        $this->assertStringContainsString(
            '<script id="cnstrc-data" type="application/json">{"request":{"term":"pellet grill","section":"Products"},"result_id":"res-1"}</script>',
            $html,
        );
    }

    public function test_zero_results_keep_the_container_attributes_with_a_count_of_zero(): void
    {
        $results = new SearchResults(products: [], total: 0, page: 1, perPage: 24, resultId: 'res-0');

        $html = $this->render('<x-constructor::search-results :results="$results" term="xyz" />', ['results' => $results]);

        $this->assertStringContainsString('data-cnstrc-num-results="0"', $html);
        $this->assertStringContainsString('data-cnstrc-result-id="res-0"', $html);
    }

    public function test_results_not_from_constructor_get_no_result_id_and_no_tracking_script(): void
    {
        // An empty fallback after a failure has no result ID.
        $html = $this->render('<x-constructor::search-results :results="$results" term="grill" />', [
            'results' => SearchResults::empty(),
        ]);

        $this->assertStringNotContainsString('data-cnstrc-result-id', $html);
        $this->assertStringNotContainsString('cnstrc-data', $html);
        $this->assertStringContainsString('data-cnstrc-section="Products"', $html);
    }

    public function test_tracking_script_can_be_turned_off_and_printed_separately(): void
    {
        $html = $this->render(
            '<x-constructor::search-results :results="$results" term="grill" :with-tracking-data="false" tag="ul" /><x-constructor::tracking-data :results="$results" />',
            ['results' => $this->searchResults()],
        );

        $this->assertStringContainsString('<ul data-cnstrc-search=""', $html);
        $this->assertSame(1, substr_count($html, 'id="cnstrc-data"'));
    }

    public function test_tracking_script_json_cannot_close_the_script_tag(): void
    {
        $results = new SearchResults(
            products: [],
            total: 0,
            page: 1,
            perPage: 24,
            metadata: ['request' => ['term' => '</script><script>alert(1)</script>']],
            resultId: 'res-1',
        );

        $html = $this->render('<x-constructor::tracking-data :results="$results" />', ['results' => $results]);

        $this->assertStringNotContainsString('</script><script>', $html);
        // "</script>" as JSON with < and > \u-escaped.
        $this->assertStringContainsString(trim(json_encode('</script>', JSON_HEX_TAG | JSON_THROW_ON_ERROR), '"'), $html);
        $this->assertSame(1, substr_count($html, '</script>'));
    }

    public function test_browse_container_names_the_filter(): void
    {
        $html = $this->render(
            '<x-constructor::browse-results :results="$results" filter-name="group_id" filter-value="pellet-grills" section="Products" />',
            ['results' => $this->searchResults()],
        );

        $this->assertStringContainsString(
            '<div data-cnstrc-browse="" data-cnstrc-section="Products" data-cnstrc-result-id="res-1" data-cnstrc-num-results="42" data-cnstrc-result-page="2" data-cnstrc-filter-name="group_id" data-cnstrc-filter-value="pellet-grills">',
            $html,
        );
        $this->assertStringContainsString('id="cnstrc-data"', $html);
    }

    public function test_section_defaults_to_the_configured_section(): void
    {
        $this->reconfigure(['constructor.default_section' => 'Recipes']);

        $html = $this->render('<x-constructor::search-results :results="$results" term="brisket" />', ['results' => $this->searchResults()]);

        $this->assertStringContainsString('data-cnstrc-section="Recipes"', $html);
    }

    public function test_item_renders_id_name_variation_and_price_escaped(): void
    {
        $product = $this->product(['variation_id' => 'TRG-780-BLK'], ['value' => 'Pro 780 "Black" <Edition> & Cover']);

        $html = $this->render('<x-constructor::item :product="$product" tag="li" class="card">Card</x-constructor::item>', ['product' => $product]);

        $this->assertStringContainsString(
            '<li data-cnstrc-item-id="TRG-780" data-cnstrc-item-name="Pro 780 &quot;Black&quot; &lt;Edition&gt; &amp; Cover" data-cnstrc-item-variation-id="TRG-780-BLK" data-cnstrc-item-price="999.99" class="card">Card</li>',
            $html,
        );
    }

    public function test_item_price_is_the_lower_price_and_is_omitted_when_there_is_none(): void
    {
        $onSale = $this->product(['price' => 1199.0, 'original_price' => '899.50']);
        $noPrice = $this->product(['price' => 0]);

        $html = $this->render(
            '<x-constructor::item :product="$onSale" /><x-constructor::item :product="$noPrice" />',
            ['onSale' => $onSale, 'noPrice' => $noPrice],
        );

        $this->assertStringContainsString('data-cnstrc-item-price="899.5"', $html);
        $this->assertSame(1, substr_count($html, 'data-cnstrc-item-price'));
        $this->assertStringNotContainsString('data-cnstrc-item-variation-id', $html);
    }

    public function test_sponsored_items_carry_their_campaign(): void
    {
        $ad = $this->product([], ['labels' => ['is_retail_media' => true, 'sl_campaign_id' => 'camp-7', 'sl_campaign_owner' => 'Traeger']]);

        $html = $this->render('<x-constructor::item :product="$ad" />', ['ad' => $ad]);

        $this->assertStringContainsString('data-cnstrc-sl-campaign-id="camp-7" data-cnstrc-sl-campaign-owner="Traeger"', $html);
    }

    public function test_caller_attributes_override_computed_ones(): void
    {
        // e.g. the shopper picked another swatch.
        $html = $this->render(
            '<x-constructor::item :product="$product" data-cnstrc-item-variation-id="TRG-780-RED" />',
            ['product' => $this->product(['variation_id' => 'TRG-780-BLK'])],
        );

        $this->assertStringContainsString('data-cnstrc-item-variation-id="TRG-780-RED"', $html);
        $this->assertStringNotContainsString('TRG-780-BLK', $html);
    }

    public function test_recommendation_pod_and_items(): void
    {
        $pod = new RecommendationResults(
            podId: 'pdp-complementary',
            title: 'Goes well with',
            products: [$this->product([], ['strategy' => ['id' => 'complementary_items']])],
            total: 6,
            resultId: 'rec-1',
        );

        $html = $this->render(
            '<x-constructor::recommendations :results="$pod" :seed-items="[\'TRG-780\', \'TRG-575\']" tag="section">'
            .'@foreach ($pod->products as $item)<x-constructor::recommendation-item :product="$item" />@endforeach'
            .'</x-constructor::recommendations>',
            ['pod' => $pod],
        );

        $this->assertStringContainsString(
            '<section data-cnstrc-recommendations="" data-cnstrc-recommendations-pod-id="pdp-complementary" data-cnstrc-result-id="rec-1" data-cnstrc-num-results="6" data-cnstrc-recommendations-seed-items="TRG-780,TRG-575">',
            $html,
        );
        $this->assertStringContainsString(
            '<div data-cnstrc-item="recommendation" data-cnstrc-item-id="TRG-780" data-cnstrc-item-name="Pro 780 Pellet Grill" data-cnstrc-item-price="999.99" data-cnstrc-strategy-id="complementary_items">',
            $html,
        );
    }

    public function test_pods_without_seed_items_omit_the_attribute_and_zero_state_items_carry_a_section(): void
    {
        $pod = new RecommendationResults(podId: 'zero-state', title: '', products: [$this->product()], resultId: 'rec-2');

        $html = $this->render(
            '<x-constructor::recommendations :results="$pod"><x-constructor::recommendation-item :product="$pod->products[0]" section="Products" /></x-constructor::recommendations>',
            ['pod' => $pod],
        );

        $this->assertStringNotContainsString('seed-items', $html);
        // No total from Constructor: the product count is used.
        $this->assertStringContainsString('data-cnstrc-num-results="1"', $html);
        $this->assertStringContainsString('data-cnstrc-item-section="Products"', $html);
        $this->assertStringNotContainsString('data-cnstrc-strategy-id', $html);
    }

    public function test_quiz_results_container(): void
    {
        $results = new QuizResults(
            quizId: 'grill-finder',
            products: [],
            total: 20,
            resultId: 'quiz-res',
            quizVersionId: 'v-11',
            quizSessionId: 's-31',
        );

        $html = $this->render('<x-constructor::quiz-results :results="$results" />', ['results' => $results]);

        $this->assertStringContainsString(
            '<div data-cnstrc-quizzes="" data-cnstrc-quiz-id="grill-finder" data-cnstrc-quiz-version-id="v-11" data-cnstrc-quiz-session-id="s-31" data-cnstrc-result-id="quiz-res" data-cnstrc-num-results="20">',
            $html,
        );
    }

    public function test_product_detail_page_and_quick_view_modal(): void
    {
        // A catalog item from getItem() keeps its fields under `data`.
        $item = ['id' => 'TRG-780', 'name' => 'Pro 780', 'data' => ['price' => '999.99', 'variation_id' => 'TRG-780-BLK']];

        $html = $this->render(
            '<x-constructor::product-detail :product="$item" id="pdp" /><x-constructor::product-detail :product="$item" modal />',
            ['item' => $item],
        );

        $this->assertStringContainsString(
            '<div data-cnstrc-product-detail="" data-cnstrc-item-id="TRG-780" data-cnstrc-item-name="Pro 780" data-cnstrc-item-variation-id="TRG-780-BLK" data-cnstrc-item-price="999.99" id="pdp">',
            $html,
        );
        $this->assertStringContainsString('<div data-cnstrc-product-detail-modal="" data-cnstrc-item-id="TRG-780"', $html);
    }

    public function test_generic_results_and_conversion_buttons(): void
    {
        $html = $this->render(
            '<x-constructor::generic-results :total="48"><x-constructor::button class="btn">Add to cart</x-constructor::button>'
            .'<x-constructor::button action="add_to_wishlist" tag="a" href="/wishlist">Save</x-constructor::button></x-constructor::generic-results>',
        );

        $this->assertStringContainsString('<div data-cnstrc-generic-results="" data-cnstrc-num-results="48">', $html);
        $this->assertStringContainsString('<button type="button" data-cnstrc-btn="add_to_cart" class="btn">Add to cart</button>', $html);
        $this->assertStringContainsString('<a data-cnstrc-btn="add_to_wishlist" href="/wishlist">Save</a>', $html);
    }

    public function test_an_invalid_tag_is_rejected(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('valid HTML element name');

        $this->render('<x-constructor::item :product="[]" tag="div onclick=alert(1)" />');
    }
}
