<?php

namespace ConstructorIO\Laravel\DataTransferObjects;

/**
 * Results from search(), browse(), browseItems() and browseCollection().
 *
 *     $results = Constructor::search('pellet grill');
 *
 *     if ($results->isRedirect()) {
 *         return redirect($results->redirectUrl());
 *     }
 *
 *     foreach ($results->products as $product) {
 *         echo $product['name'], ' ', $product['price'];
 *     }
 *
 *     echo "Page {$results->page} of {$results->totalPages()}";
 *
 * Pass $results->resultId to the page (data-cnstrc-result-id) so the beacon's
 * "results loaded" event can be attributed to this response.
 *
 * @see https://docs.constructor.com/reference/constructor-concepts-results-response-structure
 */
class SearchResults
{
    /**
     * Constructor reports at most this many total results. Show "10,000+"
     * when total reaches it.
     */
    public const MAX_REPORTED_TOTAL = 10000;

    /**
     * @param  list<array<string, mixed>>  $products  Normalised products (see ResultTransformer::product()).
     * @param  int  $total  Total matching results across all pages.
     * @param  int  $page  Current page, starting at 1.
     * @param  int  $perPage  Results per page.
     * @param  array<string, array<string, mixed>>  $facets  Facets keyed by name (see ResultTransformer::facets()).
     * @param  array<string, mixed>  $metadata  request_id, result_id and other response details.
     * @param  list<array<string, mixed>>  $groups  Category tree for the results.
     * @param  string|null  $resultId  Constructor's ID for this response, needed for tracking.
     * @param  array<string, mixed>|null  $redirect  Set when a merchandising redirect rule matched: ['url' => ..., 'rule_id' => ..., 'matched_terms' => [...]].
     * @param  list<array<string, mixed>>  $sortOptions  Sort options configured in the dashboard.
     * @param  list<array<string, mixed>>  $refinedContent  Content rules (banners, messages) that matched.
     * @param  list<string>  $relatedSearches  Related search terms, when enabled.
     */
    public function __construct(
        public readonly array $products,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $facets = [],
        public readonly array $metadata = [],
        public readonly array $groups = [],
        public readonly ?string $resultId = null,
        public readonly ?array $redirect = null,
        public readonly array $sortOptions = [],
        public readonly array $refinedContent = [],
        public readonly array $relatedSearches = [],
    ) {}

    /**
     * True when Constructor says to send the shopper to another URL instead
     * of showing results (a redirect rule set up in the dashboard).
     */
    public function isRedirect(): bool
    {
        return $this->redirectUrl() !== null;
    }

    public function redirectUrl(): ?string
    {
        return $this->redirect['url'] ?? null;
    }

    public function hasMore(): bool
    {
        return ($this->page * $this->perPage) < $this->total;
    }

    public function totalPages(): int
    {
        if ($this->perPage <= 0) {
            return 0;
        }

        return (int) ceil($this->total / $this->perPage);
    }

    public function isEmpty(): bool
    {
        return $this->products === [];
    }

    /**
     * Number of products on this page.
     */
    public function count(): int
    {
        return count($this->products);
    }

    /**
     * Number of results before this page. For "Showing 25-48 of 156".
     */
    public function getOffset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * The next page number, or 0 on the last page.
     */
    public function nextPageNumber(): int
    {
        return $this->hasMore() ? $this->page + 1 : 0;
    }

    /**
     * True when Constructor capped the total (see MAX_REPORTED_TOTAL).
     */
    public function isTotalCapped(): bool
    {
        return $this->total >= self::MAX_REPORTED_TOTAL;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'products' => $this->products,
            'total' => $this->total,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'facets' => $this->facets,
            'groups' => $this->groups,
            'metadata' => $this->metadata,
            'result_id' => $this->resultId,
            'redirect' => $this->redirect,
            'sort_options' => $this->sortOptions,
            'refined_content' => $this->refinedContent,
            'related_searches' => $this->relatedSearches,
        ];
    }

    public static function empty(int $page = 1, int $perPage = 24): self
    {
        return new self(products: [], total: 0, page: $page, perPage: $perPage);
    }
}
