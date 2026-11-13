<?php

namespace ConstructorIO\Laravel\DataTransferObjects;

/**
 * Results from getRecommendationPage(): every pod configured on one
 * recommendation page, fetched in a single request.
 *
 *     $page = Constructor::getRecommendationPage('pdp', ['item_id' => 'GRILL-123']);
 *
 *     foreach ($page->pods as $pod) {           // in the order set in the dashboard
 *         echo $pod->title;
 *     }
 *
 *     $accessories = $page->pod('pdp-complementary');   // or look one up by ID
 *
 * Constructor removes duplicates across the page's pods, so a product appears
 * in at most one pod (unless it is slotted).
 *
 * Tracking: each pod is a separate recommendations container for the beacon.
 * Use each pod's own $pod->resultId on its container. The page's $resultId
 * identifies the page request only; it is not a beacon ID.
 */
class RecommendationPageResults
{
    /**
     * @param  string  $pageId  The page that was requested.
     * @param  string  $title  The page's display name from the dashboard.
     * @param  string  $pageType  The page's configured type, such as "pdp" or "home".
     * @param  list<RecommendationResults>  $pods  Every pod on the page, including empty ones, in the configured order.
     * @param  array<string, mixed>  $metadata  request, request_id, result_id and experiments (shared by every pod).
     * @param  string|null  $resultId  Identifies the page request. Not for tracking: use each pod's resultId.
     */
    public function __construct(
        public readonly string $pageId,
        public readonly string $title,
        public readonly string $pageType,
        public readonly array $pods,
        public readonly array $metadata = [],
        public readonly ?string $resultId = null,
    ) {}

    /**
     * The pod with this ID, or null if the page doesn't have it.
     */
    public function pod(string $podId): ?RecommendationResults
    {
        foreach ($this->pods as $pod) {
            if ($pod->podId === $podId) {
                return $pod;
            }
        }

        return null;
    }

    /**
     * Only the pods that returned products, in page order. Handy for
     * rendering without empty headings.
     *
     * @return list<RecommendationResults>
     */
    public function podsWithResults(): array
    {
        return array_values(array_filter($this->pods, fn (RecommendationResults $pod) => $pod->hasRecommendations()));
    }

    /**
     * True when no pod returned any products.
     */
    public function isEmpty(): bool
    {
        return $this->podsWithResults() === [];
    }

    public function hasRecommendations(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * @return array{page_id: string, title: string, page_type: string, pods: list<array<string, mixed>>, metadata: array<string, mixed>, result_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'page_id' => $this->pageId,
            'title' => $this->title,
            'page_type' => $this->pageType,
            'pods' => array_map(fn (RecommendationResults $pod) => $pod->toArray(), $this->pods),
            'metadata' => $this->metadata,
            'result_id' => $this->resultId,
        ];
    }

    public static function empty(string $pageId = ''): self
    {
        return new self(pageId: $pageId, title: '', pageType: '', pods: []);
    }
}
