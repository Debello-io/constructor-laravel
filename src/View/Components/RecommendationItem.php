<?php

namespace ConstructorIO\Laravel\View\Components;

/**
 * One product inside a <x-constructor::recommendations> container:
 *
 *     <x-constructor::recommendation-item :product="$item">...</x-constructor::recommendation-item>
 *
 * Adds data-cnstrc-item="recommendation" and the strategy ID to the usual
 * item attributes. When recommendations fill a zero-state search box (shown
 * before the shopper types), also pass the section, e.g. section="Products".
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
class RecommendationItem extends TrackingComponent
{
    /**
     * @param  array<string, mixed>  $product  A product from RecommendationResults.
     */
    public function __construct(
        public array $product,
        public ?string $section = null,
        string $tag = 'div',
    ) {
        parent::__construct($tag);
    }

    public function trackingAttributes(): array
    {
        $strategy = $this->product['strategy'] ?? null;

        return [
            'data-cnstrc-item' => 'recommendation',
            ...self::itemAttributes($this->product),
            'data-cnstrc-strategy-id' => self::text(is_array($strategy) ? ($strategy['id'] ?? null) : null),
            'data-cnstrc-item-section' => self::text($this->section),
        ];
    }
}
