<?php

namespace ConstructorIO\Laravel\Scout;

/**
 * Optional interface for Scout models indexed in Constructor.
 *
 * Neither method is required: without them the engine uses the configured
 * default section and builds the item from toSearchableArray(). Implement it
 * when you want full control:
 *
 *     class Product extends Model implements ConstructorSearchable
 *     {
 *         use Searchable;
 *
 *         public function getConstructorSection(): string
 *         {
 *             return 'Products';
 *         }
 *
 *         public function toConstructorItem(): array
 *         {
 *             return [
 *                 'name' => $this->name,
 *                 'data' => [
 *                     'url' => route('products.show', $this),
 *                     'image_url' => $this->image_url,
 *                     'price' => $this->price,
 *                     'group_ids' => $this->categories->pluck('slug')->all(),
 *                 ],
 *             ];
 *         }
 *     }
 *
 * The item ID is always the model's Scout key (getScoutKey()), so search
 * results can be matched back to your database rows. To index by SKU,
 * override getScoutKey() and getScoutKeyName() on the model.
 *
 * @see https://docs.constructor.com/reference/v2-items-create-or-replace-items
 */
interface ConstructorSearchable
{
    /**
     * The index section this model belongs to, e.g. "Products".
     *
     * Base it on the model class or its key only. Queued Scout deletes
     * restore models with just their key loaded, so a section computed from
     * other attributes would point the delete at the wrong section.
     */
    public function getConstructorSection(): string;

    /**
     * The item to send to Constructor: `name` (required), optionally
     * `suggested_score`, and catalog fields in `data`. Fields outside `data`
     * are moved into it for you. Any `id` is replaced by the Scout key.
     *
     * @return array<string, mixed>
     */
    public function toConstructorItem(): array;
}
