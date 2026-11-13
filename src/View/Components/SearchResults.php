<?php

namespace ConstructorIO\Laravel\View\Components;

use ConstructorIO\Laravel\DataTransferObjects\SearchResults as SearchResultsDto;

/**
 * The container around a search results list:
 *
 *     <x-constructor::search-results :results="$results" :term="$query" class="grid">
 *         <x-constructor::item :product="$product">...</x-constructor::item>  (one per product)
 *     </x-constructor::search-results>
 *
 * Renders data-cnstrc-search with the term, section, result ID, result count
 * and page, then the cnstrc-data script tag the beacon also needs on search
 * pages. Pass :with-tracking-data="false" if you print that tag yourself
 * (with <x-constructor::tracking-data>).
 *
 * The term is a prop rather than read from the response, because Constructor
 * documents the response's `request` echo as being for debugging only.
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
class SearchResults extends TrackingComponent
{
    public function __construct(
        public SearchResultsDto $results,
        public string $term,
        public ?string $section = null,
        public bool $withTrackingData = true,
        string $tag = 'div',
    ) {
        parent::__construct($tag);
    }

    public function trackingAttributes(): array
    {
        return [
            'data-cnstrc-search' => '',
            'data-cnstrc-search-term' => trim($this->term),
            ...self::resultsListAttributes($this->results, $this->section),
        ];
    }

    public function trackingData(): ?string
    {
        return $this->withTrackingData ? TrackingData::json($this->results) : null;
    }
}
