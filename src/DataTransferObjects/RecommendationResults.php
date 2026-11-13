<?php

namespace ConstructorIO\Laravel\DataTransferObjects;

/**
 * Results from getRecommendations() and getItemRecommendations().
 *
 *     $recommendations = Constructor::getItemRecommendations('pdp-complementary', 'GRILL-123');
 *
 *     if ($recommendations->hasRecommendations()) {
 *         echo $recommendations->title;        // Pod display name from the dashboard
 *         foreach ($recommendations->products as $product) {
 *             echo $product['name'];
 *         }
 *     }
 *
 * Pass $recommendations->resultId to the page so the beacon can attribute
 * recommendation views and clicks.
 */
class RecommendationResults
{
    /**
     * @param  string  $podId  The pod that was requested.
     * @param  string  $title  The pod's display name.
     * @param  list<array<string, mixed>>  $products  Recommended products (see ResultTransformer::product()).
     * @param  int  $total  Total recommendations available.
     * @param  array<string, mixed>  $metadata  request_id, result_id, the full pod object and experiments.
     * @param  string|null  $resultId  Constructor's ID for this response, needed for tracking.
     */
    public function __construct(
        public readonly string $podId,
        public readonly string $title,
        public readonly array $products,
        public readonly int $total = 0,
        public readonly array $metadata = [],
        public readonly ?string $resultId = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->products === [];
    }

    public function hasRecommendations(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Number of products returned.
     */
    public function count(): int
    {
        return count($this->products);
    }

    /**
     * @return array{pod_id: string, title: string, products: list<array<string, mixed>>, total: int, metadata: array<string, mixed>, result_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'pod_id' => $this->podId,
            'title' => $this->title,
            'products' => $this->products,
            'total' => $this->total,
            'metadata' => $this->metadata,
            'result_id' => $this->resultId,
        ];
    }

    public static function empty(string $podId = '', string $title = ''): self
    {
        return new self(podId: $podId, title: $title, products: []);
    }
}
