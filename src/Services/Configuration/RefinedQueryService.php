<?php

namespace ConstructorIO\Laravel\Services\Configuration;

use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;

/**
 * Searchandising for search ("refined queries"): merchandising rules for one
 * search query, such as pinning a product to a position (slot_rules),
 * boosting or burying items (boost_rules, -10 to 10), hiding items
 * (blacklist_rules), limiting results to a filter (whitelist_rule) or
 * attaching banner content (content_rules).
 *
 *     Constructor::configuration()->refinedQueries()->create([
 *         'query' => 'smoker',
 *         'slot_rules' => [['rule' => ['item_id' => 'TRG-780', 'position' => 1]]],
 *         'boost_rules' => [['rule' => ['filters' => ['brand' => ['Traeger']], 'boost' => 5]]],
 *     ]);
 *
 * Every method takes `response_section`: `results` (the default) for rules
 * on products, or `facets` for rules that slot or hide facets for the query.
 *
 * @see https://docs.constructor.com/reference/v1-searchandising-create-refined-query
 */
class RefinedQueryService extends ConfigurationResource
{
    private const RESPONSE_SECTIONS = ['results', 'facets'];

    /** Constructor returns at most 100 refined queries per page. */
    private const MAX_PER_PAGE = 100;

    /** The `query` filter accepts at most 100 queries. */
    private const MAX_QUERY_FILTERS = 100;

    /**
     * Options:
     *   section, response_section (results|facets)
     *   queries     list    Only these exact queries (1-100)
     *   phrase      string  Queries containing this phrase
     *   sort_by     string  id (default), created_at, updated_at,
     *                       last_updated_internally, last_updated_externally
     *   sort_order  string  ascending (default) or descending
     *   page, per_page (1-100, default 20), offset
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> ['refined_queries' => [...], 'total_count' => int]
     *
     * @see https://docs.constructor.com/reference/v1-searchandising-retrieve-refined-queries
     */
    public function list(array $options = []): array
    {
        $queries = isset($options['queries']) ? array_values(array_map('strval', (array) $options['queries'])) : null;

        if ($queries !== null && ($queries === [] || count($queries) > self::MAX_QUERY_FILTERS)) {
            throw new InvalidArgumentException('The queries filter takes 1 to '.self::MAX_QUERY_FILTERS.' queries.');
        }

        $response = $this->read('/v1/refined_queries', array_merge(
            $this->ruleQuery($options),
            [
                'query' => $queries,
                'phrase' => $options['phrase'] ?? null,
                'sort_by' => $options['sort_by'] ?? null,
                'sort_order' => $options['sort_order'] ?? null,
            ],
            $this->paging($options, self::MAX_PER_PAGE),
        ));

        return $this->listResponse($response, 'refined_queries');
    }

    /**
     * @param  array<string, mixed>  $options  section, response_section
     * @return array<string, mixed>|null Null if there is no refined query with this ID.
     */
    public function get(int|string $refinedQueryId, array $options = []): ?array
    {
        return $this->find($this->path($refinedQueryId), $this->ruleQuery($options));
    }

    /**
     * @param  array<string, mixed>  $refinedQuery  `query` is required, plus the rules.
     * @param  array<string, mixed>  $options  section, response_section
     * @return array<string, mixed> The created refined query, with its `id`.
     */
    public function create(array $refinedQuery, array $options = []): array
    {
        return $this->write('POST', '/v1/refined_queries', $this->ruleQuery($options), $refinedQuery);
    }

    /**
     * Overwrite a refined query and all of its rules.
     *
     * @param  array<string, mixed>  $refinedQuery
     * @param  array<string, mixed>  $options  section, response_section
     * @return array<string, mixed>
     */
    public function replace(int|string $refinedQueryId, array $refinedQuery, array $options = []): array
    {
        return $this->write('PUT', $this->path($refinedQueryId), $this->ruleQuery($options), $refinedQuery);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $options  section, response_section
     * @return array<string, mixed>
     */
    public function update(int|string $refinedQueryId, array $changes, array $options = []): array
    {
        return $this->write('PATCH', $this->path($refinedQueryId), $this->ruleQuery($options), $changes);
    }

    /**
     * @param  array<string, mixed>  $options  section, response_section
     * @return array<string, mixed>
     */
    public function delete(int|string $refinedQueryId, array $options = []): array
    {
        return $this->write('DELETE', $this->path($refinedQueryId), $this->ruleQuery($options));
    }

    private function path(int|string $refinedQueryId): string
    {
        return '/v1/refined_queries/'.$this->numericId($refinedQueryId, 'refined query');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException For a response_section other than results or facets.
     */
    private function ruleQuery(array $options): array
    {
        $responseSection = $options['response_section'] ?? null;

        if ($responseSection !== null && ! in_array($responseSection, self::RESPONSE_SECTIONS, true)) {
            throw new InvalidArgumentException("response_section must be 'results' or 'facets'.");
        }

        return $this->section($options['section'] ?? null) + ['response_section' => $responseSection];
    }
}
