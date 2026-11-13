<?php

namespace ConstructorIO\Laravel\Services\Configuration;

use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;

/**
 * One-way synonyms: a search for the parent phrase also matches its child
 * phrases, but not the other way round. "smoker" can find "pellet grill"
 * without every "pellet grill" search showing smokers. One-way synonyms
 * belong to the whole index, so they take no section.
 *
 *     Constructor::configuration()->oneWaySynonyms()->create('smoker', ['pellet grill', 'offset smoker']);
 *
 * @see https://docs.constructor.com/reference/v2-one-way-synonyms-retrieve-one-way-synonyms
 */
class OneWaySynonymService extends ConfigurationResource
{
    /** Constructor returns at most 1,000 relations per page. */
    private const MAX_PER_PAGE = 1000;

    /** A parent phrase has 1-50 child phrases. */
    private const MAX_CHILD_PHRASES = 50;

    /**
     * Options: parent_phrases (list), phrase, sort_by
     * (created_at|updated_at|activated_at), sort_order, page, per_page
     * (1-1,000, default 20), offset.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> ['one_way_synonym_relations' => [...], 'total_count' => int]
     */
    public function list(array $options = []): array
    {
        $response = $this->read('/v2/one_way_synonyms', array_merge([
            'parent_phrase' => isset($options['parent_phrases']) ? array_values(array_map('strval', (array) $options['parent_phrases'])) : null,
            'phrase' => $options['phrase'] ?? null,
            'sort_by' => $options['sort_by'] ?? null,
            'sort_order' => $options['sort_order'] ?? null,
        ], $this->paging($options, self::MAX_PER_PAGE)));

        return $this->listResponse($response, 'one_way_synonym_relations');
    }

    /**
     * @return array<string, mixed>|null ['parent_phrase' => ..., 'child_phrases' => [...]], or null if unknown.
     */
    public function get(string $parentPhrase): ?array
    {
        return $this->find($this->path($parentPhrase));
    }

    /**
     * @param  list<string>  $childPhrases  1-50 phrases.
     */
    public function create(string $parentPhrase, array $childPhrases): void
    {
        $this->write('POST', $this->path($parentPhrase), [], $this->body($childPhrases));
    }

    /**
     * Replace all of the parent phrase's child phrases with these.
     *
     * @param  list<string>  $childPhrases  1-50 phrases.
     */
    public function replace(string $parentPhrase, array $childPhrases): void
    {
        $this->write('PUT', $this->path($parentPhrase), [], $this->body($childPhrases));
    }

    public function delete(string $parentPhrase): void
    {
        $this->write('DELETE', $this->path($parentPhrase));
    }

    /**
     * Delete **every** one-way synonym in the index.
     *
     * @return array<string, mixed>
     */
    public function deleteAll(): array
    {
        return $this->write('DELETE', '/v2/one_way_synonyms');
    }

    private function path(string $parentPhrase): string
    {
        return '/v2/one_way_synonyms/'.$this->name($parentPhrase, 'parent phrase');
    }

    /**
     * Constructor expects [{"phrase": "..."}] objects. Accept plain strings.
     *
     * @param  array<mixed>  $childPhrases
     * @return array{child_phrases: list<array{phrase: string}>}
     *
     * @throws InvalidArgumentException When the list is empty or too long.
     */
    private function body(array $childPhrases): array
    {
        $phrases = array_values(array_filter(
            array_map(fn ($phrase) => is_scalar($phrase) ? trim((string) $phrase) : '', $childPhrases),
            fn (string $phrase) => $phrase !== '',
        ));

        if ($phrases === [] || count($phrases) > self::MAX_CHILD_PHRASES) {
            throw new InvalidArgumentException('A one-way synonym needs 1 to '.self::MAX_CHILD_PHRASES.' child phrases.');
        }

        return ['child_phrases' => array_map(fn (string $phrase) => ['phrase' => $phrase], $phrases)];
    }
}
