<?php

namespace ConstructorIO\Laravel\View\Components;

/**
 * The container around a product detail page, or a quick-view modal:
 *
 *     <x-constructor::product-detail :product="$product">...</x-constructor::product-detail>
 *     <x-constructor::product-detail :product="$product" modal>...</x-constructor::product-detail>
 *
 * Renders data-cnstrc-product-detail (or data-cnstrc-product-detail-modal)
 * with the item ID, name, shown variation ID and price. `product` can be a
 * product from any results DTO, a catalog item from getItem(), or your own
 * array with id, name and optionally variation_id and price.
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
class ProductDetail extends TrackingComponent
{
    /**
     * @param  array<string, mixed>  $product
     */
    public function __construct(
        public array $product,
        public bool $modal = false,
        string $tag = 'div',
    ) {
        parent::__construct($tag);
    }

    public function trackingAttributes(): array
    {
        // Catalog items from getItem() keep price and variation in `data`.
        $data = is_array($this->product['data'] ?? null) ? $this->product['data'] : [];

        return [
            $this->modal ? 'data-cnstrc-product-detail-modal' : 'data-cnstrc-product-detail' => '',
            ...self::itemAttributes($this->product + $data),
        ];
    }
}
