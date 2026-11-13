<?php

namespace ConstructorIO\Laravel\Support;

use ConstructorIO\Laravel\Exceptions\InvalidCatalogDataException;

/**
 * Shapes catalog records (items, variations, item groups) the way the
 * catalog APIs expect them.
 *
 * Every record type has a few fields that belong at the top level; anything
 * else must go inside `data`, or Constructor rejects the request
 * (`additionalProperties: false` in the API reference). These helpers let
 * callers pass flat arrays and move the extra fields into `data` for them:
 *
 *     ['id' => 'GRILL-123', 'name' => 'Pellet Grill', 'price' => 899]
 *     // becomes
 *     ['id' => 'GRILL-123', 'name' => 'Pellet Grill', 'data' => ['price' => 899]]
 *
 * @see https://docs.constructor.com/reference/v2-items-create-or-replace-items
 * @see https://docs.constructor.com/reference/v2-variations-create-or-replace-variations
 * @see https://docs.constructor.com/reference/v2-item-groups-create-or-replace-item-groups
 */
final class CatalogRecords
{
    private const ITEM_FIELDS = ['id', 'name', 'suggested_score', 'data'];

    private const VARIATION_FIELDS = ['id', 'item_id', 'name', 'suggested_score', 'data'];

    private const ITEM_GROUP_FIELDS = ['id', 'name', 'parent_ids', 'data'];

    /**
     * An item: `id` and `name` (required unless $requireName is false, as
     * for partial updates), optional `suggested_score`, everything else in `data`.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     *
     * @throws InvalidCatalogDataException
     */
    public static function item(array $item, bool $requireName = true): array
    {
        $id = self::requiredId($item, 'catalog item');
        $name = self::optionalString($item, 'name');

        if ($requireName && $name === null) {
            throw new InvalidCatalogDataException("Catalog item \"{$id}\" needs a non-empty \"name\".");
        }

        return self::withoutNulls([
            'id' => $id,
            'name' => $name,
            'suggested_score' => self::optionalInt($item, 'suggested_score'),
            'data' => self::data($item, self::ITEM_FIELDS),
        ]);
    }

    /**
     * A variation: `id` and the `item_id` of its parent item (required unless
     * $requireItemId is false, as for partial updates), optional `name` and
     * `suggested_score`, everything else in `data`.
     *
     * @param  array<string, mixed>  $variation
     * @return array<string, mixed>
     *
     * @throws InvalidCatalogDataException
     */
    public static function variation(array $variation, bool $requireItemId = true): array
    {
        $id = self::requiredId($variation, 'variation');
        $itemId = self::optionalString($variation, 'item_id');

        if ($requireItemId && $itemId === null) {
            throw new InvalidCatalogDataException("Variation \"{$id}\" needs a non-empty \"item_id\" (the ID of its parent item).");
        }

        return self::withoutNulls([
            'id' => $id,
            'item_id' => $itemId,
            'name' => self::optionalString($variation, 'name'),
            'suggested_score' => self::optionalInt($variation, 'suggested_score'),
            'data' => self::data($variation, self::VARIATION_FIELDS),
        ]);
    }

    /**
     * An item group (category): `id` and `name` (required unless $requireName
     * is false, as for partial updates), optional `parent_ids`, everything
     * else in `data`.
     *
     * An explicit empty `parent_ids` list is kept, so a partial update can
     * move a group to the top of the tree.
     *
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     *
     * @throws InvalidCatalogDataException
     */
    public static function itemGroup(array $group, bool $requireName = true): array
    {
        $id = self::requiredId($group, 'item group');
        $name = self::optionalString($group, 'name');

        if ($requireName && $name === null) {
            throw new InvalidCatalogDataException("Item group \"{$id}\" needs a non-empty \"name\".");
        }

        $parentIds = array_key_exists('parent_ids', $group)
            ? array_values(array_map('strval', (array) $group['parent_ids']))
            : null;

        return self::withoutNulls([
            'id' => $id,
            'name' => $name,
            'parent_ids' => $parentIds,
            'data' => self::data($group, self::ITEM_GROUP_FIELDS),
        ]);
    }

    /**
     * IDs in the `[{id: ...}]` shape the delete endpoints expect. Empty IDs
     * are dropped rather than sent.
     *
     * @param  array<array-key, string|int>  $ids
     * @return list<array{id: string}>
     */
    public static function ids(array $ids): array
    {
        $records = [];

        foreach ($ids as $id) {
            $id = trim((string) $id);

            if ($id !== '') {
                $records[] = ['id' => $id];
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $record
     *
     * @throws InvalidCatalogDataException
     */
    private static function requiredId(array $record, string $label): string
    {
        return self::optionalString($record, 'id')
            ?? throw new InvalidCatalogDataException("Every {$label} needs a non-empty \"id\".");
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function optionalString(array $record, string $field): ?string
    {
        $value = isset($record[$field]) ? trim((string) $record[$field]) : '';

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function optionalInt(array $record, string $field): ?int
    {
        return isset($record[$field]) ? (int) $record[$field] : null;
    }

    /**
     * Fields that aren't allowed at the top level, merged into `data`.
     * Values already inside `data` win over flat fields with the same name.
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $topLevelFields
     * @return array<string, mixed>|null
     */
    private static function data(array $record, array $topLevelFields): ?array
    {
        $extraFields = array_diff_key($record, array_flip($topLevelFields));
        $data = array_merge($extraFields, (array) ($record['data'] ?? []));

        return $data === [] ? null : $data;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $record): array
    {
        return array_filter($record, fn ($value) => $value !== null);
    }
}
