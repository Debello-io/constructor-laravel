<?php

namespace ConstructorIO\Laravel\View\Components;

/**
 * The container around a product list Constructor didn't produce: a
 * hand-curated landing page or promotion, for example.
 *
 *     <x-constructor::generic-results :total="count($products)">
 *         <x-constructor::item :product="$product">...</x-constructor::item>  (one per product)
 *     </x-constructor::generic-results>
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
class GenericResults extends TrackingComponent
{
    public function __construct(
        public int $total,
        string $tag = 'div',
    ) {
        parent::__construct($tag);
    }

    public function trackingAttributes(): array
    {
        return [
            'data-cnstrc-generic-results' => '',
            'data-cnstrc-num-results' => self::count($this->total),
        ];
    }
}
