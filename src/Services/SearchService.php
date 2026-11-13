<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Concerns\HandlesStorefrontFailures;
use ConstructorIO\Laravel\DataTransferObjects\AutocompleteResults;
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Http\Path;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\ParameterBuilder;
use ConstructorIO\Laravel\Support\ResultTransformer;
use SensitiveParameter;
use SplFileInfo;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Search, browse, autocomplete, facets, categories and collections.
 *
 * Every method calls a public storefront endpoint on ac.cnstrc.com (image
 * search: image-search.cnstrc.com) and forwards the shopper's identity (see
 * RequestContext). Filters and options
 * are documented on ParameterBuilder.
 *
 * Most apps use this through the facade: Constructor::search(...).
 *
 * @see https://docs.constructor.com/reference/v1-search-get-search-results
 * @see https://docs.constructor.com/reference/v1-search-get-natural-language-search-results
 * @see https://docs.constructor.com/reference/v1-browse-get-browse-results
 * @see https://docs.constructor.com/reference/v1-autocomplete-get-autocomplete-results
 * @see https://docs.constructor.com/reference/v2-image-search-retrieve-search-results
 */
class SearchService
{
    use HandlesStorefrontFailures;

    /** Constructor limits autocomplete prefixes to 200 characters. */
    private const MAX_AUTOCOMPLETE_PREFIX_LENGTH = 200;

    /** Constructor accepts at most this many IDs on /browse/items. */
    private const MAX_BROWSE_ITEM_IDS = 100;

    /** Image Search accepts images up to 6 MB. */
    public const MAX_IMAGE_BYTES = 6 * 1024 * 1024;

    /**
     * Formats Image Search accepts, by their file signature ("magic bytes").
     * WebP is checked separately (its signature isn't a plain prefix). HEIC
     * is not supported; AVIF only if Constructor enables it for you.
     *
     * @see https://docs.constructor.com/docs/products-search-learn-about-image-search
     */
    private const IMAGE_SIGNATURES = [
        'jpeg' => ["\xFF\xD8\xFF"],
        'png' => ["\x89PNG\r\n\x1A\n"],
        'gif' => ['GIF87a', 'GIF89a'],
    ];

    /** Ways Image Search can crop the objects it detects (search_crop_type). */
    private const IMAGE_CROP_TYPES = ['bbox', 'segment'];

    /** Constructor's maximum page size for /browse/facets. */
    private const MAX_FACETS_PER_REQUEST = 1000;

    /** Constructor's maximum (and default) page size for /browse/facets_with_options. */
    private const MAX_FACETS_WITH_OPTIONS_PER_PAGE = 10;

    /** Constructor allows 0-50 results per autocomplete section. */
    private const MAX_AUTOCOMPLETE_SECTION_RESULTS = 50;

    /** Section names Constructor may use for category suggestions in autocomplete. */
    private const CATEGORY_SECTIONS = ['Categories', 'Groups', 'group_ids'];

    protected readonly ConstructorConfig $config;

    public function __construct(
        protected readonly ApiClient $api,
        protected readonly ResultTransformer $transformer,
        protected readonly RecommendationService $recommendations,
    ) {
        $this->config = $api->config();
    }

    /**
     * Full-text search.
     *
     *     Constructor::search('pellet grill', ['brand' => ['Traeger']], ['per_page' => 24, 'sort_by' => 'price']);
     *
     * An empty query returns no results; use browse() for listing pages.
     *
     * @param  array<string, mixed>  $filters  ['facet' => value or [values]]
     * @param  array<string, mixed>  $options  See ParameterBuilder.
     */
    public function search(string $query, array $filters = [], array $options = []): SearchResults
    {
        $query = trim($query);
        if ($query === '') {
            return SearchResults::empty(ParameterBuilder::page($options), ParameterBuilder::perPage($options));
        }

        return $this->fetchResults('search', '/v1/search/'.Path::segment($query), $filters, $options);
    }

    /**
     * Search with a spoken or conversational query, e.g. from voice input.
     *
     *     Constructor::naturalLanguageSearch('show me gas grills under 500 dollars');
     *
     * Constructor strips filler words from the query and turns phrases it
     * recognises ("under 500 dollars", "in black") into filters. Convert speech
     * to text in the browser or app first. Takes the same filters and options
     * as search(), and returns the same SearchResults, including redirects.
     *
     * @see https://docs.constructor.com/reference/v1-search-get-natural-language-search-results
     *
     * @param  array<string, mixed>  $filters  ['facet' => value or [values]]
     * @param  array<string, mixed>  $options  See ParameterBuilder.
     */
    public function naturalLanguageSearch(string $query, array $filters = [], array $options = []): SearchResults
    {
        $query = trim($query);
        if ($query === '') {
            return SearchResults::empty(ParameterBuilder::page($options), ParameterBuilder::perPage($options));
        }

        return $this->fetchResults('naturalLanguageSearch', '/v1/search/natural_language/'.Path::segment($query), $filters, $options);
    }

