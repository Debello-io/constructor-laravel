<?php

namespace ConstructorIO\Laravel\Scout;

use Illuminate\Database\Eloquent\Collection;

/**
 * Models returned by a Scout search, plus Constructor's response details.
 *
 * It is an ordinary Eloquent collection in Constructor's ranking order, so
 * existing Scout code keeps working. The extra getters expose what a results
 * page usually needs next to the models:
 *
 *     $products = Product::search('pellet grill')->get();
 *
 *     $products->getTotal();        // matches across all pages
 *     $products->getFacets();       // for the filter sidebar
 *     $products->getSortOptions();  // sort options configured in the dashboard
 *     $products->getResultId();     // pass to the page for beacon tracking
 *
 * @template TKey of array-key
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Collection<TKey, TModel>
 */
class SearchResultCollection extends Collection
{
    /** Total matches across all pages. */
    protected int $total = 0;

    /** @var array<string, array<string, mixed>> Facets keyed by name (see ResultTransformer::facets()). */
    protected array $facets = [];

    /** @var list<array<string, mixed>> Sort options configured in the dashboard. */
    protected array $sortOptions = [];

    /** Constructor's ID for the response, needed by the beacon's results-loaded event. */
    protected ?string $resultId = null;

    public function setTotal(int $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @param  array<string, array<string, mixed>>  $facets
     */
    public function setFacets(array $facets): static
    {
        $this->facets = $facets;

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getFacets(): array
    {
        return $this->facets;
    }

    /**
     * @param  list<array<string, mixed>>  $sortOptions
     */
    public function setSortOptions(array $sortOptions): static
    {
        $this->sortOptions = $sortOptions;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSortOptions(): array
    {
        return $this->sortOptions;
    }

    public function setResultId(?string $resultId): static
    {
        $this->resultId = $resultId;

        return $this;
    }

    public function getResultId(): ?string
    {
        return $this->resultId;
    }
}
