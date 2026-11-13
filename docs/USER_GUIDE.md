# Constructor for Laravel: User Guide

This guide covers everything in `debello-io/constructor-laravel` 2.x: how to set it up, how to integrate it correctly as a **backend integration**, and how to use each feature. Examples use an outdoor-cooking store (grills, smokers, pellets, accessories).

If you are upgrading from 1.x, read [UPGRADE.md](../UPGRADE.md) first.

## Table of Contents

1. [Getting started](#1-getting-started)
2. [Backend integration essentials](#2-backend-integration-essentials)
3. [Core concepts](#3-core-concepts)
4. [Search](#4-search)
5. [Browse and category pages](#5-browse-and-category-pages)
6. [Autocomplete and zero state](#6-autocomplete-and-zero-state)
7. [Facets and filtering](#7-facets-and-filtering)
8. [Recommendations](#8-recommendations)
9. [Collections](#9-collections)
10. [AI agents](#10-ai-agents)
11. [Behavioral tracking](#11-behavioral-tracking)
12. [Catalog management](#12-catalog-management)
13. [Recipes](#13-recipes)
14. [Laravel Scout](#14-laravel-scout)
15. [DTO reference](#15-dto-reference)
16. [Error handling](#16-error-handling)
17. [Testing your integration](#17-testing-your-integration)
18. [Troubleshooting](#18-troubleshooting)

---

## 1. Getting started

### Requirements

- PHP 8.2 or later
- Laravel 11, 12 or 13 (Laravel 13 needs PHP 8.3+)
- A Constructor index, and the [Constructor beacon](https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking) on every page of your site

### Installation

```bash
composer require debello-io/constructor-laravel
```

The service provider and the `Constructor` facade are registered automatically.

### Publish the configuration

```bash
php artisan vendor:publish --tag=constructor-config
```

This creates `config/constructor.php`. Every setting in it has a comment explaining what it does and links to the Constructor page that defines it.

### Environment variables

The minimum for a storefront:

```env
CONSTRUCTOR_API_KEY=key_your-api-key
CONSTRUCTOR_BACKEND_TOKEN=your-backend-security-token
```

Add these when you use the related features:

```env
# Catalog uploads, item updates, Scout indexing
CONSTRUCTOR_API_TOKEN=your-api-token

# AI Shopping Agent
CONSTRUCTOR_AGENT_DOMAIN=your-agent-domain

# How your traffic is labelled in Constructor's reporting
CONSTRUCTOR_CLIENT_IDENTIFIER=cio-be-web-yourcompany
```

`.env.example` in the package lists every variable with a short explanation.

### Check that it works

```bash
php artisan tinker
```

```php
>>> Constructor::search('pellet grill')->total
=> 128

>>> Constructor::catalog()->verifyCredentials()   // only if you set CONSTRUCTOR_API_TOKEN
=> true
```

If search returns 0 and you expected results, see [Troubleshooting](#18-troubleshooting). By default, failed storefront calls are logged and return empty results, so check `storage/logs/laravel.log`.

---

## 2. Backend integration essentials

With this package, **your server** calls Constructor on behalf of each shopper's browser. Constructor calls this a [backend integration](https://docs.constructor.com/docs/integrating-with-constructor-retrieving-results-integration-approaches-backend-integration-options). It has requirements that a browser integration gets for free. The package handles most of them, but you need to understand them to configure it correctly.

### Three credentials, three jobs

Constructor uses three different credentials. They are **not** interchangeable.

| Setting | What it is | Used for |
|---------|------------|----------|
| `CONSTRUCTOR_API_KEY` | Public index key (`key_...`) | Every request, as the `key` parameter |
| `CONSTRUCTOR_API_TOKEN` | Secret API token from the dashboard (Integration > API Integration) | Catalog management only: uploads, `/v2/items`, tasks. Sent with HTTP Basic auth. |
| `CONSTRUCTOR_BACKEND_TOKEN` | Secret backend **security token** that Constructor gives you | Search, browse, autocomplete, recommendations and agent requests, as the `x-cnstrc-token` header |

The backend token is how Constructor recognises that requests from your server's IP are legitimate. Without it, Constructor is likely to throttle your traffic (HTTP 429), because thousands of shoppers appear to come from one IP address. Ask your Constructor contact for it.

The package never sends the API token on storefront requests, and never uses it in place of the backend token.

See [Authentication](https://docs.constructor.com/reference/main-authentication) and [Required parameters](https://docs.constructor.com/docs/integrating-with-constructor-direct-api-integration-required-parameters).

### The beacon must be on every page

The Constructor JavaScript beacon does two things this package cannot do from the server:

1. It assigns each shopper an anonymous **client ID** and a **session number** and stores them in cookies.
2. It sends **behavioral events** (results loaded, clicks, add to cart, purchases) that Constructor learns from. See [Behavioral tracking](#11-behavioral-tracking).

Install the beacon before you build the integration, so the cookies exist for your server to read.

### Shopper identity: what is sent with each request

For every storefront call, the package adds these values for the current shopper:

| Parameter | Value | Source |
|-----------|-------|--------|
| `i` | Anonymous client ID | `ConstructorioID_client_id` cookie |
| `s` | Session number (an integer) | `ConstructorioID_session_id` cookie |
| `ui` | Your user ID, **only for signed-in shoppers** | `auth()->id()`, or your resolver |
| `c` | Integration identifier | `CONSTRUCTOR_CLIENT_IDENTIFIER` |
| `_dt` | Request timestamp in milliseconds | Generated |
| `origin_referrer` | The page the shopper is on | The current URL for page requests; the `Referer` header for AJAX/fetch calls |

And these headers:

| Header | Value |
|--------|-------|
| `User-Agent` | The shopper's browser User-Agent, unchanged |
| `X-Forwarded-For` | The shopper's IP address (a single address) |
| `x-cnstrc-token` | `CONSTRUCTOR_BACKEND_TOKEN` |

Some details worth knowing:

- **Cookies are read from the raw `Cookie` header.** The beacon's cookies are plain text, and Laravel's `EncryptCookies` middleware would otherwise discard them. The service provider also registers both cookie names with `EncryptCookies::except()`.
- **First visit.** Before the beacon has run, there is no client ID cookie. The package generates a UUID, sends it, and sets the `ConstructorioID_client_id` cookie (readable by JavaScript) so the beacon adopts the same value. The session number defaults to `1`, as Constructor's documentation recommends. Turn generation off with `CONSTRUCTOR_GENERATE_CLIENT_ID=false`.
- **Guests never get a `ui`.** In 1.x, the Laravel session ID was sometimes sent as `ui`. That was wrong, and it leaked a session credential. Don't pass `session()->getId()` as `session_id` either: `s` must be the beacon's session number. The package ignores values that aren't positive integers.
- **No shopper outside HTTP requests.** In Artisan commands and queue workers, no identity or forwarded headers are sent, only `c`, `_dt` and the backend token.

### Choosing what is sent as `ui`

Constructor recommends an anonymized identifier. If you prefer not to send your raw primary key, register a resolver, for example in `AppServiceProvider::boot()`:

```php
use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Http\Request;

Constructor::resolveUserIdUsing(function (?Request $request) {
    $user = $request?->user();

    // A keyed hash: a plain hash of a sequential ID is easy to reverse.
    return $user ? hash_hmac('sha256', (string) $user->getKey(), config('app.key')) : null;
});
```

Return `null` for guests. To never send `ui`, set `CONSTRUCTOR_SEND_USER_ID=false`.

Register resolvers **once, in `boot()`**. They are process-wide. On Octane, a resolver registered per request (for example in middleware) that captures a user would be reused for other shoppers.

### Consent

For a shopper who has not consented to personalization (or has withdrawn consent), Constructor's documentation says to omit `i`. Register a consent resolver:

```php
Constructor::resolveConsentUsing(function (?Request $request) {
    return $request?->cookie('cookie_consent') === 'all';
});
```

When it returns `false`, `i` is left out and no client ID cookie is created. Results for that shopper are not personalized.

Consent banners often set their cookie from JavaScript, unencrypted. Add its name to `EncryptCookies::except([...])` (for example in `bootstrap/app.php` with `$middleware->encryptCookies(except: ['cookie_consent'])`), or `cookie()` will return `null`.

### Caching, middleware and debugging tools

- **The generated client ID cookie** is queued with `Cookie::queue()`, so it only reaches the browser on routes with the `AddQueuedCookiesToResponse` middleware (the `web` group). On stateless `api` routes, let the beacon create the cookie, or pass `client_id` and `session_id` explicitly.
- **Page caches and CDNs** must not store a response that sets `ConstructorioID_client_id` (send `Cache-Control: private`). Otherwise every shopper served from the cache gets the same client ID.
- **Telescope** and similar tools record outgoing HTTP headers. Hide the backend token with `Telescope::hideRequestHeaders(['x-cnstrc-token'])`.

### Trusted proxies and the shopper's IP

`X-Forwarded-For` is filled from Laravel's `Request::ip()`. Behind a load balancer, CDN or Cloudflare, `Request::ip()` returns the proxy's address unless you configure trusted proxies. Configure them as described in the [Laravel docs](https://laravel.com/docs/requests#configuring-trusted-proxies), for example in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: '*');
})
```

Then check a live request: the IP Constructor receives must be the shopper's, not your server's.

### Pass `result_id` to the page

Every search, browse, autocomplete and recommendation response includes a `result_id`. The beacon's "results loaded" event must carry it, or Constructor cannot connect what shoppers did to what it returned. Put it on the results container:

```blade
<div data-cnstrc-search
     data-cnstrc-search-term="{{ $query }}"
     data-cnstrc-section="Products"
     data-cnstrc-result-id="{{ $results->resultId }}"
     data-cnstrc-num-results="{{ $results->total }}"
     data-cnstrc-result-page="{{ $results->page }}">
    @foreach ($results->products as $product)
        <div data-cnstrc-item-id="{{ $product['id'] }}"
             data-cnstrc-item-name="{{ $product['name'] }}">
            ...
        </div>
    @endforeach
</div>
```

Browse pages use `data-cnstrc-browse` with `data-cnstrc-filter-name` and `data-cnstrc-filter-value` instead of the search attributes. See [Data-driven event tracking](https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking) for the full attribute list.

### Caching personalized results

Results are personalized per shopper. Never put them in a cache shared between shoppers unless the cache key includes the client ID and, for signed-in shoppers, the user ID:

```php
use ConstructorIO\Laravel\Support\RequestContext;

$identity = app(RequestContext::class);
$key = 'recs:home:'.$identity->clientId().':'.($identity->userId() ?? 'guest');

$recommendations = Cache::remember($key, now()->addMinutes(10), fn () =>
    Constructor::getRecommendations('home-bestsellers')
);
```

Caching non-personalized data, such as the category tree from `getBrowseGroups()`, globally is fine.

### Mobile apps calling your API

A native app has no browser cookies. The Constructor iOS and Android SDKs hold the client ID and session ID. Have the app send them to your API, and pass them as overrides:

```php
$results = Constructor::search($request->input('q'), [], [
    'client_id' => $request->header('X-Cnstrc-Client-Id'),
    'session_id' => (int) $request->header('X-Cnstrc-Session-Id'),
]);
```

Every storefront method accepts `client_id`, `session_id`, `user_id` and `origin_referrer` overrides. For app traffic, set `CONSTRUCTOR_CLIENT_IDENTIFIER=cio-be-mobile-yourcompany`, and have the app send a User-Agent in the format given on [Required parameters](https://docs.constructor.com/docs/integrating-with-constructor-direct-api-integration-required-parameters).

### Integration checklist

Before going live:

- [ ] The beacon is on every page.
- [ ] `CONSTRUCTOR_BACKEND_TOKEN` is set, and you see no 429 responses in your logs.
- [ ] Trusted proxies are configured, so Constructor sees shoppers' IPs.
- [ ] The client ID in your server's requests matches the one in the beacon's events, for the same shopper.
- [ ] `result_id` reaches the page on search, browse and recommendation results.
- [ ] Personalized responses are not shared between shoppers by any cache.
- [ ] `CONSTRUCTOR_CLIENT_IDENTIFIER` uses your company name.

---

## 3. Core concepts

### Entry point and services

`Constructor` (the facade) is the entry point. Storefront methods are available directly. The AI agents and catalog management have their own services:

```php
use ConstructorIO\Laravel\Facades\Constructor;

Constructor::search('smoker');                    // SearchService
Constructor::getRecommendations('home-bestsellers'); // RecommendationService
Constructor::agent()->askShoppingAgent('...');    // AgentService
Constructor::catalog()->uploadCatalog('...');     // CatalogService
```

| Service | Responsibility |
|---------|----------------|
| `Services\SearchService` | Search, browse, browse by item ID, autocomplete, zero state, facets, categories, collections, recipes |
| `Services\RecommendationService` | Recommendation pods |
| `Services\AgentService` | AI Shopping Agent and Product Insights Agent |
| `Services\CatalogService` | Catalog uploads, item updates, task status (uses the API token) |
| `Scout\ConstructorEngine` | The `constructor` Laravel Scout driver |

You can also inject the services, or the `Contracts\SearchProvider` interface, which the `Constructor` class implements:

```php
use ConstructorIO\Laravel\Contracts\SearchProvider;

class SearchController
{
    public function __construct(private SearchProvider $search) {}
}
```

### Filters and options

Most methods take `$filters` and `$options` arrays.

**Filters** are `['facet_name' => value or [values]]`. Values for the same facet are ORed; different facets are ANDed:

```php
['brand' => ['Weber', 'Traeger'], 'fuel_type' => 'Pellet', 'wifi_enabled' => true]
```

**Options** are the same across search, browse and collections:

| Option | Type | Meaning |
|--------|------|---------|
| `page` | int | Page number, starting at 1 |
| `per_page` | int | Results per page, 1 to 200 (default 24) |
| `offset` | int | Results to skip. Use instead of `page`. |
| `section` | string | Index section (default `CONSTRUCTOR_SECTION`, usually `Products`) |
| `sort_by` | string | Sort option name, e.g. `price` |
| `sort_order` | string | `ascending` or `descending` (default `descending`) |
| `range_filters` | array | `['price' => ['min' => 200, 'max' => 800]]` |
| `filter_match_types` | array | `['features' => 'all']` (`any`, `all` or `none`) |
| `pre_filter_expression` | array or string | Filters shoppers can't see or remove, e.g. hide out-of-stock items |
| `fmt_options` | array | Response formatting options |
| `hidden_fields` | list | Hidden catalog fields to return, e.g. `['inventory_count']` |
| `variations_map` | array or string | How to group variations, e.g. for colour swatches |
| `user_segments` | list or string | Segments used by redirect and content rules (sent as `us`) |
| `lang` | string | Query language, e.g. `en` |
| `parameters` | array | Any other API parameter, passed through unchanged |
| `user_id`, `client_id`, `session_id`, `origin_referrer` | | Identity overrides (see [section 2](#mobile-apps-calling-your-api)) |

The full list is documented on `Support\ParameterBuilder`. `parameters` is the escape hatch for anything new in Constructor's API that this package doesn't name yet.

### What a product looks like

Every method that returns products uses the same flat shape:

```php
[
    'id' => 'TRG-PRO780',
    'name' => 'Traeger Pro 780 Pellet Grill',
    'description' => '...',
    'url' => '/grills/traeger-pro-780',
    'image_url' => 'https://cdn.example.com/trg-pro780.jpg',
    'price' => 999.99,
    'original_price' => 1099.99,
    'sku' => 'TRG-PRO780',
    'brand' => 'Traeger',
    'variation_id' => null,
    'categories' => [...],        // the item's groups
    'facets' => [...],
    'metadata' => [...],
    'matched_terms' => ['pellet'],
    'variations' => [...],
    'variations_map' => null,
    'is_slotted' => false,        // placed by a merchandising rule
    'labels' => [],               // e.g. ['is_sponsored' => true]
    'strategy' => null,           // recommendations only
    '_raw' => [...],              // every catalog field, including your custom ones
]
```

Fields you added to your catalog (BTU rating, cooking area, warranty...) are in `_raw`:

```php
$product['_raw']['cooking_area_sq_in'] ?? null;
```

### Sponsored and promoted results

When `is_slotted` is true, a merchandising rule placed the product. If `labels['is_sponsored']` is true, it is an advertised placement and you should show a "Sponsored" badge. For sponsored listings, the beacon also needs the campaign details from `labels`:

```blade
<div data-cnstrc-item-id="{{ $product['id'] }}"
     data-cnstrc-item-name="{{ $product['name'] }}"
     @isset($product['labels']['sl_campaign_id'])
         data-cnstrc-sl-campaign-id="{{ $product['labels']['sl_campaign_id'] }}"
         data-cnstrc-sl-campaign-owner="{{ $product['labels']['sl_campaign_owner'] ?? '' }}"
     @endisset>
    @if ($product['labels']['is_sponsored'] ?? false)
        <span class="badge">Sponsored</span>
    @endif
    ...
</div>
```

### Return types

Storefront methods return readonly DTOs: `SearchResults`, `AutocompleteResults` and `RecommendationResults`. All three have `toArray()` for JSON responses. See [DTO reference](#15-dto-reference).

---

## 4. Search

[Search API reference](https://docs.constructor.com/reference/v1-search-get-search-results)

### Basic search

```php
$results = Constructor::search('pellet grill');

foreach ($results->products as $product) {
    echo $product['name'].' — $'.$product['price'];
}

echo "{$results->total} results";
```

The query is sent as one URL path segment, correctly encoded, so queries such as `1/2" grill grate #5` work. An empty query returns no results without calling Constructor. Use [browse](#5-browse-and-category-pages) for listing pages.

### Filters, ranges and sorting

```php
$results = Constructor::search('grill', [
    'brand' => ['Weber', 'Napoleon'],
    'fuel_type' => 'Gas',
], [
    'range_filters' => [
        'price' => ['min' => 300, 'max' => 1200],
        'btu' => ['min' => 30000],          // no upper bound
    ],
    'sort_by' => 'price',
    'sort_order' => 'ascending',
    'page' => 2,
    'per_page' => 24,
]);
```

Range filters are sent in Constructor's `"min-max"` format (`300-1200`, `30000-inf`). Boolean filter values are sent as `True`/`False`.

### Hiding products shoppers shouldn't see

Use `pre_filter_expression` for rules shoppers can't change, such as "in stock only" or "available in this region". It uses Constructor's [filter expression](https://docs.constructor.com/reference/constructor-concepts-filter-expressions) syntax:

```php
$results = Constructor::search('smoker', [], [
    'pre_filter_expression' => ['name' => 'in_stock', 'value' => 'True'],
]);

// Combine with and / or / not
$results = Constructor::search('smoker', [], [
    'pre_filter_expression' => [
        'and' => [
            ['name' => 'in_stock', 'value' => 'True'],
            ['not' => ['name' => 'ships_freight', 'value' => 'True']],
        ],
    ],
]);
```

### Redirects

Merchandisers can set up redirect rules in the dashboard, such as sending "weber" to your Weber brand page. When a rule matches, the response contains a URL instead of results. Always check for it:

```php
$results = Constructor::search($request->input('q', ''));

if ($results->isRedirect()) {
    return redirect($results->redirectUrl());
}
```

`$results->redirect` also has `rule_id`, `matched_terms` and `matched_user_segments`.

### Pagination

```php
$results->page;             // current page
$results->perPage;
$results->total;            // matches across all pages
$results->totalPages();
$results->hasMore();
$results->nextPageNumber(); // 0 on the last page
$results->getOffset();      // for "Showing 25–48 of 156"
```

Constructor reports at most 10,000 total results. When `$results->isTotalCapped()` is true, show "10,000+".

To use Laravel's paginator:

```php
use Illuminate\Pagination\LengthAwarePaginator;

$paginator = new LengthAwarePaginator(
    $results->products,
    $results->total,
    $results->perPage,
    $results->page,
    ['path' => $request->url(), 'query' => $request->query()],
);
```

### Sort options and content rules

```php
$results->sortOptions;     // sort options configured in the dashboard, to build a sort dropdown
$results->refinedContent;  // content rules that matched (banners, messages)
$results->relatedSearches; // related search terms, when enabled
```

### A complete search controller

```php
namespace App\Http\Controllers;

use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $query = trim((string) $request->input('q', ''));

        $results = Constructor::search($query, $request->input('filters', []), [
            'page' => $request->integer('page', 1),
            'per_page' => 24,
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order', 'descending'),
            'range_filters' => array_filter([
                'price' => array_filter([
                    'min' => $request->input('price_min'),
                    'max' => $request->input('price_max'),
                ]),
            ]),
        ]);

        if ($results->isRedirect()) {
            return redirect($results->redirectUrl());
        }

        return view('search.index', [
            'query' => $query,
            'results' => $results,
        ]);
    }
}
```

`sort_by` is only sent when it is set, so `null` is safe here.

---

## 5. Browse and category pages

[Browse API reference](https://docs.constructor.com/reference/v1-browse-get-browse-results)

### Browse a category

Categories are browsed by `group_id`, the ID from your catalog's item groups:

```php
$results = Constructor::browse('group_id', 'pellet-grills', [], [
    'page' => $request->integer('page', 1),
    'per_page' => 24,
]);
```

### Browse by any facet

The same method browses by brand or any other facet:

```php
$weber = Constructor::browse('brand', 'Weber');

$results = Constructor::browse('brand', 'Weber', ['fuel_type' => 'Charcoal'], ['sort_by' => 'price']);
```

Filter names and values are URL-encoded once, so IDs such as `Grills & Smokers` work as they are.

### Category tree for navigation

```php
$categories = Constructor::getBrowseGroups([
    'max_items' => 10,     // top-level categories
    'max_children' => 6,   // children per category
    'with_images' => true, // see below
]);

foreach ($categories as $category) {
    echo $category['name'];          // "Grills"
    foreach ($category['children'] as $child) {
        echo $child['name'];         // "Pellet Grills"
    }
}
```

Each group has `id`, `group_id`, `name`, `count`, `image`, `data`, `parents`, `has_children` and `children`. The tree's single "All" root is skipped automatically.

Other options:

| Option | Meaning |
|--------|---------|
| `group_id` | Start from a sub-category instead of the root |
| `depth` | How many levels to return (default 2 with children, 1 without) |
| `section` | Index section |

**Images.** A category's image comes from the `image_url` field in its data, if you set one in your item groups. `with_images => true` fills missing images with the first product's image, which costs **one extra request per category**. Cache the result:

```php
$navigation = Cache::remember('nav:categories', now()->addHour(), fn () =>
    Constructor::getBrowseGroups(['max_items' => 8, 'with_images' => true])
);
```

The category tree isn't personalized, so a shared cache is fine here.

### Products by ID ("recently viewed", comparisons)

```php
$recentlyViewed = Constructor::browseItems(
    session('recently_viewed', []),      // e.g. ['TRG-PRO780', 'WEB-SPIRIT-E310']
);

foreach ($recentlyViewed->products as $product) {
    // ...
}
```

### A category page controller

```php
public function show(Request $request, string $category)
{
    $results = Constructor::browse('group_id', $category, $request->input('filters', []), [
        'page' => $request->integer('page', 1),
        'per_page' => 24,
        'sort_by' => $request->input('sort_by'),
    ]);

    abort_if($results->total === 0 && $results->page === 1, 404);

    return view('category.show', [
        'category' => $category,
        'results' => $results,
        'subcategories' => Constructor::getBrowseGroups(['group_id' => $category, 'max_items' => 20]),
    ]);
}
```

---

## 6. Autocomplete and zero state

[Autocomplete API reference](https://docs.constructor.com/reference/v1-autocomplete-get-autocomplete-results)

### Suggestions as the shopper types

```php
$results = Constructor::autocomplete('web');

foreach ($results->suggestions as $suggestion) {
    echo $suggestion['term'];          // "weber grill"
}

foreach ($results->products as $product) {
    echo $product['name'];             // "Weber Spirit E-310"
}
```

Prefixes longer than 200 characters are truncated to Constructor's limit.

### How many results per section

By default you get 5 search suggestions and 6 products. Change that per call:

```php
$results = Constructor::autocomplete('web', [
    'sections' => [
        'suggestions' => ['enabled' => true, 'limit' => 8],
        'products' => ['enabled' => true, 'limit' => 4],
    ],
]);
```

Or name the sections directly, which also works for custom sections:

```php
$results = Constructor::autocomplete('brisket', [
    'results_per_section' => ['Search Suggestions' => 5, 'Products' => 4, 'Recipes' => 3],
]);

$recipes = $results->sections['Recipes'] ?? [];
```

`sections` on the result contains every section exactly as Constructor returned it.

Category suggestions come from a `Categories` or `Groups` section if your index has one. Otherwise, pass `'include_categories_from_products' => true` to build them from the products' categories.

Autocomplete also accepts `filters`, `pre_filter_expression`, `hidden_fields`, `user_segments` and the identity overrides.

### Zero state (focused but empty search box)

```php
$zeroState = Constructor::getZeroStateData([
    'show_top_categories' => true,
    'categories_limit' => 5,
    'show_popular_products' => true,
    'recommendation_pod_id' => 'zero-state-bestsellers',
    'products_limit' => 6,
]);

$zeroState->topCategories;   // from getBrowseGroups()
$zeroState->popularProducts; // from the recommendation pod
```

Popular products are only fetched when you name a pod. Pass extra pod options with `recommendation_pod_params`.

### An autocomplete JSON endpoint

```php
Route::get('/api/autocomplete', function (Request $request) {
    $query = trim((string) $request->input('q', ''));

    $results = $query === ''
        ? Constructor::getZeroStateData(['recommendation_pod_id' => 'zero-state-bestsellers'])
        : Constructor::autocomplete($query);

    return response()->json($results->toArray());
})->middleware('throttle:120,1');
```

Call it from the browser as the shopper types, debounced by about 150ms. The beacon tracks autocomplete interactions when your markup uses the [autocomplete data attributes](https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking).

### A Livewire search box

```php
namespace App\Livewire;

use ConstructorIO\Laravel\Facades\Constructor;
use Livewire\Component;

class SearchBox extends Component
{
    public string $query = '';

    public function render()
    {
        $query = trim($this->query);

        $results = $query === ''
            ? Constructor::getZeroStateData(['recommendation_pod_id' => 'zero-state-bestsellers'])
            : Constructor::autocomplete($query);

        return view('livewire.search-box', ['results' => $results]);
    }
}
```

```blade
<div>
    <input type="search" wire:model.live.debounce.150ms="query" placeholder="Search grills, smokers, accessories">

    @if ($results->hasSuggestions())
        <ul>
            @foreach ($results->suggestions as $suggestion)
                <li><a href="{{ route('search', ['q' => $suggestion['term']]) }}">{{ $suggestion['term'] }}</a></li>
            @endforeach
        </ul>
    @endif

    @foreach ($results->hasProducts() ? $results->products : $results->popularProducts as $product)
        <a href="{{ $product['url'] }}">
            <img src="{{ $product['image_url'] }}" alt="">
            {{ $product['name'] }}
        </a>
    @endforeach
</div>
```

Livewire requests are normal routed HTTP requests, so shopper identity is forwarded as usual.

---

## 7. Facets and filtering

[Facets in the results response](https://docs.constructor.com/reference/constructor-concepts-results-response-structure)

### Facets in results

Search and browse results include the facets for the current result set, keyed by facet name:

```php
$results->facets['brand'];
// [
//     'name' => 'Brand',                 // display name
//     'type' => 'checkbox_list',         // checkbox_list | single_select | range | hierarchical
//     'values' => ['Weber' => 45, 'Traeger' => 32],   // value => count
//     'options' => [
//         ['value' => 'Weber', 'display_name' => 'Weber', 'count' => 45, 'selected' => true, 'data' => [], 'range' => null, 'options' => []],
//         ...
//     ],
//     'hidden' => false,
//     'data' => [],
// ]
```

`values` is a quick value-to-count map. `options` has everything else, including whether the option is currently selected and nested options for hierarchical facets.

### Range facets (price, BTU, cooking area)

Range facets have no options. They have the range of values in the results and the range the shopper selected:

```php
$price = $results->facets['price'];
// ['type' => 'range', 'min' => 149, 'max' => 3499, 'selected' => ['min' => 300, 'max' => 1200], ...]
```

Use `min`/`max` to set up a price slider, and send the shopper's choice back with `range_filters`.

### A filter sidebar

```blade
<form method="GET">
    <input type="hidden" name="q" value="{{ $query }}">

    @foreach ($results->facets as $facetName => $facet)
        @continue($facet['hidden'])

        <fieldset>
            <legend>{{ $facet['name'] }}</legend>

            @if ($facet['type'] === 'range')
                <input type="number" name="price_min" min="{{ $facet['min'] }}" max="{{ $facet['max'] }}"
                       value="{{ $facet['selected']['min'] ?? '' }}" placeholder="{{ $facet['min'] }}">
                <input type="number" name="price_max" min="{{ $facet['min'] }}" max="{{ $facet['max'] }}"
                       value="{{ $facet['selected']['max'] ?? '' }}" placeholder="{{ $facet['max'] }}">
            @else
                @foreach ($facet['options'] as $option)
                    <label>
                        <input type="{{ $facet['type'] === 'single_select' ? 'radio' : 'checkbox' }}"
                               name="filters[{{ $facetName }}][]"
                               value="{{ $option['value'] }}"
                               @checked($option['selected'])>
                        {{ $option['display_name'] }} ({{ $option['count'] }})
                    </label>
                @endforeach
            @endif
        </fieldset>
    @endforeach

    <button type="submit">Apply</button>
</form>
```

This example only handles a range facet named `price`; map other range facets to `range_filters` the same way.

Send the option's `value`, not its `display_name`, back as the filter value.

### Facets on their own

```php
$facets = Constructor::getFacets('smoker', ['fuel_type' => 'Wood']);
```

This runs a one-result search and returns its facets.

### All facets in the index

For "Shop by brand" or "Shop by fuel type" navigation:

```php
$facets = Constructor::getAvailableFacets();
// [['name' => 'brand', 'display_name' => 'Brand', 'type' => 'multiple'], ...]
```

Hidden facets and the category facet (`group_id`) are left out; use `getBrowseGroups()` for categories.

### Facet options with images

```php
$brands = Constructor::getFacetValuesWithImages('brand', 8);
// [['value' => 'Weber', 'display_name' => 'Weber', 'count' => 45, 'image' => 'https://...'], ...]
```

Options are sorted by product count. Each image comes from the option's `image_url` data field, if you set one in the dashboard. Otherwise one browse request per option finds a product image, so keep the number small and cache the result.

---

## 8. Recommendations

[Recommendations API reference](https://docs.constructor.com/reference/v1-recommendations-get-pod-results)

### Pods

A pod is a recommendation placement you configure in the Constructor dashboard: a strategy (best sellers, alternatives, complementary items, bundles...), filters and a display name. You reference it by ID.

### Home page and landing pages

```php
$bestsellers = Constructor::getRecommendations('home-bestsellers', ['num_results' => 8]);

echo $bestsellers->title;   // the pod's display name, e.g. "Best sellers this week"

foreach ($bestsellers->products as $product) {
    // ...
}
```

### Product pages and carts

Item-based strategies need the item (or items) they are about:

```php
// Alternatives to the product being viewed
$similar = Constructor::getItemRecommendations('pdp-alternatives', 'TRG-PRO780');

// "Complete your cookout": covers, pellets, thermometers for this grill
$accessories = Constructor::getItemRecommendations('pdp-complementary', 'TRG-PRO780', ['num_results' => 6]);

// Everything in the cart
$cartRecs = Constructor::getItemRecommendations('cart-complementary', $cart->pluck('sku')->all());
```

Use a `complementary_items` pod for accessory and "goes with" recommendations. It replaces 1.x's `searchComplementaryProducts()`, which was removed because it built a hard-coded clothing prompt.

### Other strategies

| Option | Required by | Example |
|--------|-------------|---------|
| `item_id` (string or list) | alternative_items, complementary_items, bundles | `'item_id' => 'TRG-PRO780'` |
| `variation_id` | Variation-specific pods | `'variation_id' => 'TRG-PRO780-BRONZE'` |
| `term` | query_recommendations | `'term' => 'brisket smoker'` |
| `filters` | filtered_items | `'filters' => ['brand' => 'Traeger']` |
| `num_results` | All (default 8) | `'num_results' => 4` |
| `section` | All | `'section' => 'Products'` |

```php
// "Popular in Pellet Grills" on a category page
$popular = Constructor::getRecommendations('category-popular', [
    'filters' => ['group_id' => 'pellet-grills'],
]);

// No-results page: recommendations for the failed query
$fallback = Constructor::getRecommendations('no-results', ['term' => $query]);
```

`pre_filter_expression`, `hidden_fields`, `user_segments` and the identity overrides also work here.

### Strategy and tracking

Each recommended product includes the `strategy` that picked it. Pass the pod ID, `resultId` and the seed item IDs (comma-separated, for item-based pods) to the page for the beacon:

```blade
<section data-cnstrc-recommendations
         data-cnstrc-recommendations-pod-id="{{ $accessories->podId }}"
         data-cnstrc-result-id="{{ $accessories->resultId }}"
         data-cnstrc-num-results="{{ $accessories->total }}"
         data-cnstrc-recommendations-seed-items="TRG-PRO780">
    <h2>{{ $accessories->title }}</h2>
    @foreach ($accessories->products as $product)
        <div data-cnstrc-item-id="{{ $product['id'] }}"> ... </div>
    @endforeach
</section>
```

### A Blade component

```php
namespace App\View\Components;

use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\View\Component;

class Recommendations extends Component
{
    public function __construct(
        public string $pod,
        public ?string $itemId = null,
        public int $limit = 8,
    ) {}

    public function render()
    {
        $recommendations = $this->itemId
            ? Constructor::getItemRecommendations($this->pod, $this->itemId, ['num_results' => $this->limit])
            : Constructor::getRecommendations($this->pod, ['num_results' => $this->limit]);

        return view('components.recommendations', ['recommendations' => $recommendations]);
    }
}
```

```blade
<x-recommendations pod="pdp-complementary" :item-id="$product['id']" :limit="6" />
```

If the pod fails or doesn't exist, the result is empty (and logged), so check `$recommendations->hasRecommendations()` in the view.

---

## 9. Collections

Collections are curated product lists set up in the dashboard, such as "Summer cookout essentials" or "Holiday gift guide". The package uses Constructor's public browse endpoints for collections, so no API token is needed on the storefront.

### List collections

```php
$collections = Constructor::getCollections(['max_items' => 12]);

foreach ($collections as $collection) {
    echo $collection['name'];        // "Summer Cookout Essentials"
    echo $collection['image'];       // data.image_url, if you set one
}
```

Each collection has `id`, `name`, `description` and `image` (both taken from the collection's custom data), `data`, `start_time` and `end_time`. Options: `max_items`, `page`, `sort_by` (`id` or `display_name`), `sort_order` and `section`.

### One collection's details

```php
$collection = Constructor::getCollection('summer-cookout');   // null if it doesn't exist
```

### Products in a collection

```php
$results = Constructor::browseCollection('summer-cookout', ['brand' => 'Weber'], [
    'page' => 1,
    'per_page' => 24,
    'sort_by' => 'price',
]);
```

The result is a normal `SearchResults`, with facets and pagination. The collection's details are also in `$results->metadata['collection']`.

### A tile image from the first product

```php
$image = $collection['image'] ?? Constructor::getFirstProductImageFromCollection('summer-cookout');
```

This makes one browse request, so cache it.

---

## 10. AI agents

The agents are served from `agent.cnstrc.com` and need no API token. Agent methods **throw** `ConstructorException` on failure (unlike storefront reads), so you can show an error message in the UI.

### AI Shopping Agent

[AI Shopping Agent API reference](https://docs.constructor.com/reference/v1-asa-retrieve-intent)

The shopper describes what they want in their own words, and the agent replies with text and batches of products. Set `CONSTRUCTOR_AGENT_DOMAIN` first.

```php
$reply = Constructor::agent()->askShoppingAgent('A smoker for a small patio, under $500');

echo $reply['message'];

foreach ($reply['products'] as $product) {
    echo $product['name'];
}

// Continue the conversation
$followUp = Constructor::agent()->askShoppingAgent('Electric only, please', $reply['thread_id']);
```

The result contains:

| Key | Meaning |
|-----|---------|
| `message` | The agent's full reply text |
| `products` | Every product shown, each with the `result_id` of its batch |
| `thread_id` | Pass it back to continue the conversation (only issued for conversational agent domains) |
| `intent_result_id`, `result_ids` | IDs for tracking agent views and clicks |
| `follow_up_questions` | Questions the agent suggests asking next |
| `refinements` | Each follow-up as `['question' => ..., 'options' => [...]]`, for quick-reply buttons |
| `search_requests`, `alternative_search_requests` | The searches the agent ran |
| `articles` | Content references (`url`, `title`, `image_url`, `abstract`) |
| `images` | Image metadata keyed by item ID |
| `recipe` | Recipe information, when the agent returns one |
| `route` | Set when the agent routes the shopper elsewhere, e.g. `customer_support` |
| `_raw` | Every event received, in order |

Options:

| Option | Meaning |
|--------|---------|
| `guard` | Screen the shopper's input for harmful or off-topic content (default `CONSTRUCTOR_AGENT_GUARD`, on) |
| `num_result_events` | Maximum product batches (default 5) |
| `num_results_per_event` | Maximum products per batch (default 4) |
| `pre_filter_expression` | Limit the products the agent may show, e.g. `['name' => 'in_stock', 'value' => 'True']` |
| `hidden_fields`, `fmt_options`, `user_segments`, `parameters` | As for search |
| `user_id`, `client_id`, `session_id` | Identity overrides |

### Streaming the Shopping Agent to the browser

`askShoppingAgent()` waits for the whole answer, which can take several seconds. For a chat UI, stream instead: `askShoppingAgentStreaming()` calls your callback for each event as Constructor sends it. Forward the events to the browser as Server-Sent Events:

```php
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Http\Request;

Route::get('/assistant/stream', function (Request $request) {
    $query = (string) $request->input('q');
    $threadId = $request->input('thread_id');

    return response()->stream(function () use ($query, $threadId) {
        $send = function (string $event, array $data) {
            echo "event: {$event}\n";
            echo 'data: '.json_encode($data)."\n\n";

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };

        try {
            Constructor::agent()->askShoppingAgentStreaming($query, function (string $event, array $data) use ($send) {
                // Forward what the UI needs. 'complete' repeats everything, so skip it.
                if (in_array($event, ['start', 'message', 'products', 'follow_up', 'end'], true)) {
                    $send($event, $data);
                }
            }, $threadId);
        } catch (ConstructorException $e) {
            report($e);
            $send('error', ['message' => 'Sorry, the assistant is unavailable right now.']);
        }
    }, 200, [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'X-Accel-Buffering' => 'no', // stop nginx from buffering the stream
    ]);
})->middleware('throttle:20,1');
```

```javascript
const source = new EventSource(`/assistant/stream?q=${encodeURIComponent(question)}`);

source.addEventListener('start', (e) => { threadId = JSON.parse(e.data).thread_id; });
source.addEventListener('message', (e) => { replyEl.textContent = JSON.parse(e.data).accumulated; });
source.addEventListener('products', (e) => { renderProducts(JSON.parse(e.data).products); });
source.addEventListener('follow_up', (e) => { renderQuickReplies(JSON.parse(e.data)); });
source.addEventListener('end', () => source.close());
source.addEventListener('error', () => source.close());
```

The callback receives these events, in order:

| Event | Data |
|-------|------|
| `start` | `thread_id`, `intent_result_id` |
| `message` | `text` (this chunk), `accumulated` (all text so far), `route` |
| `products` | `products`, `result_id`, `search_request`, `alternative_search_requests` |
| `follow_up` | `questions`, `question`, `options` |
| `image_meta`, `article_reference`, `recipe_info`, `recipe_instructions` | Constructor's data, unchanged |
| `error` | `status_code`, `message`. Sent just before an `ApiException` is thrown. |
| `end` | Constructor's data |
| `complete` | The final result, the same shape as `askShoppingAgent()` returns |

Events the package doesn't know yet are passed through with Constructor's name and data, so new API features reach you without an upgrade.

The stream may run for up to `CONSTRUCTOR_STREAM_TIMEOUT` seconds (default 120). Make sure your PHP-FPM / web server timeouts allow that.

### Product Insights Agent

[Product questions API reference](https://docs.constructor.com/reference/v1-asa-retrieve-item-questions)

On product pages, suggest questions and answer them with AI, using the product's data:

```php
$agent = Constructor::agent();

$suggested = $agent->getProductQuestions('TRG-PRO780', ['num_results' => 4]);
// ['questions' => ['How long does a full hopper last?', 'Does it have WiFi?', ...], '_raw' => [...]]

$answer = $agent->askProductQuestion('Can I use it in the rain?', 'TRG-PRO780');

echo $answer['answer'];
$answer['follow_up_questions'];   // list of strings
$answer['item_results'];          // alternative / complementary products the answer mentions
$answer['thread_id'];             // continue with the next question
$answer['qna_result_id'];         // for tracking
```

`getProductQuestions()` returns `['questions' => []]` instead of throwing when Constructor fails, because suggested questions are decoration on a product page. `askProductQuestion()` throws.

To stream an answer, use `askProductQuestionStreaming($question, $itemId, $onEvent, $threadId, $options)`. Its events are `start`, `message`, `follow_up_questions`, `item_results`, `error`, `end` and `complete`.

Both methods accept `variation_id`, `guard`, `num_results`, `user_segments` and identity overrides.

### A product Q&A endpoint

```php
Route::post('/products/{sku}/questions', function (Request $request, string $sku) {
    $validated = $request->validate([
        'question' => ['required', 'string', 'max:300'],
        'thread_id' => ['nullable', 'string'],
    ]);

    try {
        $answer = Constructor::agent()->askProductQuestion($validated['question'], $sku, $validated['thread_id'] ?? null);
    } catch (\ConstructorIO\Laravel\Exceptions\ConstructorException $e) {
        report($e);

        return response()->json(['message' => 'We could not answer that right now.'], 503);
    }

    return response()->json([
        'answer' => $answer['answer'],
        'follow_up_questions' => $answer['follow_up_questions'],
        'thread_id' => $answer['thread_id'],
    ]);
})->middleware('throttle:30,1');
```

---

## 11. Behavioral tracking

Constructor ranks and personalizes results by learning from shopper behavior. **This package does not send tracking events.** The Constructor JavaScript beacon does, in the shopper's browser, using the [data attributes](https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking) on your pages.

What the server side must do:

1. **Expose `result_id`** from every search, browse, autocomplete and recommendation response to the page, as shown in [section 2](#pass-result_id-to-the-page). It is required on results-loaded events.
2. **Use the same client and session IDs as the beacon.** The package does this by reading the beacon's cookies (see [section 2](#shopper-identity-what-is-sent-with-each-request)).
3. **Mark up results** with item IDs (`data-cnstrc-item-id`) so clicks and add-to-cart events can be attributed.

For AI agent results, track agent views and clicks with the JavaScript client's `trackAgent*` methods, using the `intent_result_id` and per-product `result_id` returned by `askShoppingAgent()`.

Constructor also has an API for events that happen offline, such as in-store purchases: `POST /v1/offline_behavioral_actions`. This package does not wrap it. Call it from a queued job if you need it, and check the [Behavioral tracking](https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking) documentation for the payload.

To check your tracking, compare, for one shopper, the client ID in a results request with the client ID in the beacon's events, and confirm the `result_id` in a results-loaded event matches the one in the response.

---

## 12. Catalog management

[Catalog API reference](https://docs.constructor.com/reference/v1-catalog-create-or-replace-catalog)

Catalog calls use `CONSTRUCTOR_API_TOKEN` (HTTP Basic auth). They are private: run them from Artisan commands and queued jobs, never while handling shopper requests. They always throw on failure.

```php
$catalog = Constructor::catalog();
```

### Check your credentials

```php
if (! $catalog->verifyCredentials()) {
    throw new RuntimeException('CONSTRUCTOR_API_TOKEN was rejected.');
}
```

This lists one item. It returns `false` on 401/403 and throws for other errors.

### Upload catalog files

```php
// Full sync: replace the catalog with these files
$upload = $catalog->uploadCatalog(storage_path('app/catalog/items.jsonl'), 'create_or_replace', [
    'variations_file' => storage_path('app/catalog/variations.jsonl'),
    'item_groups_file' => storage_path('app/catalog/item_groups.jsonl'),
]);

// Partial: update only the fields in this file
$upload = $catalog->uploadCatalog(storage_path('app/catalog/price-updates.csv'), 'patch', [
    'patch_delta' => true,
    'on_missing' => 'IGNORE',
]);
```

| Option | Meaning |
|--------|---------|
| `variations_file` | Path to a variations file |
| `item_groups_file` | Path to an item groups (categories) file |
| `format` | `csv` or `jsonl`. Default: from the file extension |
| `section` | Index section (default `CONSTRUCTOR_SECTION`) |
| `force` | Allow an upload that would remove more than half the catalog (default `CONSTRUCTOR_FORCE_UPLOAD`) |
| `notification_email` | Who Constructor emails if the task fails (default `CONSTRUCTOR_NOTIFICATION_EMAIL`) |
| `mode` | `create_or_replace` only: `full` (default) or `ids` |
| `patch_delta` | `patch` only: update only the fields you send. Without it, each record in the file replaces the stored record. |
| `on_missing` | `patch` with `patch_delta` only: `FAIL` (default), `IGNORE` or `CREATE` |

`$itemsFile` may be `null` when you only upload variations or item groups. The upload's return value is:

```php
[
    'task_id' => 12345,            // null if Constructor queued the upload with 202 Accepted
    'task_status_path' => '/v1/tasks/12345',
    'message' => null,             // explanation when task_id is null
    'response' => [...],           // Constructor's full response
]
```

Uploads are not retried automatically, because a file stream can only be sent once. The upload timeout is `CONSTRUCTOR_UPLOAD_TIMEOUT` (default 300 seconds).

See Constructor's [catalog file format documentation](https://docs.constructor.com/reference/v1-catalog-create-or-replace-catalog) for the CSV and JSONL columns.

### Wait for the task

Catalog writes are processed in the background:

```php
use ConstructorIO\Laravel\Exceptions\TaskTimeoutException;

try {
    $task = $catalog->waitForTaskCompletion($upload['task_id']);
} catch (TaskTimeoutException $e) {
    // Still running at Constructor. Check again later with getTaskStatus().
}

if ($task['successful']) {
    logger()->info('Catalog updated', ['warnings' => $task['warnings']]);
} else {
    logger()->error('Catalog task failed', ['status' => $task['status'], 'error' => $task['error']]);
}
```

`waitForTaskCompletion($taskId, $timeoutSeconds, $pollIntervalSeconds)` defaults to `CONSTRUCTOR_TASK_TIMEOUT` (600) and `CONSTRUCTOR_TASK_POLL_INTERVAL` (10). It blocks, so only call it in jobs and commands.

`getTaskStatus($taskId)` returns Constructor's response plus:

| Key | Meaning |
|-----|---------|
| `completed` | True once the status is `DONE`, `FAILED` or `CANCELED` |
| `successful` | True only for `DONE` |
| `error` | Failure details, if any |
| `warnings` | Ingestion warnings, if any |

Task IDs must be positive integers; anything else throws `InvalidCatalogDataException`.

### Update individual items

For small, frequent changes (price, stock), use the items API instead of files:

```php
// Create or fully replace items. id and name are required.
$catalog->upsertItems([
    [
        'id' => 'TRG-PRO780',
        'name' => 'Traeger Pro 780 Pellet Grill',
        'data' => ['url' => '/grills/traeger-pro-780', 'price' => 999.99, 'image_url' => '...'],
    ],
]);

// Fields outside `data` are moved into it for you, so this is equivalent:
$catalog->upsertItems([
    ['id' => 'TRG-PRO780', 'name' => 'Traeger Pro 780 Pellet Grill', 'price' => 999.99, 'url' => '/grills/traeger-pro-780'],
]);

// Update fields of existing items. Only id is required.
$catalog->patchItems([
    ['id' => 'TRG-PRO780', 'data' => ['price' => 899.99, 'in_stock' => true]],
], options: ['on_missing' => 'IGNORE']);

// Delete
$catalog->deleteItems(['DISCONTINUED-1', 'DISCONTINUED-2']);
```

Each method takes an optional `$section` and `$options` (`force`, `notification_email`; `on_missing` for `patchItems`). Lists are sent in batches of `CONSTRUCTOR_SCOUT_BATCH_SIZE` (default 1,000, at most 10,000), and the methods return one task result per batch. Items without an `id` (or a `name`, for `upsertItems`) throw `InvalidCatalogDataException` before anything is sent.

### Read items

```php
$item = $catalog->getItem('TRG-PRO780');   // null if it doesn't exist

$page = $catalog->listItems(options: ['page' => 1, 'per_page' => 100]);
// ['items' => [...], 'total_count' => 5321, 'next_page_cursor' => null]

$some = $catalog->listItems(options: ['ids' => ['TRG-PRO780', 'WEB-SPIRIT-E310']]);
```

For very large catalogs, walk with `next_page_cursor` instead of `page`.

### A nightly sync job

```php
namespace App\Jobs;

use App\Exports\ConstructorCatalogExport;
use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncCatalogToConstructor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function handle(ConstructorCatalogExport $export): void
    {
        // Your own code that writes items.jsonl (and optionally variations / item groups).
        $itemsFile = $export->writeItems(storage_path('app/catalog/items.jsonl'));

        $catalog = Constructor::catalog();
        $upload = $catalog->uploadCatalog($itemsFile, 'create_or_replace');

        if ($upload['task_id'] === null) {
            logger()->info('Catalog upload queued by Constructor', ['message' => $upload['message']]);

            return;
        }

        $task = $catalog->waitForTaskCompletion($upload['task_id']);

        if (! $task['successful']) {
            $this->fail(new \RuntimeException('Constructor catalog task '.$task['status']));
        }
    }
}
```

```php
// routes/console.php
Schedule::job(new SyncCatalogToConstructor)->dailyAt('03:00');
```

---

## 13. Recipes

If your index has a `Recipes` section (for example, BBQ recipes linked to products), these helpers search it:

```php
$results = Constructor::searchRecipes('smoked brisket');

$results = Constructor::browseRecipes('meal_type', 'dinner');

$recipe = Constructor::getRecipe('texas-brisket');   // null if not found
```

They behave like `search()` and `browse()` with `'section' => 'Recipes'`. Pass a different `section` in the options if yours is named differently. `getRecipe()` fetches the recipe by ID.

`supportsRecipes()` (and the other `supports*()` methods) exist for code written against several search providers. Constructor supports all of them.

---

## 14. Laravel Scout

Install Scout and set the driver:

```bash
composer require laravel/scout
```

```env
SCOUT_DRIVER=constructor
CONSTRUCTOR_API_TOKEN=your-api-token
```

The package registers the `constructor` engine automatically.

### How models become Constructor items

By default, the engine sends `toSearchableArray()`, with:

- `id` set to the model's Scout key (`getScoutKey()`), so results can be matched back to your rows;
- `name` required at the top level;
- every other field moved into `data`.

For full control, implement `ConstructorIO\Laravel\Scout\ConstructorSearchable`:

```php
use ConstructorIO\Laravel\Scout\ConstructorSearchable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Product extends Model implements ConstructorSearchable
{
    use Searchable;

    public function getConstructorSection(): string
    {
        return 'Products';
    }

    public function toConstructorItem(): array
    {
        return [
            'name' => $this->name,
            'suggested_score' => $this->sales_rank,
            'data' => [
                'url' => route('products.show', $this),
                'image_url' => $this->image_url,
                'price' => $this->price,
                'brand' => $this->brand->name,
                'group_ids' => $this->categories->pluck('slug')->all(),
            ],
        ];
    }
}
```

Both methods are optional. Without `getConstructorSection()`, the default section is used. To index by SKU instead of the primary key, override `getScoutKey()` and `getScoutKeyName()` on the model.

### Searching

```php
$products = Product::search('pellet grill')
    ->where('brand', 'Traeger')
    ->whereIn('fuel_type', ['Pellet', 'Wood'])
    ->where('price', '<=', 1000)
    ->orderBy('price', 'asc')
    ->paginate(24);
```

- `where()` and `whereIn()` become filters.
- `<`, `<=`, `>`, `>=` become range filters. Constructor's ranges are inclusive, so `<` and `<=` behave the same.
- `!=` and `whereNotIn()` become a `pre_filter_expression` with `not`.
- Searches go through `SearchService`, so they carry the shopper's identity and the backend token, like `Constructor::search()`.
- Results are your Eloquent models, loaded from the database in Constructor's ranking order.
- An empty query returns no results. Use `Constructor::browse()` for listing pages.

`get()` returns a `Scout\SearchResultCollection`, an Eloquent collection with Constructor's extras:

```php
$products = Product::search('smoker')->get();

$products->getTotal();
$products->getFacets();
$products->getSortOptions();
$products->getResultId();   // pass to the page for tracking
```

### Indexing

```bash
php artisan scout:import "App\Models\Product"
php artisan scout:flush "App\Models\Product"
```

Saving and deleting models syncs them through `/v2/items` in batches. With `SCOUT_QUEUE=true`, syncing runs in your queue, and because indexing errors are thrown, failed jobs are retried by the queue.

`scout:index` and `scout:delete-index` are not supported: Constructor indexes are created in the dashboard. They throw a `ConstructorException` explaining this.

For a full initial load of a large catalog, a catalog file upload ([section 12](#12-catalog-management)) is faster than `scout:import`.

---

## 15. DTO reference

### SearchResults

Returned by `search()`, `browse()`, `browseItems()`, `browseCollection()`, `searchRecipes()` and `browseRecipes()`.

| Property | Type | Meaning |
|----------|------|---------|
| `products` | list | Products (see [What a product looks like](#what-a-product-looks-like)) |
| `total` | int | Matches across all pages (capped at 10,000) |
| `page` | int | Current page |
| `perPage` | int | Results per page |
| `facets` | array | Facets keyed by name |
| `groups` | list | Category tree for the results |
| `resultId` | ?string | Pass to the page for tracking |
| `redirect` | ?array | Set when a redirect rule matched: `url`, `rule_id`, `matched_terms`, `matched_user_segments` |
| `sortOptions` | list | Sort options configured in the dashboard |
| `refinedContent` | list | Content rules that matched |
| `relatedSearches` | list | Related search terms |
| `metadata` | array | `request_id`, `result_id`, `experiments`, and when present `collection`, `features`, `result_sources` |

| Method | Returns |
|--------|---------|
| `isRedirect()` / `redirectUrl()` | Whether to redirect, and where |
| `hasMore()` | Whether there is another page |
| `totalPages()` | Number of pages |
| `nextPageNumber()` | Next page, or 0 |
| `getOffset()` | Results before this page |
| `isEmpty()` / `count()` | Products on this page |
| `isTotalCapped()` | Whether `total` reached `SearchResults::MAX_REPORTED_TOTAL` (10,000) |
| `toArray()` | Everything, with snake_case keys |
| `SearchResults::empty($page, $perPage)` | An empty result |

### AutocompleteResults

Returned by `autocomplete()` and `getZeroStateData()`.

| Property | Meaning |
|----------|---------|
| `suggestions` | `term`, `matched_terms`, `data` |
| `products` | Product previews |
| `categories` | `id`, `name`, `path`, `count` |
| `popularProducts`, `topCategories` | Zero-state content |
| `trending` | Reserved for trending searches; not populated by Constructor today |
| `sections` | Every section exactly as Constructor returned it |
| `metadata` | `request_id`, `result_id`, `experiments` |

Methods: `hasSuggestions()`, `hasProducts()`, `hasCategories()`, `hasTrending()`, `hasPopularProducts()`, `hasTopCategories()`, `hasZeroStateData()`, `isEmpty()`, `isAutocompleteEmpty()` (ignores zero-state content), `toArray()`, and the factories `AutocompleteResults::empty()` and `AutocompleteResults::zeroState(...)`.

### RecommendationResults

Returned by `getRecommendations()` and `getItemRecommendations()`.

| Property | Meaning |
|----------|---------|
| `podId` | The pod |
| `title` | The pod's display name |
| `products` | Recommended products, each with `strategy` |
| `total` | Total available |
| `resultId` | Pass to the page for tracking |
| `metadata` | `request_id`, `result_id`, `pod`, `experiments` |

Methods: `isEmpty()`, `hasRecommendations()`, `count()`, `toArray()`, `RecommendationResults::empty($podId)`.

---

## 16. Error handling

### The policy

| Call | On failure |
|------|------------|
| Search, browse, autocomplete, facets, groups, collections, recommendations | Logs a warning and returns an empty result |
| `getProductQuestions()` | Logs and returns `['questions' => []]` |
| Shopping Agent, `askProductQuestion()` | Throws |
| Catalog management, Scout indexing | Throws |
| Missing configuration (API key, API token, agent domain) | Always throws `ConfigurationException` |

Storefront reads fail gracefully so a brief Constructor outage degrades a page instead of breaking it. To throw instead, for example in tests or to render your own fallback:

```env
CONSTRUCTOR_THROW_EXCEPTIONS=true
```

Logs go to `CONSTRUCTOR_LOG_CHANNEL` (or your default channel). They include the operation, exception class and HTTP status, never credentials or shopper identifiers.

### Exceptions

All exceptions extend `ConstructorIO\Laravel\Exceptions\ConstructorException`:

| Exception | When |
|-----------|------|
| `ConfigurationException` | A required setting is missing |
| `ConnectionException` | Constructor couldn't be reached, or a stream was interrupted |
| `ApiException` | Constructor returned an error status. `getStatusCode()`, `getResponseBody()` |
| `AuthenticationException` (extends `ApiException`) | 401: wrong key or token. 403: the token lacks permission |
| `RateLimitException` (extends `ApiException`) | 429: throttled. On storefront traffic, usually a missing `CONSTRUCTOR_BACKEND_TOKEN` |
| `InvalidCatalogDataException` | Catalog data rejected before sending (missing item ID or name, missing file, unknown operation, bad task ID) |
| `TaskTimeoutException` | A catalog task didn't finish within the timeout |

```php
use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Exceptions\RateLimitException;

try {
    $reply = Constructor::agent()->askShoppingAgent($query);
} catch (RateLimitException $e) {
    return back()->with('error', 'The assistant is busy. Please try again in a moment.');
} catch (ApiException $e) {
    report($e);   // $e->getStatusCode(), $e->getResponseBody()
} catch (ConstructorException $e) {
    report($e);
}
```

### Retries and timeouts

Only failures that might succeed on a second try are retried: connection errors, 429 and 5xx responses. Other 4xx errors (bad parameters, unknown pod) are never retried.

| Setting | Default | Meaning |
|---------|---------|---------|
| `CONSTRUCTOR_RETRY_ATTEMPTS` | 2 | Total attempts per request |
| `CONSTRUCTOR_RETRY_DELAY` | 100 | Milliseconds before the first retry; doubles each time |
| `CONSTRUCTOR_TIMEOUT` | 10 | Seconds for a normal request |
| `CONSTRUCTOR_STREAM_TIMEOUT` | 120 | Seconds for a streaming agent response |

---

## 17. Testing your integration

### Mock the facade

For controller and feature tests, mock the facade and return DTOs:

```php
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Facades\Constructor;

public function test_search_page_lists_products(): void
{
    Constructor::shouldReceive('search')
        ->once()
        ->with('smoker', [], \Mockery::any())
        ->andReturn(new SearchResults(
            products: [['id' => 'SMK-1', 'name' => 'Offset Smoker', 'url' => '/p/smk-1', 'image_url' => null, 'price' => 499]],
            total: 1,
            page: 1,
            perPage: 24,
            resultId: 'test-result',
        ));

    $this->get('/search?q=smoker')
        ->assertOk()
        ->assertSee('Offset Smoker');
}

public function test_search_follows_redirect_rules(): void
{
    Constructor::shouldReceive('search')->andReturn(new SearchResults(
        products: [], total: 0, page: 1, perPage: 24,
        redirect: ['url' => '/brands/weber'],
    ));

    $this->get('/search?q=weber')->assertRedirect('/brands/weber');
}
```

### Fake the HTTP layer

To test the real package code against canned Constructor responses, use Laravel's HTTP fake. Turn on exceptions so failures aren't hidden:

```php
use Illuminate\Support\Facades\Http;

protected function setUp(): void
{
    parent::setUp();

    config([
        'constructor.api_key' => 'key_test',
        'constructor.backend_token' => 'test-backend-token',
        'constructor.throw_exceptions' => true,
    ]);

    Http::preventStrayRequests();
}

public function test_recommendations_widget(): void
{
    Http::fake([
        'ac.cnstrc.com/recommendations/*' => Http::response([
            'result_id' => 'rec-1',
            'response' => [
                'pod' => ['id' => 'pdp-complementary', 'display_name' => 'Complete your cookout'],
                'results' => [['value' => 'Grill Cover', 'data' => ['id' => 'COVER-1']]],
                'total_num_results' => 1,
            ],
        ]),
    ]);

    $this->get('/grills/traeger-pro-780')->assertSee('Complete your cookout');
}
```

Set the config before the first Constructor call in the test: the package reads configuration once per application instance.

### Agents

Mock `AgentService` in the container. Do it before the first Constructor call in the test, because the `Constructor` class receives its services when it is first resolved:

```php
use ConstructorIO\Laravel\Services\AgentService;

$this->mock(AgentService::class, function ($mock) {
    $mock->shouldReceive('askProductQuestion')->andReturn([
        'answer' => 'Yes, the cover fits all Pro 780 models.',
        'follow_up_questions' => [],
        'item_results' => [],
        'thread_id' => 'thread-1',
        'qna_result_id' => 'qna-1',
        '_raw' => [],
    ]);
});
```

To fake a Shopping Agent stream, return an SSE body:

```php
Http::fake([
    'agent.cnstrc.com/*' => Http::response(
        "event: start\ndata: {\"thread_id\":\"t-1\"}\n\n".
        "event: message\ndata: {\"text\":\"Here are some compact smokers.\"}\n\n".
        "event: end\ndata: {}\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    ),
]);
```

### In Tinker

```php
>>> Constructor::search('grill')->toArray()
>>> Constructor::getRecommendations('home-bestsellers')->toArray()
>>> Constructor::agent()->getProductQuestions('TRG-PRO780')
>>> Constructor::catalog()->getTaskStatus(12345)
```

Tinker runs in the console, so no shopper identity is sent: results are not personalized.

---

## 18. Troubleshooting

### "CONSTRUCTOR_API_KEY is not set"

A `ConfigurationException` names the missing setting. Check `.env`, then clear cached config:

```bash
php artisan config:clear
```

### Search returns no results

1. Set `CONSTRUCTOR_THROW_EXCEPTIONS=true` temporarily to see the real error, or check the log for "Constructor search failed".
2. Check that `CONSTRUCTOR_API_KEY` is the key of the index that holds your catalog.
3. Check the section: the default is `Products`. Set `CONSTRUCTOR_SECTION` if yours differs.
4. Remove filters. A filter name that isn't a facet in your index causes a 400 error.
5. An empty query returns nothing by design. Use `browse()` for listing pages.

### 429 Too Many Requests

On storefront traffic, this almost always means `CONSTRUCTOR_BACKEND_TOKEN` is missing or wrong. Without it, all your shoppers appear to come from your server's IP address. Ask your Constructor contact for the backend security token. (The dashboard API token is a different credential and does not help here.)

### Personalization isn't working, or one shopper appears as many

- Check that the beacon is on every page and sets the `ConstructorioID_client_id` cookie.
- Check that requests reach your app with the `Cookie` header (some CDNs strip cookies on cached routes).
- Confirm the client ID in your server's requests equals the one in the beacon's events.
- Check trusted proxies: Constructor must see the shopper's IP, not your load balancer's.
- If you cache results, include the client ID in the cache key.

### Recommendations are empty

1. Check the log for "Constructor recommendations failed" and the status code. 404 or 400 usually means a wrong pod ID.
2. Item-based pods need `item_id`: use `getItemRecommendations()`.
3. `query_recommendations` pods need `term`; `filtered_items` pods need `filters`.
4. New pods may need time and traffic before they return results.

### Agent errors

- `ConfigurationException`: set `CONSTRUCTOR_AGENT_DOMAIN`.
- `AuthenticationException`: check that the API key and agent domain belong together.
- No `thread_id`: threads are only issued for conversational agent domains.
- Streams cut off: raise `CONSTRUCTOR_STREAM_TIMEOUT`, PHP's `max_execution_time` and your web server's proxy timeouts, and disable response buffering (`X-Accel-Buffering: no` for nginx).

### Catalog uploads

- 401/403: check `CONSTRUCTOR_API_TOKEN`. The catalog uses the dashboard API token, not the backend token.
- JSONL treated as CSV: name the file `.jsonl` or pass `'format' => 'jsonl'`.
- Upload rejected for removing too many items: check the file is complete, or pass `'force' => true` deliberately.
- `task_id` is null: Constructor accepted the upload with 202 and will create the task shortly; `message` explains.
- `TaskTimeoutException`: the task is still running. Check it later with `getTaskStatus()`, or raise `CONSTRUCTOR_TASK_TIMEOUT`.

### Scout

- `InvalidCatalogDataException` when saving models: the searchable array has no `name`. Add one, or implement `toConstructorItem()`.
- Results don't match your database rows: the item IDs in Constructor must equal the models' Scout keys. Re-import after changing `getScoutKey()`.
- `scout:index` throws: create indexes in the Constructor dashboard instead.

### Performance

- Category images (`with_images`), `getFacetValuesWithImages()` and `getFirstProductImageFromCollection()` make extra requests. Cache them.
- Cache non-personalized data such as the category tree globally, and personalized data per client ID.
- Keep `CONSTRUCTOR_TIMEOUT` short (the default is 10 seconds) so a slow response doesn't hold up page rendering.

### Need more help?

- [Constructor documentation](https://docs.constructor.com/)
- [Constructor API reference](https://docs.constructor.com/reference)
- [Package issues](https://github.com/Debello-io/constructor-laravel/issues)
