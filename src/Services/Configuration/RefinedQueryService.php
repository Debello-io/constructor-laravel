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

    /**
     * Options: section, response_section (results|facets), page, per_page,
     * offset, plus `parameters` for any other filter the endpoint accepts.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> Constructor's response.
     */
    public function list(array $options = []): array
    {
        // The reference doesn't state a page-size maximum for this endpoint,
        // so per_page is only kept at 1 or more.
        return $this->read('/v1/refined_queries', array_merge(
            $this->ruleQuery($options),
            $this->paging($options, PHP_INT_MAX),
            (array) ($options['parameters'] ?? []),
        ));
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