    /**
     * Products that look like a photo, e.g. one the shopper took or uploaded.
     *
     *     $results = Constructor::searchByImage($request->file('photo'));
     *     $results = Constructor::searchByImage($request->file('photo'), ['use_segmentation' => true]);
     *
     * $image is an uploaded file (UploadedFile), a file you trust on disk
     * (new SplFileInfo($path)), or the image bytes themselves (for example
     * from Storage::get() or a decoded mobile upload). A plain string is
     * always treated as bytes, never as a path, so shopper input can't make
     * the server read one of its own files.
     *
     * The image must be JPEG, PNG, WebP or GIF, at most 6 MB. That is checked
     * from the file's contents before anything is sent, and a bad image throws
     * InvalidArgumentException (validate uploads first: `image|max:6144`).
     *
     * Options:
     *   section            string            Index section (default from config)
     *   use_segmentation   bool              Detect several objects in the photo and search for each
     *   return_segments    bool              With use_segmentation: describe each detected object
     *                                        (metadata['segments'])
     *   search_crop_type   'bbox'|'segment'  With use_segmentation: crop objects with or without background
     * plus variations_map, fmt_options, hidden_fields, user_segments,
     * parameters and identity overrides (see ParameterBuilder).
     *
     * Image Search has no filters or pages. To narrow the matches, pass the
     * results to filterImageResults(). metadata also holds `browse_url`,
     * `segmentation_used` and `whole_image_search_fallback`.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException If the image is missing, too large or in an unsupported format.
     */
    public function searchByImage(#[SensitiveParameter] SplFileInfo|string $image, array $options = []): SearchResults
    {
        // Checked before attempt(): a bad image is the caller's to handle
        // (tell the shopper), not an outage to log and hide.
        $bytes = $this->imageBytes($image);
        $parameters = $this->imageSearchParameters($options);

        return $this->attempt('searchByImage', function () use ($bytes, $parameters, $options) {
            $response = $this->api->postStorefront(
                $this->config->imageSearchBaseUrl,
                '/v2/image_search',
                $bytes,
                'application/octet-stream',
                $parameters,
                ParameterBuilder::identity($options),
            );

            $results = is_array($response['response']['results'] ?? null) ? $response['response']['results'] : [];

            return $this->toSearchResults($response, 1, max(1, count($results)), array_filter([
                'browse_url' => $response['response']['browse_url'] ?? null,
                'segmentation_used' => $response['segmentation_used'] ?? null,
                'whole_image_search_fallback' => $response['whole_image_search_fallback'] ?? null,
                'segments' => $response['segments'] ?? null,
            ], fn ($value) => $value !== null));
        }, fn () => SearchResults::empty(1, 1));
    }

    /**
     * Narrow Image Search matches with filters, e.g. only Weber grills that
     * look like the photo.
     *
     *     $matches = Constructor::searchByImage($photo);
     *     $weber = Constructor::filterImageResults($matches, ['brand' => 'Weber']);
     *
     * Constructor's documented way to filter image results is to browse the
     * matched items with filters (the `browse_url` it returns points at
     * /browse/items). This does that with the matched item IDs, through
     * browseItems(), so filters, sorting and paging work as they do there.
     * Only the first 100 matches are used, the most /browse/items accepts.
     *
     * @see https://docs.constructor.com/reference/image-search-image-search-results
     *
     * @param  array<string, mixed>  $filters  ['facet' => value or [values]]
     * @param  array<string, mixed>  $options  See ParameterBuilder.
     */
    public function filterImageResults(SearchResults $imageResults, array $filters = [], array $options = []): SearchResults
    {
        $itemIds = array_slice(array_values(array_filter(
            array_map(fn (array $product) => (string) ($product['id'] ?? ''), $imageResults->products),
            fn (string $id) => $id !== '',
        )), 0, self::MAX_BROWSE_ITEM_IDS);

        if ($itemIds === []) {
            return SearchResults::empty(ParameterBuilder::page($options), ParameterBuilder::perPage($options));
        }

        return $this->browseItems($itemIds, $filters, $options);
    }

    /**
     * Products matching one filter, e.g. a category or brand page.
     *
     *     Constructor::browse('group_id', 'pellet-grills');
     *     Constructor::browse('brand', 'Weber', ['fuel_type' => 'Gas']);
     *
     * @param  array<string, mixed>  $filters  Additional filters.
     * @param  array<string, mixed>  $options  See ParameterBuilder.
     */
    public function browse(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults
    {
        $path = '/browse/'.Path::segment($filterName).'/'.Path::segment($filterValue);

        return $this->fetchResults('browse', $path, $filters, $options);
    }

    /**
     * Specific products by ID, e.g. "recently viewed" or a comparison table.
     * Results come back in the order Constructor ranks them.
     *
     * @param  list<string>  $itemIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options  See ParameterBuilder.
     */
    public function browseItems(array $itemIds, array $filters = [], array $options = []): SearchResults
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('strval', $itemIds),
            fn (string $id) => $id !== '',
        )));

        if ($itemIds === []) {
            return SearchResults::empty();
        }

        if (count($itemIds) > self::MAX_BROWSE_ITEM_IDS) {
            throw new InvalidArgumentException(sprintf(
                'browseItems() accepts at most %d item IDs per call (Constructor\'s limit); %d given.',
                self::MAX_BROWSE_ITEM_IDS,
                count($itemIds),
            ));
        }

        $options['parameters'] = array_merge($options['parameters'] ?? [], ['ids' => $itemIds]);
        $options['per_page'] ??= count($itemIds);

        return $this->fetchResults('browseItems', '/browse/items', $filters, $options);
    }

    /**
     * Search suggestions and product previews as the shopper types.
     *
     * Choose how many results each section returns:
     *
     *     Constructor::autocomplete('weber', [
     *         'sections' => [
     *             'suggestions' => ['enabled' => true, 'limit' => 5],
     *             'products' => ['enabled' => true, 'limit' => 6],
     *         ],
     *         // Or name sections directly, including custom ones:
     *         'results_per_section' => ['Search Suggestions' => 5, 'Products' => 6, 'Categories' => 3],
     *     ]);
     *
     * filters and pre_filter_expression apply to every section. To narrow a
     * single section, key them by section name instead:
     *
     *         'section_filters' => ['Products' => ['brand' => 'Weber', 'in_stock' => true]],
     *         'section_pre_filter_expression' => ['Products' => ['name' => 'brand', 'value' => 'Weber']],
     *
     * They are sent as filters[Products][brand]=Weber and
     * pre_filter_expression[Products]={...}. Filtering "Search Suggestions"
     * needs extra configuration on Constructor's side.
     *
     * Other supported options: fmt_options, hidden_fields, variations_map,
     * user_segments, lang, parameters, include_categories_from_products, plus
     * identity overrides.
     *
     * @param  array<string, mixed>  $options
     */
    public function autocomplete(string $query, array $options = []): AutocompleteResults
    {
        $prefix = mb_substr(trim($query), 0, self::MAX_AUTOCOMPLETE_PREFIX_LENGTH);
        if ($prefix === '') {
            return AutocompleteResults::empty();
        }

        // Both would be sent as pre_filter_expression, so they can't be combined.
        // Checked before the request, so it throws even when failures are logged.
        if (! empty($options['pre_filter_expression']) && ! empty($options['section_pre_filter_expression'])) {
            throw new InvalidArgumentException('Pass either pre_filter_expression or section_pre_filter_expression to autocomplete(), not both.');
        }

        return $this->attempt('autocomplete', function () use ($prefix, $options) {
            $parameters = ParameterBuilder::shared($options['filters'] ?? [], $options);
            $parameters['num_section_results_per_page'] = $this->autocompleteSectionLimits($options);
            $parameters = $this->withSectionFilters($parameters, $options);

            $response = $this->api->getStorefront(
                '/v1/autocomplete/'.Path::segment($prefix),
                $parameters,
                ParameterBuilder::identity($options),
            );

            return $this->toAutocompleteResults($response, $options);
        }, fn () => AutocompleteResults::empty());
    }

    /**
     * Content for an empty, focused search box: top categories and products
     * from a recommendation pod.
     *
     *     Constructor::getZeroStateData([
     *         'show_top_categories' => true,
     *         'categories_limit' => 5,
     *         'show_popular_products' => true,
     *         'recommendation_pod_id' => 'zero-state-bestsellers',
     *         'products_limit' => 6,
     *     ]);
     *
     * @param  array<string, mixed>  $options
     */
    public function getZeroStateData(array $options = []): AutocompleteResults
    {
        $topCategories = [];
        if ($options['show_top_categories'] ?? true) {
            $topCategories = $this->getBrowseGroups([
                'limit' => $options['categories_limit'] ?? 5,
                'children_limit' => 0,
            ]);
        }

        $popularProducts = [];
        $podId = trim((string) ($options['recommendation_pod_id'] ?? ''));
        if (($options['show_popular_products'] ?? true) && $podId !== '') {
            $podOptions = $options['recommendation_pod_params'] ?? [];
            if (is_string($podOptions)) {
                // 1.x accepted a JSON string here.
                $podOptions = json_decode($podOptions, true);
            }
            if (! is_array($podOptions)) {
                $podOptions = [];
            }

            $popularProducts = $this->recommendations
                ->getRecommendations($podId, ['limit' => $options['products_limit'] ?? 6] + $podOptions)
                ->products;
        }

        return AutocompleteResults::zeroState(popularProducts: $popularProducts, topCategories: $topCategories);
    }

    /**
     * Facets (with option counts) for a query and filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, array<string, mixed>>
     */
    public function getFacets(string $query, array $filters = []): array
    {
        return $this->search($query, $filters, ['per_page' => 1])->facets;
    }

    /**
     * Every facet configured for the index, without options.
     * For building "Shop by ..." navigation.
     *
     * @param  array<string, mixed>  $options  section, fmt_options (e.g. show_hidden_facets)
     * @return list<array{name: string, display_name: string, type: string}>
     */
    public function getAvailableFacets(array $options = []): array
    {
        return $this->attempt('getAvailableFacets', function () use ($options) {
            $parameters = $this->sectionParameters($options) + [
                // The endpoint pages its results (20 by default); ask for all of them.
                'num_results_per_page' => self::MAX_FACETS_PER_REQUEST,
            ];

            $response = $this->api->getStorefront('/browse/facets', $parameters);
            $showHidden = (bool) ($options['fmt_options']['show_hidden_facets'] ?? false);

            $facets = [];
            foreach ($response['response']['facets'] ?? [] as $facet) {
                $name = is_array($facet) ? (string) ($facet['name'] ?? '') : '';

                // Categories are served by getBrowseGroups().
                if ($name === '' || in_array($name, ['group_id', 'group_ids'], true)) {
                    continue;
                }

                if (($facet['hidden'] ?? false) && ! $showHidden) {
                    continue;
                }

                $facets[] = [
                    'name' => $name,
                    'display_name' => $facet['display_name'] ?? $name,
                    'type' => ResultTransformer::facetType((string) ($facet['type'] ?? 'multiple')),
                ];
            }

            return $facets;
        }, fn () => []);
    }

    /**
     * Facets together with their options, a page at a time, for building a
     * filter sidebar or "Shop by" menus without running a search.
     *
     *     $page = Constructor::getFacetsWithOptions(['per_page' => 10]);
     *     $page['facets']['brand']['options'];   // [['value' => 'Weber', 'count' => 12, ...], ...]
     *     $page['has_more'];                     // request 'page' => 2 for the next facets
     *
     * Facets use the same shape as SearchResults::$facets. Counts cover the
     * whole section. Constructor returns at most 10 facets per request, so
     * per_page is capped at 10.
     *
     * Options:
     *   section        string  Index section, default from config
     *   page           int     Page of facets, starting at 1
     *   offset         int     Facets to skip. Use instead of page.
     *   per_page       int     Facets per page (1-10, default 10)
     *   fmt_options    array   show_hidden_facets, hidden_facets, show_hidden_facet_options, show_protected_facets
     *   user_segments  list    Segments (`us`)
     *   parameters     array   Any other API parameter, passed through as-is
     * plus the shopper identity overrides described on ParameterBuilder.
     *
     * @see https://docs.constructor.com/reference/v1-browse-get-browse-facets-with-options
     *
     * @param  array<string, mixed>  $options
     * @return array{facets: array<string, array<string, mixed>>, total: int, page: int, per_page: int, has_more: bool, result_id: string|null}
     */
    public function getFacetsWithOptions(array $options = []): array
    {
        $perPage = min(self::MAX_FACETS_WITH_OPTIONS_PER_PAGE, max(1, (int) ($options['per_page'] ?? self::MAX_FACETS_WITH_OPTIONS_PER_PAGE)));
        $offset = isset($options['offset']) ? max(0, (int) $options['offset']) : null;
        $page = $offset !== null ? intdiv($offset, $perPage) + 1 : ParameterBuilder::page($options);

        $empty = fn () => ['facets' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'has_more' => false, 'result_id' => null];

        return $this->attempt('getFacetsWithOptions', function () use ($options, $perPage, $offset, $page) {
            $parameters = $this->sectionParameters($options) + ['num_results_per_page' => $perPage];

            // The API rejects page and offset together.
            if ($offset !== null) {
                $parameters['offset'] = $offset;
            } else {
                $parameters['page'] = $page;
            }

            $segments = $options['user_segments'] ?? $options['us'] ?? null;
            if (! empty($segments)) {
                $parameters['us'] = array_values((array) $segments);
            }

            $response = $this->api->getStorefront(
                '/browse/facets_with_options',
                array_merge($parameters, $options['parameters'] ?? []),
                ParameterBuilder::identity($options),
            );

            $body = $response['response'] ?? [];
            $facets = $this->transformer->facets(is_array($body['facets'] ?? null) ? $body['facets'] : []);
            $total = (int) ($body['total_num_results'] ?? count($facets));
            $skipped = $offset ?? ($page - 1) * $perPage;

            return [
                'facets' => $facets,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $skipped + $perPage < $total,
                'result_id' => $response['result_id'] ?? null,
            ];
        }, $empty);
    }

    /**
     * The most common options of one facet, each with an image.
     *
     * The image comes from the option's `image_url` data field when you set
     * one in the dashboard. Otherwise one extra browse request per option
     * finds a product image, so keep $maxItems small or cache the result.
     *
     * @return list<array{value: string, display_name: string, count: int, image: string|null}>
     */
    public function getFacetValuesWithImages(string $facetName, int $maxItems = 10): array
    {
        return $this->attempt('getFacetValuesWithImages', function () use ($facetName, $maxItems) {
            $response = $this->api->getStorefront('/browse/facet_options', [
                'facet_name' => $facetName,
                'section' => $this->config->defaultSection,
            ]);

            $facet = $this->transformer->facets($response['response']['facets'] ?? [])[$facetName] ?? null;
            if ($facet === null) {
                return [];
            }

            $options = $facet['options'];
            usort($options, fn (array $a, array $b) => $b['count'] <=> $a['count']);

            return array_map(fn (array $option) => [
                'value' => $option['value'],
                'display_name' => $option['display_name'],
                'count' => $option['count'],
                'image' => $option['data']['image_url'] ?? $this->firstImage($this->browse($facetName, $option['value'], [], ['per_page' => 1])),
            ], array_slice($options, 0, $maxItems));
        }, fn () => []);
    }

    /**
     * The category tree, for navigation menus.
     *
     *     Constructor::getBrowseGroups(['limit' => 10, 'children_limit' => 5, 'with_images' => true]);
     *
     * Options:
     *   limit           int     Top-level groups to return (default 10)
     *   children_limit  int     Children kept per group (default 5; 0 for none)
     *   depth           int     Levels to request (groups_max_depth)
     *   group_id        string  Start from this sub-category
     *   with_images     bool    Add each group's first product image
     *   section         string  Index section
     * The 1.x names max_items and max_children are also accepted.
     *
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function getBrowseGroups(array $options = []): array
    {
        return $this->attempt('getBrowseGroups', function () use ($options) {
            $parameters = $this->sectionParameters($options);

            if (isset($options['group_id'])) {
                $parameters['filters'] = ['group_id' => [(string) $options['group_id']]];
            }

            $maxChildren = (int) ($options['children_limit'] ?? $options['max_children'] ?? 5);

            // Depth to request: one level for the groups themselves, one more
            // when we skip the "All" root, and one more for their children.
            $defaultDepth = 1 + (isset($options['group_id']) ? 0 : 1) + ($maxChildren > 0 ? 1 : 0);
            $parameters['fmt_options'] = array_merge(
                $parameters['fmt_options'] ?? [],
                ['groups_max_depth' => (int) ($options['depth'] ?? $defaultDepth)],
            );

            $response = $this->api->getStorefront('/browse/groups', $parameters);
            $groups = $response['response']['groups'] ?? [];

            // The tree usually starts with a single "All" root. Show its children.
            if (count($groups) === 1 && ! empty($groups[0]['children']) && ! isset($options['group_id'])) {
                $groups = $groups[0]['children'];
            }

            $groups = array_slice($this->transformer->groups($groups, $maxChildren), 0, (int) ($options['limit'] ?? $options['max_items'] ?? 10));

            if ($options['with_images'] ?? false) {
                foreach ($groups as &$group) {
                    $group['image'] ??= $this->firstImage($this->browse('group_id', $group['id'], [], ['per_page' => 1]));
                }
                unset($group);
            }

            return $groups;
        }, fn () => []);
    }

    /**
     * Collections (curated product lists) set up in the dashboard.
     *
     * @param  array<string, mixed>  $options  page, per_page (default 10; max_items, the 1.x name, also works), sort_by (id|display_name), sort_order, section
     * @return list<array<string, mixed>>
     */
    public function getCollections(array $options = []): array
    {
        return $this->attempt('getCollections', function () use ($options) {
            $parameters = $this->sectionParameters($options) + [
                'num_results_per_page' => min(ParameterBuilder::MAX_PER_PAGE, max(1, (int) ($options['per_page'] ?? $options['max_items'] ?? 10))),
                'page' => ParameterBuilder::page($options),
            ];

            if (isset($options['sort_by'])) {
                $parameters['sort_by'] = $options['sort_by'];
                $parameters['sort_order'] = $options['sort_order'] ?? 'descending';
            }

            $response = $this->api->getStorefront('/browse/collections', $parameters);

            return array_values(array_map(
                fn (array $collection) => $this->transformer->collection($collection),
                array_filter($response['response']['collections'] ?? [], 'is_array'),
            ));
        }, fn () => []);
    }

    /**
     * One collection's details (name, custom data), or null if it doesn't exist.
     *
     * @return array<string, mixed>|null
     */
    public function getCollection(string $collectionId): ?array
    {
        return $this->attempt('getCollection', function () use ($collectionId) {
            $response = $this->api->getStorefront(
                '/browse/collection_id/'.Path::segment($collectionId),
                ['section' => $this->config->defaultSection, 'num_results_per_page' => 1],
            );

            $collection = $response['response']['collection'] ?? null;

            return is_array($collection) ? $this->transformer->collection($collection) : null;
        }, fn () => null);
    }

    /**
     * Products in a collection.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options  See ParameterBuilder.
     */
    public function browseCollection(string $collectionId, array $filters = [], array $options = []): SearchResults
    {
        return $this->fetchResults('browseCollection', '/browse/collection_id/'.Path::segment($collectionId), $filters, $options);
    }

    /**
     * The image of the first product in a collection, for collection tiles.
     */
    public function getFirstProductImageFromCollection(string $collectionId): ?string
    {
        return $this->firstImage($this->browseCollection($collectionId, [], ['per_page' => 5]));
    }

    /**
     * Search the "Recipes" section (or the section you pass).
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function searchRecipes(string $query, array $filters = [], array $options = []): SearchResults
    {
        return $this->search($query, $filters, $options + ['section' => 'Recipes']);
    }

    /**
     * Browse the "Recipes" section (or the section you pass).
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function browseRecipes(string $filterName, string $filterValue, array $filters = [], array $options = []): SearchResults
    {
        return $this->browse($filterName, $filterValue, $filters, $options + ['section' => 'Recipes']);
    }

    /**
     * One recipe by ID, or null.
     *
     * @param  array<string, mixed>  $options  section (default "Recipes")
     * @return array<string, mixed>|null
     */
    public function getRecipe(string $recipeId, array $options = []): ?array
    {
        $results = $this->browseItems([$recipeId], [], $options + ['section' => 'Recipes', 'per_page' => 1]);

        return $results->products[0] ?? null;
    }

    /**
     * Call a results endpoint (search or browse) and build SearchResults.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    protected function fetchResults(string $operation, string $path, array $filters, array $options): SearchResults
    {
        $perPage = ParameterBuilder::perPage($options);

        // With an offset, report the page that offset falls on so hasMore()
        // and nextPageNumber() stay meaningful.
        $page = isset($options['offset'])
            ? intdiv(max(0, (int) $options['offset']), $perPage) + 1
            : ParameterBuilder::page($options);

        return $this->attempt($operation, function () use ($path, $filters, $options, $page, $perPage) {
            $response = $this->api->getStorefront(
                $path,
                ParameterBuilder::forResults($filters, $options, $this->config->defaultSection),
                ParameterBuilder::identity($options),
            );

            return $this->toSearchResults($response, $page, $perPage);
        }, fn () => SearchResults::empty($page, $perPage));
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $extraMetadata  Endpoint-specific details to keep in metadata.
     */
    protected function toSearchResults(array $response, int $page, int $perPage, array $extraMetadata = []): SearchResults
    {
        $body = $response['response'] ?? [];
        $resultId = $response['result_id'] ?? null;

        // A redirect rule matched: the response has no results, just a URL.
        if (isset($body['redirect'])) {
            return new SearchResults(
                products: [],
                total: 0,
                page: $page,
                perPage: $perPage,
                metadata: $this->transformer->metadata($response),
                resultId: $resultId,
                redirect: [
                    'url' => $body['redirect']['data']['url'] ?? null,
                    'rule_id' => $body['redirect']['data']['rule_id'] ?? null,
                    'matched_terms' => $body['redirect']['matched_terms'] ?? [],
                    'matched_user_segments' => $body['redirect']['matched_user_segments'] ?? [],
                ],
            );
        }

        $products = $this->transformer->products($body['results'] ?? []);

        return new SearchResults(
            products: $products,
            total: (int) ($body['total_num_results'] ?? count($products)),
            page: $page,
            perPage: $perPage,
            facets: $this->transformer->facets($body['facets'] ?? []),
            metadata: $this->transformer->metadata($response) + $extraMetadata + array_filter([
                'collection' => $body['collection'] ?? null,
                'features' => $body['features'] ?? null,
                'result_sources' => $body['result_sources'] ?? null,
            ]),
            groups: $this->transformer->groups($body['groups'] ?? []),
            resultId: $resultId,
            sortOptions: $body['sort_options'] ?? [],
            refinedContent: $body['refined_content'] ?? [],
            relatedSearches: $body['related_searches'] ?? [],
            relatedBrowsePages: $body['related_browse_pages'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $options
     */
    protected function toAutocompleteResults(array $response, array $options): AutocompleteResults
    {
        $sections = $response['sections'] ?? [];

        $suggestions = array_map(fn (array $suggestion) => [
            'term' => $suggestion['value'] ?? '',
            'matched_terms' => $suggestion['matched_terms'] ?? [],
            'data' => $suggestion['data'] ?? [],
        ], array_values(array_filter($sections['Search Suggestions'] ?? [], 'is_array')));

        $products = $this->transformer->products($sections[$this->config->defaultSection] ?? $sections['Products'] ?? []);

        $categories = [];
        foreach (self::CATEGORY_SECTIONS as $sectionName) {
            if (isset($sections[$sectionName])) {
                $categories = array_map(fn (array $category) => [
                    'id' => (string) ($category['data']['id'] ?? $category['value'] ?? ''),
                    'name' => $category['value'] ?? '',
                    'path' => $category['data']['path'] ?? null,
                    'count' => $category['data']['count'] ?? null,
                ], array_values(array_filter((array) $sections[$sectionName], 'is_array')));
                break;
            }
        }

        if ($categories === [] && ($options['include_categories_from_products'] ?? false)) {
            $categories = $this->categoriesFromProducts($products);
        }

        return new AutocompleteResults(
            suggestions: $suggestions,
            products: $products,
            categories: $categories,
            metadata: $this->transformer->metadata($response) + [
                'total_num_results_per_section' => $response['total_num_results_per_section'] ?? [],
            ],
            sections: $sections,
            resultId: $response['result_id'] ?? null,
        );
    }

    /**
     * Add per-section autocomplete filters (filters[Section][name]=value) and
     * pre-filter expressions (pre_filter_expression[Section]={json}).
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withSectionFilters(array $parameters, array $options): array
    {
        foreach ((array) ($options['section_filters'] ?? []) as $sectionName => $sectionFilters) {
            $built = is_array($sectionFilters) ? ParameterBuilder::filters($sectionFilters) : [];
            if ($built !== []) {
                $parameters['filters'][(string) $sectionName] = $built;
            }
        }

        $expressions = (array) ($options['section_pre_filter_expression'] ?? []);
        if ($expressions !== []) {
            $parameters['pre_filter_expression'] = array_map(ParameterBuilder::json(...), $expressions);
        }

        return $parameters;
    }

    /**
     * Build num_section_results_per_page from either option style.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, int>
     */
    private function autocompleteSectionLimits(array $options): array
    {
        $sections = $options['sections'] ?? [];

        $limits = [];
        foreach (['suggestions' => 'Search Suggestions', 'products' => $this->config->defaultSection] as $alias => $sectionName) {
            $section = $sections[$alias] ?? [];
            $defaultLimit = $alias === 'suggestions' ? 5 : 6;
            $limits[$sectionName] = ($section['enabled'] ?? true) ? (int) ($section['limit'] ?? $defaultLimit) : 0;
        }

        $limits = array_merge($limits, $options['results_per_section'] ?? []);

        return array_map(
            fn ($limit) => min(self::MAX_AUTOCOMPLETE_SECTION_RESULTS, max(0, (int) $limit)),
            $limits,
        );
    }

    /**
     * Unique categories from product results, for autocomplete when the
     * index has no category section.
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function categoriesFromProducts(array $products, int $limit = 5): array
    {
        $categories = [];

        foreach ($products as $product) {
            foreach ($product['_raw']['groups'] ?? [] as $group) {
                $id = $group['group_id'] ?? '';
                if ($id !== '' && ! isset($categories[$id])) {
                    $categories[$id] = [
                        'id' => $id,
                        'name' => $group['display_name'] ?? $id,
                        'path' => $group['path'] ?? null,
                        'count' => null,
                    ];
                }
            }
        }

        return array_slice(array_values($categories), 0, $limit);
    }

    /**
     * The image to send, as bytes, after checking it is a format and size
     * Image Search accepts.
     *
     * @throws InvalidArgumentException
     */
    private function imageBytes(#[SensitiveParameter] SplFileInfo|string $image): string
    {
        if ($image instanceof SplFileInfo) {
            if ($image instanceof UploadedFile && ! $image->isValid()) {
                throw new InvalidArgumentException('The image upload failed: '.$image->getErrorMessage());
            }

            $path = $image->getRealPath();
            if ($path === false || ! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException('The image file does not exist or cannot be read.');
            }

            // Check the size before reading, so an oversized file never ends up in memory.
            if ((int) filesize($path) > self::MAX_IMAGE_BYTES) {
                throw new InvalidArgumentException($this->imageTooLargeMessage());
            }

            $image = (string) file_get_contents($path);
        }

        if ($image === '') {
            throw new InvalidArgumentException('The image is empty.');
        }

        if (strlen($image) > self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException($this->imageTooLargeMessage());
        }

        if (! $this->isSupportedImage($image)) {
            throw new InvalidArgumentException('Image Search accepts JPEG, PNG, WebP or GIF images.');
        }

        return $image;
    }

    /**
     * Whether the bytes start with a JPEG, PNG, GIF or WebP signature. The
     * contents decide, not the file name or the browser's MIME type, which a
     * shopper controls.
     */
    private function isSupportedImage(string $bytes): bool
    {
        // WebP: "RIFF", four size bytes, then "WEBP".
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return true;
        }

        foreach (self::IMAGE_SIGNATURES as $signatures) {
            foreach ($signatures as $signature) {
                if (str_starts_with($bytes, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function imageTooLargeMessage(): string
    {
        return sprintf('Image Search accepts images up to %d MB.', intdiv(self::MAX_IMAGE_BYTES, 1024 * 1024));
    }

    /**
     * Query parameters for /v2/image_search: only the ones the endpoint
     * documents. It has no filters, sorting or paging.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function imageSearchParameters(array $options): array
    {
        $cropType = $options['search_crop_type'] ?? null;
        if ($cropType !== null && ! in_array($cropType, self::IMAGE_CROP_TYPES, true)) {
            throw new InvalidArgumentException('search_crop_type must be "bbox" or "segment".');
        }

        return array_merge(
            array_filter([
                'section' => $options['section'] ?? $this->config->defaultSection,
                'use_segmentation' => isset($options['use_segmentation']) ? (bool) $options['use_segmentation'] : null,
                'return_segments' => isset($options['return_segments']) ? (bool) $options['return_segments'] : null,
                'search_crop_type' => $cropType,
            ], fn ($value) => $value !== null),
            ParameterBuilder::shared([], array_intersect_key($options, array_flip([
                'variations_map', 'fmt_options', 'hidden_fields', 'user_segments', 'us', 'parameters',
            ]))),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function sectionParameters(array $options): array
    {
        $parameters = ['section' => $options['section'] ?? $this->config->defaultSection];

        if (! empty($options['fmt_options'])) {
            $parameters['fmt_options'] = $options['fmt_options'];
        }

        return $parameters;
    }

    private function firstImage(SearchResults $results): ?string
    {
        foreach ($results->products as $product) {
            if (! empty($product['image_url'])) {
                return $product['image_url'];
            }
        }

        return null;
    }
}
