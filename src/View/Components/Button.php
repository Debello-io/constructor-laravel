<?php

namespace ConstructorIO\Laravel\View\Components;

/**
 * A conversion button inside a result item or product detail view:
 *
 *     <x-constructor::button action="add_to_cart" class="btn">Add to cart</x-constructor::button>
 *
 * Renders data-cnstrc-btn with the conversion type. Constructor's types are
 * add_to_cart, add_to_wishlist, like, message, make_offer and read; any other
 * value is recorded as a custom conversion. It is a type="button" <button>
 * unless you pass another tag or type (for example type="submit" in a form).
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
class Button extends TrackingComponent
{
    public function __construct(
        public string $action = 'add_to_cart',
        string $tag = 'button',
    ) {
        parent::__construct($tag);
    }

    public function trackingAttributes(): array
    {
        return [
            'type' => $this->tag === 'button' ? 'button' : null,
            'data-cnstrc-btn' => self::text($this->action) ?? 'add_to_cart',
        ];
    }
}
