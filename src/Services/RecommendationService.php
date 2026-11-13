<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Concerns\HandlesStorefrontFailures;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationResults;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\ParameterBuilder;
use ConstructorIO\Laravel\Support\ResultTransformer;

/**
 * Recommendation pods: "Best sellers", "Similar items", "Frequently bought
 * together" and so on. Pods are created in the Constructor dashboard; you
 * reference them by ID.
 *
 *     Constructor::getRecommendations('home-bestsellers', ['num_results' => 8]);
 *     Constructor::getItemRecommendations('pdp-complementary', 'GRILL-123');
 *
 * @see https://docs.constructor.com/reference/v1-recommendations-get-pod-results
 */
class RecommendationService
{
    use HandlesStorefrontFailures;

    /** Constructor returns at most 100 results per pod request. */
    private const MAX_RESULTS = 100;

    protected readonly ConstructorConfig $config;

    public function __construct(
        protected readonly ApiClient $api,
        protected readonly ResultTransformer $transformer,
    ) {
        $this->config = $api->config();
    }

    /**
     * Results from a pod.
     *
     * Options:
     *   num_results   int           How many items (default 8)
     *   item_id       string|list   Item(s) the pod is about. Required by item-based
     *                               strategies (alternative_items, complementary_items, bundles)
     *   variation_id  string        Variation of item_id
     *   term          string        Search term. Required by query_recommendations pods
     *   filters       array         ['brand' => ['Weber']]. Required by filtered_items pods
     *   section       string        Index section
     * plus pre_filter_expression, fmt_options, hidden_fields, variations_map,
     * user_segments, parameters and identity overrides (see ParameterBuilder).
     *
     * @param  array<string, mixed>  $options
     */
    public function getRecommendations(string $podId, array $options = []): RecommendationResults
    {
        return $this->attempt('recommendations', function () use ($podId, $options) {
            $parameters = array_merge(
                ['num_results' => min(self::MAX_RESULTS, max(1, (int) ($options['num_results'] ?? 8)))],
                array_filter([
                    'section' => $options['section'] ?? null,
                    'item_id' => isset($options['item_id']) ? array_values(array_map('strval', (array) $options['item_id'])) : null,
                    'variation_id' => $options['variation_id'] ?? null,
                    'term' => $options['term'] ?? null,
                ], fn ($value) => $value !== null && $value !== '' && $value !== []),
                ParameterBuilder::shared($options['filters'] ?? [], $options),
            );

            $response = $this->api->getStorefront(
                '/recommendations/v1/pods/'.rawurlencode($podId),
                $parameters,
                ParameterBuilder::identity($options),
            );

            return $this->toRecommendationResults($podId, $response);
        }, fn () => RecommendationResults::empty($podId));
    }

    /**
     * Results from an item-based pod, for a product page or cart.
     *
     * @param  string|list<string>  $itemId  One item, or several (e.g. everything in the cart).
     * @param  array<string, mixed>  $options  Same as getRecommendations().
     */
    public function getItemRecommendations(string $podId, string|array $itemId, array $options = []): RecommendationResults
    {
        return $this->getRecommendations($podId, ['item_id' => $itemId] + $options);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function toRecommendationResults(string $podId, array $response): RecommendationResults
    {
        $body = $response['response'] ?? [];
        $pod = $body['pod'] ?? [];
        $products = $this->transformer->products($body['results'] ?? []);

        return new RecommendationResults(
            podId: (string) ($pod['id'] ?? $podId),
            title: (string) ($pod['display_name'] ?? ''),
            products: $products,
            total: (int) ($body['total_num_results'] ?? count($products)),
            metadata: [
                'request_id' => $response['request']['request_id'] ?? null,
                'result_id' => $response['result_id'] ?? null,
                'pod' => $pod,
                'experiments' => $body['experiments'] ?? $response['experiments'] ?? [],
            ],
            resultId: $response['result_id'] ?? null,
        );
    }
}
