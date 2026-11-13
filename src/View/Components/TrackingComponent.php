<?php

namespace ConstructorIO\Laravel\View\Components;

use ConstructorIO\Laravel\DataTransferObjects\SearchResults as SearchResultsDto;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use Illuminate\View\Component;

/**
 * Shared behaviour for the `<x-constructor::...>` tracking components.
 *
 * Each component renders one element carrying the data-cnstrc-* attributes
 * Constructor's beacon reads, around your own markup (the slot). Attributes
 * you pass are merged in: `class` is appended, and any data-cnstrc-*
 * attribute you set replaces the computed one (for example, the variation
 * ID after a swatch change). Every value is HTML-escaped.
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
abstract class TrackingComponent extends Component
{
    /** The HTML element to render. */
    public string $tag;

    public function __construct(string $tag = 'div')
    {
        // The tag is printed as an element name, where escaping can't help,
        // so only plain element names are allowed (div, li, my-element...).
        if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $tag) !== 1) {
            throw new InvalidArgumentException("\"{$tag}\" isn't a valid HTML element name for a Constructor tracking component.");
        }

        $this->tag = $tag;
    }

    /**
     * The data-cnstrc-* attributes for this element. Null values are left
     * out; an empty string renders a marker such as data-cnstrc-search="".
     *
     * @return array<string, string|null>
     */
    abstract public function trackingAttributes(): array;

    /**
     * JSON for a cnstrc-data script tag printed after the element, or null
     * for none. Only the search and browse containers have one.
     */
    public function trackingData(): ?string
    {
        return null;
    }

    /**
     * The tracking attributes without the omitted (null) ones, ready for
     * $attributes->merge(), which escapes them.
     *
     * @return array<string, string>
     */
    public function presentAttributes(): array
    {
        return array_filter($this->trackingAttributes(), fn (?string $value) => $value !== null);
    }

    /**
     * The package view this component renders (resources/views/components).
     */
    public function render(): string
    {
        return 'constructor::components.tracking-element';
    }

    /**
     * A scalar as an attribute value. Null (attribute omitted) for an empty
     * string or anything that isn't a scalar.
     */
    protected static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * The price the beacon expects: the lower of the sale price and the list
     * price, as a plain number. Null when the item has no price: Constructor
     * asks for the attribute to be left out, never sent empty or as 0.
     *
     * @param  array<string, mixed>  $product
     */
    protected static function price(array $product): ?string
    {
        $prices = [];
        foreach (['price', 'original_price'] as $field) {
            $price = $product[$field] ?? null;
            if (is_numeric($price) && (float) $price > 0) {
                $prices[] = (float) $price;
            }
        }

        return $prices === [] ? null : (string) min($prices);
    }

    /**
     * The attributes every result item and product detail view shares.
     *
     * @param  array<string, mixed>  $product  A product from a results DTO, or any array with `id` and `name`.
     * @return array<string, string|null>
     */
    protected static function itemAttributes(array $product): array
    {
        return [
            'data-cnstrc-item-id' => self::text($product['id'] ?? null),
            'data-cnstrc-item-name' => self::text($product['name'] ?? $product['value'] ?? null),
            // Only when a face-out variation is shown. Pass the attribute
            // yourself when the shopper picks another swatch.
            'data-cnstrc-item-variation-id' => self::text($product['variation_id'] ?? null),
            'data-cnstrc-item-price' => self::price($product),
        ];
    }

    /**
     * The attributes the search and browse containers share. The section is
     * always included (Constructor asks for it even on product results), and
     * the result count is 0 on a page with no results.
     *
     * @return array<string, string|null>
     */
    protected static function resultsListAttributes(SearchResultsDto $results, ?string $section): array
    {
        return [
            'data-cnstrc-section' => self::text($section) ?? app(ConstructorConfig::class)->defaultSection,
            'data-cnstrc-result-id' => self::text($results->resultId),
            'data-cnstrc-num-results' => self::count($results->total),
            'data-cnstrc-result-page' => self::count($results->page),
        ];
    }

    /**
     * A count as an attribute value.
     */
    protected static function count(int $count): string
    {
        return (string) max(0, $count);
    }
}
