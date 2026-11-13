<?php

namespace ConstructorIO\Laravel\View\Components;

use ConstructorIO\Laravel\DataTransferObjects\RecommendationResults;

/**
 * The container around one recommendation pod:
 *
 *     <x-constructor::recommendations :results="$accessories" :seed-items="$product['id']">
 *         <x-constructor::recommendation-item :product="$item">...</x-constructor::recommendation-item>  (one per product)
 *     </x-constructor::recommendations>
 *
 * Works for a pod from getRecommendations() / getItemRecommendations() and
 * for each pod of a getRecommendationPage() result, because every pod
 * carries its own result ID.
 *
 * seed-items: the item ID(s) you requested the pod with (a string or a list),
 * for item-based strategies such as alternative or complementary items.
 * Leave it out for pods that don't use an item (best sellers, trending).
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
class Recommendations extends TrackingComponent
{
    /**
     * @param  string|list<string|int>|null  $seedItems
     */
    public function __construct(
        public RecommendationResults $results,
        public string|array|null $seedItems = null,
        string $tag = 'div',
    ) {
        parent::__construct($tag);
    }

    public function trackingAttributes(): array
    {
        return [
            'data-cnstrc-recommendations' => '',
            'data-cnstrc-recommendations-pod-id' => self::text($this->results->podId),
            'data-cnstrc-result-id' => self::text($this->results->resultId),
            // The docs ask for the total from the response; fall back to the
            // number of products when Constructor didn't report one.
            'data-cnstrc-num-results' => self::count($this->results->total > 0 ? $this->results->total : $this->results->count()),
            'data-cnstrc-recommendations-seed-items' => $this->seedItems(),
        ];
    }

    /**
     * Seed item IDs, comma-separated as the beacon expects.
     */
    private function seedItems(): ?string
    {
        $ids = array_filter(
            array_map(fn (mixed $id) => self::text($id), (array) $this->seedItems),
            fn (?string $id) => $id !== null,
        );

        return $ids === [] ? null : implode(',', $ids);
    }
}
