<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Concerns\HandlesStorefrontFailures;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationPageResults;
use ConstructorIO\Laravel\DataTransferObjects\RecommendationResults;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Http\Path;
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
 *     Constructor::getRecommendationPage('pdp', ['item_id' => 'GRILL-123']);
 *
 * @see https://docs.constructor.com/reference/v1-recommendations-get-pod-results
 * @see https://docs.constructor.com/reference/v1-recommendations-get-page-results
 */
class RecommendationService
{
    use HandlesStorefrontFailures;

    /** Constructor returns at most 100 results per pod request. */
    private const MAX_RESULTS = 100;

    /**
     * The only parameters a page request accepts per pod (`pod_overrides`).
     * Everything else is page-wide, and Constructor answers 400 if an
     * override contains it, so these are checked before sending.
     * `range_filters` and `hidden_fields` are this package's shortcuts for
     * `filters` and `fmt_options`.
     */
    private const POD_OVERRIDE_OPTIONS = [
        'num_results', 'filters', 'range_filters', 'filter_match_types',
        'pre_filter_expression', 'fmt_options', 'hidden_fields', 'variations_map',
    ];

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
                ['num_results' => $this->clampNumResults($options['num_results'] ?? 8, 1)],
                array_filter([
                    'section' => $options['section'] ?? null,
                    'item_id' => isset($options['item_id']) ? array_values(array_map('strval', (array) $options['item_id'])) : null,
                    'variation_id' => $options['variation_id'] ?? null,
                    'term' => $options['term'] ?? null,
                ], fn ($value) => $value !== null && $value !== '' && $value !== []),
                ParameterBuilder::shared($options['filters'] ?? [], $options),
            );

            $response = $this->api->getStorefront(
                '/recommendations/v1/pods/'.Path::segment($podId),
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
     * Results for every pod on a recommendation page, in one request.
     *
     * A page groups pods in the dashboard (for example a product page with
     * "Similar items", "Complete your cookout" and "Recently viewed").
     * Constructor removes duplicates across the pods, so a product shows up
     * in only one of them. The endpoint is in beta: ask your Constructor
     * contact to enable it.
     *
     * Options apply to every pod on the page:
     *   item_id       string|list   Seed item(s) for item-based pods
     *   variation_id  string        Variation of a single item_id
     *   term          string        For query_recommendations pods
     *   filters       array         ['brand' => ['Weber']], for filtered_items pods
     *   num_results   int           Items per pod (0-100). Omit to use Constructor's default.
     *   section       string        Index section
     *   pod_overrides array         Per-pod settings, keyed by pod ID:
     *                 ['pdp-similar' => ['num_results' => 12, 'filters' => ['in_stock' => true]]]
     *                 Allowed keys: num_results, filters, range_filters,
     *                 filter_match_types, pre_filter_expression, fmt_options,
     *                 hidden_fields, variations_map. An override replaces the
     *                 page-wide value for that pod; it isn't merged, so repeat
     *                 any page-wide filter the pod still needs.
     * plus range_filters, filter_match_types, pre_filter_expression,
     * fmt_options, hidden_fields, variations_map, user_segments, parameters
     * and identity overrides (see ParameterBuilder).
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException If pod_overrides contains a setting Constructor only accepts page-wide.
     */
    public function getPage(string $pageId, array $options = []): RecommendationPageResults
    {
        // Checked outside attempt(): a bad override is a coding mistake, so it
        // shouldn't be logged away as an outage.
        $podOverrides = $this->podOverrides($options['pod_overrides'] ?? []);

        return $this->attempt('recommendation page', function () use ($pageId, $options, $podOverrides) {
            $parameters = array_merge(
                array_filter([
                    'section' => $options['section'] ?? null,
                    'item_id' => isset($options['item_id']) ? array_values(array_map('strval', (array) $options['item_id'])) : null,
                    'variation_id' => $options['variation_id'] ?? null,
                    'term' => $options['term'] ?? null,
                    'num_results' => isset($options['num_results']) ? $this->clampNumResults($options['num_results'], 0) : null,
                    'pod_overrides' => $podOverrides,
                ], fn ($value) => $value !== null && $value !== '' && $value !== []),
                ParameterBuilder::shared($options['filters'] ?? [], $options),
            );

            $response = $this->api->getStorefront(
                '/recommendations/v1/pages/'.Path::segment($pageId),
                $parameters,
                ParameterBuilder::identity($options),
            );

            return $this->toRecommendationPageResults($pageId, $response);
        }, fn () => RecommendationPageResults::empty($pageId));
    }

    /**
     * Turn friendly per-pod options into the `pod_overrides` parameter,
     * sent in bracket notation: pod_overrides[pdp-similar][num_results]=12.
     *
     * @param  mixed  $overrides  ['pod-id' => [option => value, ...]]
     * @return array<string, array<string, mixed>>
     */
    private function podOverrides(mixed $overrides): array
    {
        if (! is_array($overrides)) {
            throw new InvalidArgumentException('pod_overrides must be an array keyed by pod ID.');
        }

        $parameter = [];

        foreach ($overrides as $podId => $override) {
            $podId = (string) $podId;

            // The pod ID becomes part of a bracketed parameter name, where a
            // bracket would change which parameter Constructor reads.
            if ($podId === '' || strpbrk($podId, '[]') !== false || ! is_array($override)) {
                throw new InvalidArgumentException('pod_overrides must map pod IDs (without brackets) to arrays of settings.');
            }

            $unsupported = array_diff(array_keys($override), self::POD_OVERRIDE_OPTIONS);
            if ($unsupported !== []) {
                throw new InvalidArgumentException(sprintf(
                    'pod_overrides for "%s" can only set %s. Pass %s page-wide instead.',
                    $podId,
                    implode(', ', self::POD_OVERRIDE_OPTIONS),
                    implode(', ', $unsupported),
                ));
            }

            $podParameters = ParameterBuilder::shared($override['filters'] ?? [], $override);
            if (isset($override['num_results'])) {
                $podParameters['num_results'] = $this->clampNumResults($override['num_results'], 0);
            }

            if ($podParameters !== []) {
                $parameter[$podId] = $podParameters;
            }
        }

        return $parameter;
    }

    private function clampNumResults(mixed $value, int $minimum): int
    {
        return min(self::MAX_RESULTS, max($minimum, (int) $value));
    }

    /**
     * Each element of response.pods has the same shape as a single-pod
     * response, plus its own result_id, so it maps to a RecommendationResults.
     *
     * @param  array<string, mixed>  $response
     */
    protected function toRecommendationPageResults(string $pageId, array $response): RecommendationPageResults
    {
        $body = is_array($response['response'] ?? null) ? $response['response'] : [];
        $metadata = $this->transformer->metadata($response);
        $pods = [];

        foreach ($body['pods'] ?? [] as $pod) {
            if (! is_array($pod)) {
                continue;
            }

            $podResponse = [
                'request' => $pod['request'] ?? null,
                'result_id' => $pod['result_id'] ?? null,
                // Experiments are sent once for the whole page, not per pod.
                'response' => (is_array($pod['response'] ?? null) ? $pod['response'] : []) + ['experiments' => $metadata['experiments']],
            ];

            $pods[] = $this->toRecommendationResults((string) ($pod['pod_id'] ?? ''), $podResponse);
        }

        return new RecommendationPageResults(
            pageId: (string) ($body['page_id'] ?? $pageId),
            title: (string) ($body['display_name'] ?? ''),
            pageType: (string) ($body['page_type'] ?? ''),
            pods: $pods,
            metadata: $metadata,
            resultId: $response['result_id'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function toRecommendationResults(string $podId, array $response): RecommendationResults
    {
        $body = $response['response'] ?? [];
        $pod = is_array($body['pod'] ?? null) ? $body['pod'] : [];
        $products = $this->transformer->products($body['results'] ?? []);

        return new RecommendationResults(
            podId: (string) ($pod['id'] ?? $podId),
            title: (string) ($pod['display_name'] ?? ''),
            products: $products,
            total: (int) ($body['total_num_results'] ?? count($products)),
            metadata: $this->transformer->metadata($response) + ['pod' => $pod],
            resultId: $response['result_id'] ?? null,
        );
    }
}
