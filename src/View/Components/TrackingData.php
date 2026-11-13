<?php

namespace ConstructorIO\Laravel\View\Components;

use ConstructorIO\Laravel\DataTransferObjects\SearchResults as SearchResultsDto;
use Illuminate\View\Component;

/**
 * The cnstrc-data script tag for a search or browse page:
 *
 *     <x-constructor::tracking-data :results="$results" />
 *
 * It holds Constructor's `request` object and the `result_id`, as the beacon
 * expects. The search and browse containers print it for you; use this
 * component only when you turn that off or don't use the containers.
 *
 * Nothing is printed when the results didn't come from Constructor (no
 * result ID, for example after a failure fell back to empty results): the
 * docs say the tag belongs only on pages Constructor powers.
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
class TrackingData extends Component
{
    public function __construct(public SearchResultsDto $results) {}

    /**
     * The tag's JSON, safe to print inside <script>: <, >, & and quotes are
     * \u-escaped, so a search term such as "</script><script>..." can't end
     * the tag early. Null when there's nothing to track.
     */
    public static function json(SearchResultsDto $results): ?string
    {
        if ($results->resultId === null || $results->resultId === '') {
            return null;
        }

        return json_encode(
            $results->trackingData(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The JSON for this component's results, or null to print nothing.
     */
    public function payload(): ?string
    {
        return self::json($this->results);
    }

    /**
     * The package view this component renders (resources/views/components).
     */
    public function render(): string
    {
        return 'constructor::components.tracking-data';
    }
}
