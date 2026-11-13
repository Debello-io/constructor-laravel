<?php

namespace ConstructorIO\Laravel\Services\Configuration;

use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;

/**
 * Synonym groups: phrases that mean the same thing in both directions, such
 * as "bbq", "barbecue" and "grill". A search for any of them matches all.
 * For one-way synonyms ("smoker" should also find "pellet grill", but not
 * the reverse), use OneWaySynonymService.
 *
 *     $groupId = Constructor::configuration()->synonymGroups()->create(['bbq', 'barbecue', 'grill']);
 *
 * @see https://docs.constructor.com/reference/v1-synonyms-list-synonyms
 */
class SynonymGroupService extends ConfigurationResource
{
    /** Constructor returns at most 1,000 groups per page. */
    private const MAX_PER_PAGE = 1000;

    /** A group holds 1-20 phrases of up to 200 characters. */
    private const MAX_PHRASES = 20;

    /**
     * Options: section, ids (list of group IDs), phrase (groups containing
     * it), sort_by (id|created_at|updated_at), sort_order, page, per_page
     * (1-1,000, default 20), offset.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> ['synonym_groups' => [...], 'total_count' => int]
     */
    public function list(array $options = []): array
    {
        $response = $this->read('/v1/synonym_groups', array_merge(
            $this->section($options['section'] ?? null),
            [
                'synonym_group_id' => isset($options['ids']) ? array_values(array_map('intval', (array) $options['ids'])) : null,
                'phrase' => $options['phrase'] ?? null,
                'sort_by' => $options['sort_by'] ?? null,
                'sort_order' => $options['sort_order'] ?? null,
            ],
            $this->paging($options, self::MAX_PER_PAGE),
        ));

        return $this->listResponse($response, 'synonym_groups');
    }

    /**
     * @return array<string, mixed>|null Null if there is no group with this ID.
     */
    public function get(int|string $groupId, ?string $section = null): ?array
    {
        return $this->find($this->path($groupId), $this->section($section));
    }

    /**
     * @param  list<string>  $synonyms  1-20 phrases.
     * @return int The new group's ID.
     */
    public function create(array $synonyms, ?string $section = null): int
    {
        $response = $this->write('POST', '/v1/synonym_groups', $this->section($section), [
            'synonyms' => $this->phrases($synonyms),
        ]);

        return (int) ($response['group_id'] ?? 0);
    }

    /**
     * Replace the group's phrases with these.
     *
     * @param  list<string>  $synonyms  1-20 phrases.
     */
    public function replace(int|string $groupId, array $synonyms, ?string $section = null): void
    {
        $this->write('PUT', $this->path($groupId), $this->section($section), [
            'synonyms' => $this->phrases($synonyms),
        ]);
    }

    public function delete(int|string $groupId, ?string $section = null): void
    {
        $this->write('DELETE', $this->path($groupId), $this->section($section));
    }

    /**
     * Delete **every** synonym group in the section. Constructor does this in
     * a background task.
     *
     * @return array<string, mixed> Constructor's response (a message).
     */
    public function deleteAll(?string $section = null): array
    {
        return $this->write('DELETE', '/v1/synonym_groups', $this->section($section));
    }

    private function path(int|string $groupId): string
    {
        return '/v1/synonym_groups/'.$this->numericId($groupId, 'synonym group');
    }

    /**
     * @param  array<mixed>  $synonyms
     * @return list<string>
     *
     * @throws InvalidArgumentException When the list is empty or too long.
     */
    private function phrases(array $synonyms): array
    {
        $phrases = array_values(array_filter(
            array_map(fn ($phrase) => is_scalar($phrase) ? trim((string) $phrase) : '', $synonyms),
            fn (string $phrase) => $phrase !== '',
        ));

        if ($phrases === [] || count($phrases) > self::MAX_PHRASES) {
            throw new InvalidArgumentException('A synonym group needs 1 to '.self::MAX_PHRASES.' phrases.');
        }

        return $phrases;
    }
}
