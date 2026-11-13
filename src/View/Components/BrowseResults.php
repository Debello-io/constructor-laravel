<?php

namespace ConstructorIO\Laravel\View\Components;

use ConstructorIO\Laravel\DataTransferObjects\SearchResults as SearchResultsDto;

/**
 * The container around a browse (category, brand or collection page) results
 * list:
 *
 *     <x-constructor::browse-results :results="$results" filter-name="group_id" :filter-value="$category">
 *         ...
 *     </x-constructor::browse-results>
 *
 * Renders data-cnstrc-browse with the section, result ID, result count, page
 * and the browse filter, then the cnstrc-data script tag. The filter name and
 * value must be exactly what you passed to Constructor::browse() (for a
 * collection page: collection_id and the collection's ID).
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
class BrowseResults extends TrackingComponent
{
    public function __construct(
        public SearchResultsDto $results,
        public string $filterName,
        public string $filterValue,
        public ?string $section = null,
        public bool $withTrackingData = true,
        string $tag = 'div',
    ) {
        parent::__construct($tag);
    }

    public function trackingAttributes(): array
    {
        return [
            'data-cnstrc-browse' => '',
            ...self::resultsListAttributes($this->results, $this->section),
            'data-cnstrc-filter-name' => $this->filterName,
            'data-cnstrc-filter-value' => $this->filterValue,
        ];
    }

    public function trackingData(): ?string
    {
        return $this->withTrackingData ? TrackingData::json($this->results) : null;
    }
}
