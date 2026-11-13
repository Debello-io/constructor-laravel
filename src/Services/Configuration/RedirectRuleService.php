<?php

namespace ConstructorIO\Laravel\Services\Configuration;

/**
 * Redirect rules: send shoppers who search for "gift card" or "store hours"
 * straight to a page instead of a results list. Rules belong to the whole
 * index, so they take no section.
 *
 *     Constructor::configuration()->redirectRules()->create([
 *         'url' => '/gift-cards',
 *         'matches' => [['pattern' => 'gift card', 'match_type' => 'PHRASE']],
 *     ]);
 *
 * A rule has `url` (1-1,000 characters), `matches` (1-300 of
 * ['pattern' => ..., 'match_type' => 'EXACT'|'PHRASE'|'UNORDERED']) and
 * optional `start_time`, `end_time`, `user_segments` and `metadata`.
 *
 * @see https://docs.constructor.com/reference/v1-redirects-retrieve-redirect-rules
 */
class RedirectRuleService extends ConfigurationResource
{
    /** Constructor returns at most 100 redirect rules per page. */
    private const MAX_PER_PAGE = 100;

    /**
     * Options: ids (int or list), query (search the rules), status
     * (current|pending|expired), sort_order (ascending|descending), page,
     * per_page (1-100, default 20), offset.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> ['redirect_rules' => [...], 'total_count' => int]
     */
    public function list(array $options = []): array
    {
        $response = $this->read('/v1/redirect_rules', array_merge([
            'id' => isset($options['ids']) ? array_values(array_map('intval', (array) $options['ids'])) : null,
            'query' => $options['query'] ?? null,
            'status' => $options['status'] ?? null,
            'sort_order' => $options['sort_order'] ?? null,
        ], $this->paging($options, self::MAX_PER_PAGE)));

        return $this->listResponse($response, 'redirect_rules');
    }

    /**
     * @return array<string, mixed>|null Null if there is no rule with this ID.
     */
    public function get(int|string $ruleId): ?array
    {
        return $this->find($this->path($ruleId));
    }

    /**
     * @param  array<string, mixed>  $rule  url and matches are required.
     * @return array<string, mixed> The created rule, with its `id`.
     */
    public function create(array $rule): array
    {
        return $this->write('POST', '/v1/redirect_rules', [], $rule);
    }

    /**
     * Overwrite a rule. Fields you leave out are reset.
     *
     * @param  array<string, mixed>  $rule  url and matches are required.
     * @return array<string, mixed>
     *
     * @see https://docs.constructor.com/reference/v1-redirects-replace-redirect-rule
     */
    public function replace(int|string $ruleId, array $rule): array
    {
        return $this->write('PUT', $this->path($ruleId), [], $rule);
    }

    /**
     * Change only the fields you pass.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function update(int|string $ruleId, array $changes): array
    {
        return $this->write('PATCH', $this->path($ruleId), [], $changes);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int|string $ruleId): array
    {
        return $this->write('DELETE', $this->path($ruleId));
    }

    private function path(int|string $ruleId): string
    {
        return '/v1/redirect_rules/'.$this->numericId($ruleId, 'redirect rule');
    }
}
