<?php

namespace ConstructorIO\Laravel\View\Components;

/**
 * One result inside a search, browse, quiz or generic results container:
 *
 *     <x-constructor::item :product="$product" class="card">
 *         <a href="{{ $product['url'] }}">{{ $product['name'] }}</a>
 *         <x-constructor::button action="add_to_cart">Add to cart</x-constructor::button>
 *     </x-constructor::item>
 *
 * Renders the item ID, name, face-out variation ID and price, plus the
 * Sponsored Listing campaign ID and owner when the product is an ad. Use
 * <x-constructor::recommendation-item> inside a recommendations container.
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 * @see https://docs.constructor.com/docs/products-retail-media-learn-about-sponsored-listings-implement-sponsored-listings
 */
class Item extends TrackingComponent
{
    /**
     * @param  array<string, mixed>  $product  A product from a results DTO.
     */
    public function __construct(
        public array $product,
        string $tag = 'div',
    ) {
        parent::__construct($tag);
    }

    public function trackingAttributes(): array
    {
        return [
            ...self::itemAttributes($this->product),
            'data-cnstrc-sl-campaign-id' => self::text($this->product['sponsored_campaign_id'] ?? null),
            'data-cnstrc-sl-campaign-owner' => self::text($this->product['sponsored_campaign_owner'] ?? null),
        ];
    }
}
