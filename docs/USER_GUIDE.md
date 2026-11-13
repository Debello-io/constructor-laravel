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
14. [Quizzes](#14-quizzes)
15. [Laravel Scout](#15-laravel-scout)
16. [DTO reference](#16-dto-reference)
17. [Error handling](#17-error-handling)
18. [Testing your integration](#18-testing-your-integration)
19. [Troubleshooting](#19-troubleshooting)

---

## 1. Getting started

### Requirements

- PHP 8.2 or later
- Laravel 12 or 13 (Laravel 13 needs PHP 8.3+)
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
php artisan constructor:verify
```

It checks every setting (without printing secrets), then makes one storefront request and, if you set `CONSTRUCTOR_API_TOKEN`, one catalog request. Each line says what to fix. It exits with 1 if a check fails, so you can run it in a deploy pipeline; `--offline` checks only the configuration.

Then try a search:

```bash
php artisan tinker
```

```php
>>> Constructor::search('pellet grill')->total
=> 128

>>> Constructor::catalog()->verifyCredentials()   // only if you set CONSTRUCTOR_API_TOKEN
=> true
```

If search returns 0 and you expected results, see [Troubleshooting](#19-troubleshooting). By default, failed storefront calls are logged and return empty results, so check `storage/logs/laravel.log`.

---

## 2. Backend integration essentials

With this package, **your server** calls Constructor on behalf of each shopper's browser. Constructor calls this a [backend integration](https://docs.constructor.com/docs/integrating-with-constructor-retrieving-results-integration-approaches-backend-integration-options). It has requirements that a browser integration gets for free. The package handles most of them, but you need to understand them to configure it correctly.

### Three credentials, three jobs

Constructor uses three different credentials. They are **not** interchangeable.

| Setting | What it is | Used for |
|---------|------------|----------|
| `CONSTRUCTOR_API_KEY` | Public index key (`key_...`) | Every request, as the `key` parameter |
| `CONSTRUCTOR_API_TOKEN` | Secret API token from the dashboard (Integration > API Integration) | Only catalog management (uploads, `/v2/items`, tasks) and offline behavioral actions. Sent with HTTP Basic auth. |
| `CONSTRUCTOR_BACKEND_TOKEN` | Secret backend **security token** that Constructor gives you | Search, browse, autocomplete, recommendations and agent requests, as the `x-cnstrc-token` header |
| `CONSTRUCTOR_USER_PROFILE_TOKEN` | Optional. A dashboard API token with the `user_profile.user_preferences(w)` scope | Only shopper preferences, as a Bearer token. Never replaced by the API token. |

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
| `origin_referrer` | The page the shopper is on, **without its query string** | The current URL for page requests; the `Referer` header for AJAX/fetch calls |

And these headers:

| Header | Value |
|--------|-------|
| `User-Agent` | The shopper's browser User-Agent, with control characters removed and capped at 512 characters |
| `X-Forwarded-For` | The shopper's IP address (a single address) |
| `x-cnstrc-token` | `CONSTRUCTOR_BACKEND_TOKEN` |

Some details worth knowing:

- **Cookies are read from the raw `Cookie` header.** The beacon's cookies are plain text, and Laravel's `EncryptCookies` middleware would otherwise discard them. The service provider also registers both cookie names with `EncryptCookies::except()`.
- **First visit.** Before the beacon has run, there is no client ID cookie. The package generates a UUID, sends it, and sets the `ConstructorioID_client_id` cookie (readable by JavaScript) so the beacon adopts the same value. The session number defaults to `1`, as Constructor's documentation recommends. Turn generation off with `CONSTRUCTOR_GENERATE_CLIENT_ID=false`.
- **Guests never get a `ui`.** In 1.x, the Laravel session ID was sometimes sent as `ui`. That was wrong, and it leaked a session credential. Don't pass `session()->getId()` as `session_id` either: `s` must be the beacon's session number. The package ignores values that aren't positive integers.
- **No shopper outside HTTP requests.** In Artisan commands and queue workers, no identity or forwarded headers are sent, only `c`, `_dt` and the backend token.
- **Query strings stay private.** `origin_referrer` never includes the page's query string, which can hold password-reset tokens, signed-URL signatures or email addresses.
- **Your identity values always win.** Endpoint `parameters` can't replace `key`, `c`, `i`, `s` or `ui`. Change identity only through the `client_id`, `session_id` and `user_id` overrides.

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

When it returns `false`, `i` is left out and no client ID cookie is created. Results for that shopper are not personalized. This also applies to a `client_id` you pass explicitly as an override.

Without a resolver, the package assumes consent. For sites that need opt-in consent (for example under GDPR), set `CONSTRUCTOR_REQUIRE_CONSENT=true` (`identity.require_consent`): shoppers then count as not consenting until your resolver says otherwise.

Without consent, `ui`, `s`, the IP address and the User-Agent are still sent. Constructor's documentation only requires omitting `i`; check with your legal team whether that is enough for you.

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

Every search, browse, autocomplete and recommendation response includes a `result_id`. The beacon's "results loaded" event must carry it, or Constructor cannot connect what shoppers did to what it returned. Mark up the results container and each result as [Data-driven event tracking](https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking) describes.

The package's Blade components do this for you. On a search page:

```blade
<x-constructor::search-results :results="$results" :term="$query" class="results-grid">
    @foreach ($results->products as $product)
        <x-constructor::item :product="$product" class="result">
            <a href="{{ $product['url'] }}">{{ $product['name'] }}</a>
            <x-constructor::button action="add_to_cart">Add to cart</x-constructor::button>
        </x-constructor::item>
    @endforeach
</x-constructor::search-results>
```

On a browse (category) page, name the filter you browsed by, exactly as you passed it to `browse()`:

```blade
<x-constructor::browse-results :results="$results" filter-name="group_id" :filter-value="$category">
    {{-- the same <x-constructor::item> elements --}}
</x-constructor::browse-results>
```

What the components take care of:

| Component | Renders |
|-----------|---------|
| `search-results` | `data-cnstrc-search`, the term, section, `result_id`, result count and page, then the `cnstrc-data` script tag |
| `browse-results` | `data-cnstrc-browse`, the section, `result_id`, count, page, filter name and value, then the `cnstrc-data` script tag |
| `tracking-data` | Only the `cnstrc-data` script tag, for when you pass `:with-tracking-data="false"` to a container |
| `item` | Item ID, name, face-out variation ID, price, and the Sponsored Listing campaign ID and owner |
| `recommendations`, `recommendation-item` | A recommendation pod and its items; see [Strategy and tracking](#strategy-and-tracking) |
| `quiz-results` | A quiz's results; see [Tracking quiz results](#tracking-quiz-results) |
| `product-detail` | A product page (`data-cnstrc-product-detail`), or a quick view with `modal` |
| `generic-results` | A product list Constructor didn't produce, with `:total` |
| `button` | A conversion button, `data-cnstrc-btn` (`action="add_to_cart"` by default; also `add_to_wishlist`, `like`, `message`, `make_offer`, `read` or your own) |

Every component takes a `tag` (`div` by default, `button` for the button) and passes your other attributes through: `class` is appended, and a `data-cnstrc-*` attribute you set replaces the computed one. All values are escaped, and the `cnstrc-data` JSON can't break out of its `<script>` tag. `section` defaults to `CONSTRUCTOR_SECTION`; pass `section="..."` for a custom section. To change the markup, publish the views with `php artisan vendor:publish --tag=constructor-views`.

Without the components, the same search page looks like this:

```blade
<div data-cnstrc-search
     data-cnstrc-search-term="{{ $query }}"
     data-cnstrc-section="Products"
     data-cnstrc-result-id="{{ $results->resultId }}"
     data-cnstrc-num-results="{{ $results->total }}"
     data-cnstrc-result-page="{{ $results->page }}">
    @foreach ($results->products as $product)
        <div data-cnstrc-item-id="{{ $product['id'] }}"
             data-cnstrc-item-name="{{ $product['name'] }}"
             @if ($product['variation_id']) data-cnstrc-item-variation-id="{{ $product['variation_id'] }}" @endif
             @if ($price = $product['price']) data-cnstrc-item-price="{{ $price }}" @endif>
            <a href="{{ $product['url'] }}">{{ $product['name'] }}</a>
            <button type="button" data-cnstrc-btn="add_to_cart">Add to cart</button>
        </div>
    @endforeach
</div>

<script id="cnstrc-data" type="application/json">@json($results->trackingData())</script>
```

A browse page uses `data-cnstrc-browse` instead, without the search term, plus `data-cnstrc-filter-name="group_id"` and `data-cnstrc-filter-value="{{ $category }}"`.

The rules that are easy to miss (the components follow all of them):

- `data-cnstrc-section` is always present, even for `Products`.
- `data-cnstrc-num-results` is `0` on a page with no results (keep the other attributes). Leave it out while results are still loading, so the beacon doesn't record an empty page too early.
- `data-cnstrc-item-variation-id` defaults to the product's `variation_id`. Update it when the shopper picks a different swatch.
- `data-cnstrc-item-price` is the lower of the sale price and the list price. Leave it out when an item has no price; never send `0` or an empty value.
- The `cnstrc-data` script tag holds Constructor's `request` object and the `result_id` (`SearchResults::trackingData()`). Put it on search and browse result pages only, and only when Constructor returned the results (the components skip it when there's no `result_id`, for example after a failure). `@json` escapes the output so it can't break out of the tag.

Recommendations have their own attributes; see [Strategy and tracking](#strategy-and-tracking). Sponsored results need two more; see [Sponsored and promoted results](#sponsored-and-promoted-results).

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

Caching non-personalized data, such as the category tree from `getBrowseGroups()`, globally is fine. The package can do that for you: see the next section.

### The built-in response cache

Some responses are the same for every shopper: the category tree, the facet lists and collections. The package can cache them, so a menu doesn't cost a Constructor request on every page view. It's off by default:

```env
CONSTRUCTOR_CACHE_ENABLED=true
CONSTRUCTOR_CACHE_STORE=redis              # empty = your default cache store
CONSTRUCTOR_CACHE_TTL=600                  # seconds
CONSTRUCTOR_CACHE_TTL_CATEGORIES=3600      # per-type overrides (also _FACETS, _COLLECTIONS)
```

| Cached | Never cached |
|--------|--------------|
| `getBrowseGroups()` (also the categories in `getZeroStateData()`), `getAvailableFacets()`, `getFacetsWithOptions()`, `getFacetValuesWithImages()`, `getCollections()`, `getCollection()` | Search, browse, autocomplete, recommendations, quizzes, the agents and catalog calls |

How it behaves:

- **Every option is part of the key.** Different pages, sections, `user_segments` (segments can change results through rules) and `fmt_options` get their own entries, and so do different API keys. The shopper identity overrides (`client_id`, `session_id`, `user_id`) are not part of the key, because these endpoints don't vary by shopper.
- **Failures are never cached.** If Constructor is down, the page gets an empty menu, and the next request asks again. The same applies when an image lookup inside `with_images` fails.
- **Images are shared.** With `with_images`, and for `getFacetValuesWithImages()` options without an `image_url`, the image comes from the first product. Once cached, every shopper sees the same image until the entry expires.
- **A broken cache store doesn't break the page.** Cache errors are logged and the response comes straight from Constructor.

Skip the cache for one call with `'cache' => false`, and empty it after you change categories, facets or collections in the dashboard:

```php
Constructor::getBrowseGroups(['cache' => false]);
Constructor::getCollection('summer-cookout', ['cache' => false]);

Constructor::flushCache();
```

`flushCache()` works with every cache store, including ones without tags: cache keys carry a generation number, and flushing increments it. Old entries are never read again and expire on their own TTL. Run it from a deploy script or a scheduled command when your merchandising team changes the category tree.

### Mobile apps calling your API

A native app has no browser cookies. The Constructor iOS and Android SDKs hold the client ID and session ID. Have the app send them to your API, and pass them as overrides:

```php
$results = Constructor::search($request->input('q'), [], [
    'client_id' => $request->header('X-Cnstrc-Client-Id'),
    'session_id' => (int) $request->header('X-Cnstrc-Session-Id'),
]);
```

Every storefront method accepts `client_id`, `session_id`, `user_id` and `origin_referrer` overrides. The consent rules still apply to an overridden `client_id`, and an overridden `origin_referrer` has its query string removed. For app traffic, set `CONSTRUCTOR_CLIENT_IDENTIFIER=cio-be-mobile-yourcompany`, and have the app send a User-Agent in the format given on [Required parameters](https://docs.constructor.com/docs/integrating-with-constructor-direct-api-integration-required-parameters).

### Integration checklist

Before going live:

- [ ] The beacon is on every page.
- [ ] `CONSTRUCTOR_BACKEND_TOKEN` is set, and you see no 429 responses in your logs.
- [ ] Trusted proxies are configured, so Constructor sees shoppers' IPs.
- [ ] The client ID in your server's requests matches the one in the beacon's events, for the same shopper.
- [ ] `result_id` reaches the page on search, browse and recommendation results, and search/browse pages have the `cnstrc-data` script tag.
- [ ] Sponsored results show a badge and carry their campaign attributes.
- [ ] If you need opt-in consent, `CONSTRUCTOR_REQUIRE_CONSENT=true` is set and a consent resolver is registered.
- [ ] Personalized responses are not shared between shoppers by any cache.
- [ ] `CONSTRUCTOR_CLIENT_IDENTIFIER` uses your company name.

---

## 3. Core concepts

### Entry point and services

`Constructor` (the facade) is the entry point. Storefront methods are available directly. The AI agents, quizzes, catalog management and offline behavioral actions have their own services:

```php
use ConstructorIO\Laravel\Facades\Constructor;

Constructor::search('smoker');                    // SearchService
Constructor::getRecommendations('home-bestsellers'); // RecommendationService
Constructor::agent()->askShoppingAgent('...');    // AgentService
Constructor::catalog()->uploadCatalog('...');     // CatalogService
Constructor::quizzes()->getNextQuestion('...');   // QuizService
Constructor::behavior()->recordOfflinePurchases([...]); // BehaviorService
```

| Service | Responsibility |
|---------|----------------|
| `Services\SearchService` | Search, natural language search, browse, browse by item ID, autocomplete, zero state, facets, categories, collections, recipes |
| `Services\RecommendationService` | Recommendation pods |
| `Services\AgentService` | AI Shopping Agent and Product Insights Agent |
| `Services\CatalogService` | Catalog uploads, item updates, task status (uses the API token) |
| `Services\QuizService` | Quiz questions, results and results page settings |
| `Services\BehaviorService` | Offline purchases and returns (uses the API token) |
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
| `parameters` | array | Any other API parameter, passed through unchanged (it can't replace `key` or identity values) |
| `user_id`, `client_id`, `session_id`, `origin_referrer` | | Identity overrides (see [section 2](#mobile-apps-calling-your-api)) |

The full list is documented on `Support\ParameterBuilder`. `parameters` is the escape hatch for anything new in Constructor's API that this package doesn't name yet.

Filter values must be scalars (strings, numbers, booleans). Anything else, such as a nested array from unvalidated request input, is ignored rather than sent. Validate request input before passing it in; see [A complete search controller](#a-complete-search-controller).

### Option names

The same names mean the same thing everywhere:

| Option | Meaning | Used by |
|--------|---------|---------|
| `page`, `per_page` | Paged results (1-based page; `per_page` capped per endpoint) | Search, browse, collections, facets with options, quizzes, catalog lists |
| `limit` | How many to return, for lists that aren't paged | Recommendations, category tree, agent questions, autocomplete sections |
| `*_limit` | A limit for one part of a response | `children_limit`, `categories_limit`, `products_limit` |

Constructor's own names and the 1.x names still work: `num_results` for `limit`, and `max_items` / `max_children` for the category tree and collections.

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
    'is_slotted' => false,        // pinned in place by a merchandising rule
    'is_sponsored' => false,      // an ad (labels.is_retail_media or labels.is_sponsored)
    'sponsored_campaign_id' => null,
    'sponsored_campaign_owner' => null,
    'labels' => [],               // all labels Constructor sent, unmodified
    'variation_slice' => null,    // when variation slicing is enabled
    'strategy' => null,           // recommendations only, e.g. ['id' => 'complementary_items']
    '_raw' => [...],              // every catalog field, including your custom ones
]
```

Fields you added to your catalog (BTU rating, cooking area, warranty...) are in `_raw`:

```php
$product['_raw']['cooking_area_sq_in'] ?? null;
```

### Sponsored and promoted results

When `is_slotted` is true, a merchandising rule pinned the product in place. That is not an advertisement.

When `is_sponsored` is true, the product is a [Sponsored Listing](https://docs.constructor.com/docs/products-retail-media-learn-about-sponsored-listings-implement-sponsored-listings): Constructor flags it with `labels.is_retail_media` (some response docs use `labels.is_sponsored`; the package checks both). Show a "Sponsored" badge (your region may have legal requirements for the wording), and put the campaign details on the result element so the beacon can attribute the ad's views and clicks. `<x-constructor::item>` adds `data-cnstrc-sl-campaign-id` and `data-cnstrc-sl-campaign-owner` whenever the product has them:

```blade
<x-constructor::item :product="$product">
    @if ($product['is_sponsored'])
        <span class="badge">Sponsored</span>
    @endif
    ...
</x-constructor::item>
```

If you use a third-party retail media platform through Constructor, its tracking properties are in `$product['labels']`.

### Return types

Storefront methods return readonly DTOs: `SearchResults`, `AutocompleteResults` and `RecommendationResults`, plus `QuizQuestion` and `QuizResults` for quizzes. All of them have `toArray()` for JSON responses. See [DTO reference](#16-dto-reference).

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

### Natural language and voice search

For conversational queries, typically from voice input, use `naturalLanguageSearch()`. Convert the speech to text in the browser or app first. Constructor strips filler words and turns phrases it recognises ("under 500 dollars", "in black") into filters:

```php
$results = Constructor::naturalLanguageSearch('show me a gas grill for under 500 dollars', [], ['per_page' => 24]);
```

It takes the same filters and options as `search()` and returns the same `SearchResults`, including redirects, `resultId` and `trackingData()`. See [Natural language search](https://docs.constructor.com/reference/v1-search-get-natural-language-search-results).

### Search by image

[Image Search](https://docs.constructor.com/docs/products-search-learn-about-image-search) finds products that look like a photo the shopper took or uploaded: "I want a grill like this one". Constructor enables it per account; ask your Customer Success Manager. Requests go to `CONSTRUCTOR_IMAGE_SEARCH_BASE_URL` (default `https://image-search.cnstrc.com`) and carry the shopper's identity like other storefront calls.

```php
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Http\Request;

class ImageSearchController
{
    public function __invoke(Request $request)
    {
        // Image Search accepts JPEG, PNG, WebP and GIF up to 6 MB. HEIC (the
        // iPhone default) is not supported, so ask iOS apps to send JPEG.
        $request->validate(['photo' => 'required|image|mimes:jpeg,png,webp,gif|max:6144']);

        try {
            $results = Constructor::searchByImage($request->file('photo'), [
                'use_segmentation' => true,   // a photo of a patio: search for each object in it
            ]);
        } catch (InvalidArgumentException $e) {
            // The file's contents aren't a supported image, whatever its extension says.
            return back()->withErrors(['photo' => 'Please upload a JPEG, PNG, WebP or GIF image.']);
        }

        if ($request->filled('brand')) {
            $results = Constructor::filterImageResults($results, ['brand' => $request->input('brand')]);
        }

        return view('search.image', ['results' => $results]);
    }
}
```

What you can pass:

| Argument | Meaning |
|----------|---------|
| `UploadedFile` | A shopper's upload, as in the controller above |
| `new SplFileInfo($path)` | A file on your server that you trust |
| A string | The image bytes, e.g. `Storage::get($path)` or a base64-decoded upload from a mobile app. **Never** read as a path, so shopper input can't make your server send one of its own files. |

The image is checked from its contents (not its name or MIME type, which the shopper controls) before anything is sent. A missing, empty, oversized or unsupported image throws `InvalidArgumentException`, even when `throw_exceptions` is off: it's a problem to show the shopper, not an outage.

Options:

| Option | Meaning |
|--------|---------|
| `section` | Index section (default `CONSTRUCTOR_SECTION`) |
| `use_segmentation` | Detect several objects in the photo and search for each; results are merged |
| `return_segments` | With `use_segmentation`: describe each detected object in `metadata['segments']` (bounding box, class name, confidence and its own matches) |
| `search_crop_type` | With `use_segmentation`: `bbox` (default) crops each object as a rectangle, `segment` removes the background |
| `variations_map`, `fmt_options`, `hidden_fields`, `user_segments` | As for search |

The result is a normal `SearchResults` with the matches in `products`, plus `metadata['browse_url']`, `metadata['segmentation_used']` and `metadata['whole_image_search_fallback']` (segmentation was asked for but failed, so the whole image was searched). Image Search returns one list: there are no pages, sorting or filters.

To filter, sort or page through the matches, use `filterImageResults()`. As Constructor recommends, it browses the matched items with your filters through `/browse/items` (the same request `browse_url` describes), so it accepts the same filters and options as `browseItems()`. Only the first 100 matches are used, the most that endpoint accepts.

```php
$inStockWeber = Constructor::filterImageResults($results, ['brand' => 'Weber', 'in_stock' => true], ['sort_by' => 'price']);
```

Put `resultId` on the results container for tracking, as on other result pages. Constructor's tracking guide doesn't yet describe image search pages specifically.

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

Constructor returns at most 10,000 results for one query (`SearchResults::MAX_RETRIEVABLE_RESULTS`), however many match. `totalPages()` and `hasMore()` stop at the last page that can actually be fetched, so pagination links never point past it. `$results->total` still reports every match; when `$results->exceedsRetrievableLimit()` is true, suggest a filter or a narrower query.

`offset` works too: with `['offset' => 48, 'per_page' => 24]`, `$results->page` is 3.

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
        // Validate anything from the request before it becomes a filter.
        // ?filters[brand][]=Weber&filters[fuel_type][]=Gas
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'filters' => ['array'],
            'filters.*' => ['array'],
            'filters.*.*' => ['string', 'max:200'],
            'sort_by' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'in:ascending,descending'],
            'price_min' => ['nullable', 'numeric'],
            'price_max' => ['nullable', 'numeric'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));

        $results = Constructor::search($query, $validated['filters'] ?? [], [
            'page' => $request->integer('page', 1),
            'per_page' => 24,
            'sort_by' => $validated['sort_by'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 'descending',
            'range_filters' => array_filter([
                'price' => array_filter([
                    'min' => $validated['price_min'] ?? null,
                    'max' => $validated['price_max'] ?? null,
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
    'limit' => 10,          // top-level categories
    'children_limit' => 6,  // children per category
    'with_images' => true,  // see below
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
| `depth` | How many levels to return. By default: one level, plus one to skip the "All" root (unless you pass `group_id`), plus one when `children_limit` is above 0 |
| `fmt_options` | Other formatting options; merged with the depth setting |
| `section` | Index section |

**Images.** A category's image comes from the `image_url` field in its data, if you set one in your item groups. `with_images => true` fills missing images with the first product's image, which costs **one extra request per category**. Cache the result:

```php
$navigation = Cache::remember('nav:categories', now()->addHour(), fn () =>
    Constructor::getBrowseGroups(['limit' => 8, 'with_images' => true])
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

// At most 100 IDs per call (Constructor's limit). More throws
// ConstructorIO\Laravel\Exceptions\InvalidArgumentException before any request is sent.
```

### Badges and enriched attributes on a product page

`browseItems()` returns a product's catalog data in the same shape as search results. Product details return something different: what Constructor has *added* to the product, from [Attribute Enrichment](https://docs.constructor.com/docs/products-attribute-enrichment-learn-about-attribute-enrichment) and [badges](https://docs.constructor.com/docs/products-attribute-enrichment-learn-about-badges-implement-badges). Badges must be enabled for your account by your Constructor contact.

```php
$details = Constructor::productDetails()->getItem($product->sku);
```

```blade
@foreach ($details['badges'] ?? [] as $badge)
    <span class="badge badge-{{ $badge['type'] }}">{{ $badge['display_name'] }}</span>
@endforeach

@foreach ($details['attribute_values'] ?? [] as $name => $values)
    <dt>{{ Str::headline($name) }}</dt><dd>{{ implode(', ', $values) }}</dd>
@endforeach
```

Each entry has:

| Key | Meaning |
|-----|---------|
| `id` | The item ID (the variation ID for `getVariations()`) |
| `variation_id` | The variation ID, or `null` for items |
| `badges` | `type`, `display_name` (placeholders already filled in) and `metadata` (e.g. `times_was_bought_last_week => '63'`) |
| `attributes` | Name → list of `['value' => ..., 'type' => ...]`, where type is `standard` or `extended`. `extended` values come from enrichment outside your own taxonomy. |
| `attribute_values` | Name → list of values only, for display |
| `_raw` | The entry as Constructor returned it |

Details:

- **Limits:** `getItems()` takes up to 20 IDs and `getVariations()` up to 100. More throws `InvalidArgumentException` before any request. Results come back in the order you asked, including repeated IDs.
- **Options:** `section` (default `CONSTRUCTOR_SECTION`), `fields` (default and currently only `['enriched_attributes']`), plus the identity overrides.
- **Failures:** like other storefront reads, a failure logs a warning and returns `[]` (or `null` from `getItem()`), unless `CONSTRUCTOR_THROW_EXCEPTIONS=true`.
- **Listing pages:** badges come in each result's `labels`, so you don't need this service there.

### A category page controller

```php
public function show(Request $request, string $category)
{
    $validated = $request->validate([
        'filters' => ['array'],
        'filters.*' => ['array'],
        'filters.*.*' => ['string', 'max:200'],
    ]);

    $results = Constructor::browse('group_id', $category, $validated['filters'] ?? [], [
        'page' => $request->integer('page', 1),
        'per_page' => 24,
        'sort_by' => $request->input('sort_by'),
    ]);

    abort_if($results->total === 0 && $results->page === 1, 404);

    return view('category.show', [
        'category' => $category,
        'results' => $results,
        'subcategories' => Constructor::getBrowseGroups(['group_id' => $category, 'limit' => 20]),
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

### Filtering one section

`filters` and `pre_filter_expression` apply to every section. To narrow only the product previews (for example, to in-stock items on a store's own site), key them by section name:

```php
Constructor::autocomplete('smoker', [
    'section_filters' => ['Products' => ['in_stock' => true, 'brand' => ['Traeger', 'Weber']]],
]);

Constructor::autocomplete('smoker', [
    'section_pre_filter_expression' => ['Products' => ['name' => 'store_id', 'value' => 'austin-tx']],
]);
```

These are sent as `filters[Products][in_stock]=True` and `pre_filter_expression[Products]={...}`. You can combine `filters` with `section_filters`, but not `pre_filter_expression` with `section_pre_filter_expression`: that throws `InvalidArgumentException`. Filtering the "Search Suggestions" section needs extra configuration on Constructor's side; ask your Constructor contact.

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
// [['name' => 'brand', 'display_name' => 'Brand', 'type' => 'checkbox_list'], ...]
```

Every facet in the index is returned (the endpoint is paged; the package asks for up to 1,000 at once). `type` uses the same names as facets in results: `checkbox_list`, `single_select`, `range` or `hierarchical`.

Hidden facets and the category facet (`group_id`) are left out; use `getBrowseGroups()` for categories. To include hidden facets, for example a store-specific one:

```php
Constructor::getAvailableFacets(['fmt_options' => ['show_hidden_facets' => true]]);
```

### Facets with their options, without a search

`getFacetsWithOptions()` returns facets together with their options for a whole section, so you can build a filter sidebar or a "Shop by" menu without running a search first:

```php
$page = Constructor::getFacetsWithOptions(['per_page' => 10]);

$page['facets'];      // same shape as $results->facets
$page['total'];       // facets in the section
$page['has_more'];    // fetch ['page' => 2] for the next ones
$page['result_id'];
```

Constructor returns at most 10 facets per request, so `per_page` is capped at 10; use `page` (or `offset`) for the rest. Counts cover the whole section. Hidden facets need `fmt_options`: `show_hidden_facets`, `hidden_facets` (a list), `show_hidden_facet_options` or `show_protected_facets`. Like other storefront reads, a failure logs a warning and returns an empty page. See [Browse facets with options](https://docs.constructor.com/reference/v1-browse-get-browse-facets-with-options).

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
$bestsellers = Constructor::getRecommendations('home-bestsellers', ['limit' => 8]);

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
$accessories = Constructor::getItemRecommendations('pdp-complementary', 'TRG-PRO780', ['limit' => 6]);

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
| `limit` | All (default 8; `num_results` also works) | `'limit' => 4` |
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

Each recommended product includes the `strategy` that picked it. The beacon needs the pod ID, `resultId`, the number of results and the seed item IDs (for item-based pods) on the container, and the strategy on each item. The components write all of it:

```blade
<x-constructor::recommendations :results="$accessories" seed-items="TRG-PRO780" tag="section">
    <h2>{{ $accessories->title }}</h2>
    @foreach ($accessories->products as $product)
        <x-constructor::recommendation-item :product="$product">
            <a href="{{ $product['url'] }}">{{ $product['name'] }}</a>
            <x-constructor::button action="add_to_cart">Add to cart</x-constructor::button>
        </x-constructor::recommendation-item>
    @endforeach
</x-constructor::recommendations>
```

`seed-items` takes one ID or a list, e.g. `:seed-items="$cartItemIds"` for a cart pod; it's sent comma-separated. Leave it out for pods that don't use an item (best sellers, trending). When a pod fills a zero-state search box, add `section="Products"` (or `section="Search Suggestions"`) to each `recommendation-item`.

The rendered markup, if you write it by hand:

```blade
<section data-cnstrc-recommendations
         data-cnstrc-recommendations-pod-id="{{ $accessories->podId }}"
         data-cnstrc-result-id="{{ $accessories->resultId }}"
         data-cnstrc-num-results="{{ $accessories->total }}"
         data-cnstrc-recommendations-seed-items="TRG-PRO780">
    <div data-cnstrc-item="recommendation"
         data-cnstrc-item-id="{{ $product['id'] }}"
         data-cnstrc-item-name="{{ $product['name'] }}"
         data-cnstrc-strategy-id="{{ $product['strategy']['id'] }}"
         data-cnstrc-item-price="{{ $product['price'] }}">
        ...
    </div>
</section>
```

As with search results, `data-cnstrc-item-variation-id` is only set for a face-out variation, and `data-cnstrc-item-price` is the lower of sale and list price, left out when there's no price. See [Data-driven event tracking](https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking#recommendations).

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
            ? Constructor::getItemRecommendations($this->pod, $this->itemId, ['limit' => $this->limit])
            : Constructor::getRecommendations($this->pod, ['limit' => $this->limit]);

        return view('components.recommendations', ['recommendations' => $recommendations]);
    }
}
```

`resources/views/components/recommendations.blade.php`:

```blade
@if ($recommendations->hasRecommendations())
    <x-constructor::recommendations :results="$recommendations" :seed-items="$itemId" tag="section">
        <h2>{{ $recommendations->title }}</h2>
        @foreach ($recommendations->products as $product)
            <x-constructor::recommendation-item :product="$product">
                <a href="{{ $product['url'] }}">{{ $product['name'] }}</a>
            </x-constructor::recommendation-item>
        @endforeach
    </x-constructor::recommendations>
@endif
```

```blade
<x-recommendations pod="pdp-complementary" :item-id="$product['id']" :limit="6" />
```

If the pod fails or doesn't exist, the result is empty (and logged), so check `$recommendations->hasRecommendations()` in the view.

### Every pod on a page in one request

[Retrieve by page reference](https://docs.constructor.com/reference/v1-recommendations-get-page-results). This endpoint is in **beta**; ask your Constructor contact to enable it.

A recommendation page groups pods in the dashboard, for example a product page with "Similar grills", "Complete your cookout" and "Recently viewed". `getRecommendationPage()` fetches them all at once, and Constructor removes duplicates across the pods, so a product appears in only one of them (unless it is slotted).

```php
$page = Constructor::getRecommendationPage('pdp', [
    'item_id' => 'TRG-PRO780',     // shared by every pod on the page
    'limit' => 6,                  // per pod; omit to use Constructor's default
    'pod_overrides' => [
        'pdp-similar' => ['limit' => 12, 'filters' => ['in_stock' => true]],
    ],
]);

$page->title;       // the page's display name
$page->pageType;    // e.g. "pdp" or "home"
$page->pods;        // RecommendationResults per pod, in the order set in the dashboard
$page->pod('pdp-complementary');   // one pod by ID, or null
$page->podsWithResults();          // skips pods that returned nothing
```

Every option applies to all pods (`item_id`, `variation_id`, `term`, `filters`, `section`, `user_segments`...), except what you set in `pod_overrides`. A pod override can only set `num_results`, `filters` (and `range_filters`), `filter_match_types`, `pre_filter_expression`, `fmt_options` (and `hidden_fields`) and `variations_map`; anything else throws `InvalidArgumentException` before the request is sent, because Constructor would reject it. An override **replaces** the page-wide value for that pod rather than merging with it, so repeat any page-wide filter the pod still needs.

**Tracking:** each pod is its own recommendations container. Use the pod's `resultId`, not the page's: `$page->resultId` identifies the page request and is not a beacon ID.

```blade
@foreach ($page->podsWithResults() as $pod)
    {{-- $itemBasedPods: the pod IDs on this page whose strategy uses the item --}}
    <x-constructor::recommendations :results="$pod" tag="section"
        :seed-items="in_array($pod->podId, $itemBasedPods, true) ? 'TRG-PRO780' : null">
        <h2>{{ $pod->title }}</h2>
        @foreach ($pod->products as $product)
            <x-constructor::recommendation-item :product="$product">
                <a href="{{ $product['url'] }}">{{ $product['name'] }}</a>
                <x-constructor::button action="add_to_cart">Add to cart</x-constructor::button>
            </x-constructor::recommendation-item>
        @endforeach
    </x-constructor::recommendations>
@endforeach
```

Leave out the seed items for pods whose strategy doesn't use the item (best sellers, recently viewed). If the request fails, you get an empty page (logged), and `$page->isEmpty()` is true.

---

## 9. Collections

Collections are curated product lists set up in the dashboard, such as "Summer cookout essentials" or "Holiday gift guide". The package uses Constructor's public browse endpoints for collections, so no API token is needed on the storefront.

### List collections

```php
$collections = Constructor::getCollections(['per_page' => 12]);

foreach ($collections as $collection) {
    echo $collection['name'];        // "Summer Cookout Essentials"
    echo $collection['image'];       // data.image_url, if you set one
}
```

Each collection has `id`, `name`, `description` and `image` (both taken from the collection's custom data), `data`, `start_time`, `end_time` and `active_overriding_collection` (the collection currently shown in its place, when you use content scheduling). Options: `max_items`, `page`, `sort_by` (`id` or `display_name`), `sort_order` (default `descending`) and `section`.

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
                // Forward an allowlist only. Never forward 'complete' (it carries
                // _raw, which can include hidden catalog fields such as cost
                // prices) or events you haven't reviewed.
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

**Render agent output safely.** The agent's text, product names and links can be influenced by what shoppers type (prompt injection). Insert text with `textContent` (as above), never `innerHTML`, and check that any URL you render is `https:` and points to a domain you expect before using it in a link or image.

**Timeouts.** `CONSTRUCTOR_STREAM_TIMEOUT` (default 120) is the **total** time an agent answer may take, including keep-alive pings; after that the package stops reading and throws `ConnectionException`. It also throws `ConnectionException` if the stream is cut off before Constructor's `end` event, so you never mistake a partial answer for a complete one. Make sure your PHP-FPM / web server timeouts allow the full duration.

### Product Insights Agent

[Product questions API reference](https://docs.constructor.com/reference/v1-asa-retrieve-item-questions)

On product pages, suggest questions and answer them with AI, using the product's data:

```php
$agent = Constructor::agent();

$suggested = $agent->getProductQuestions('TRG-PRO780', ['limit' => 4]);
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

Both methods accept `variation_id`, `guard`, `limit` (`num_results` also works), `user_segments` and identity overrides.

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

### Agent Insights

[Agent insights API reference](https://docs.constructor.com/reference/v1-asa-retrieve-agent-insights-search)

Agent Insights puts AI answers inside the pages shoppers already use. There are five modes; every one takes the same options and returns an `AgentInsight`:

| Method | Mode | Use it for | On failure |
|--------|------|-----------|------------|
| `searchOverview($query)` | `search` | A short AI overview above search results | Logs, returns empty |
| `chat($message)` | `chat` | One turn of a conversation | Throws |
| `answerQuestion($question, $itemId)` | `qna` | The question box on a product page | Throws |
| `recommendations($itemId, $strategy)` | `recommendations` | A curated, explained recommendation widget | Logs, returns empty |
| `refineListing($listing, $refinement)` | `query_refinement` | "Only white", "under $200" on a listing page, plus refinement chips | Logs, returns empty |

```php
$overview = Constructor::insights()->searchOverview($query, [
    'page_context' => ['page_type' => 'plp', 'search_term' => $query],
]);

$overview->status;          // complete | partial | declined | unavailable
$overview->message;         // the overview text
$overview->itemResults;     // sets of products, each with the agent's title and text
$overview->products();      // every product; each has the result_id of its set, for click tracking
$overview->articles;        // knowledge base articles cited: url, title, image_url, abstract
$overview->followUpOptions();
$overview->resultId;        // send with agent tracking events
$overview->threadId;        // pass back as thread_id to continue
```

**Declined queries.** Constructor can decide a query is better served by traditional search, and answers HTTP 422. That isn't an error: you get `isDeclined() === true` (nothing is logged), and you render your normal results. `chat` can decline too; `query_refinement` never does.

**Where the shopper is.** `page_context` tells the agent which page the request came from. Friendly keys work like the rest of the package:

```php
'page_context' => [
    'page_type' => 'plp',                     // home | plp | pdp | collection | email_campaign | cart
    'search_term' => 'pellet grill',
    'group_id' => 'grills',                   // or collection_id: the browse filter
    'filters' => ['brand' => ['Traeger', 'Weber'], 'in_stock' => true],
    'range_filters' => ['price' => ['min' => 300, 'max' => 900]],
    'filter_match_types' => ['brand' => 'all'],
],
```

Constructor's own shape (`browse_filter` and a list of filter expressions) is accepted too, so a `refinedListing()` can be sent back unchanged. `page_type` defaults to `home`, or to `pdp` for `answerQuestion()` and `recommendations()`.

**Other options**, shared by every mode: `thread_id`, `guard` (default `CONSTRUCTOR_AGENT_GUARD`), `num_result_events`, `num_results_per_event`, `fmt_options`, `hidden_fields`, `qs` (parameters forwarded to the agent's own search, browse and recommendations calls, e.g. `['sort_by' => 'price']`), `user_segments`, `features`, `feature_variants` and the identity overrides. Leave `num_result_events` unset for overviews unless you mean to drop product sets.

**Mode-specific options:**

- `chat()`: `domain` (defaults to `CONSTRUCTOR_AGENT_DOMAIN`). `chat()` and `answerQuestion()` accept `prices`, request-only member or quantity prices the agent should quote; Constructor only accepts these where it has enabled request context for you.
- `recommendations()`: `$strategy` is the routing rule configured for your index key (for example `complementary_pod`), not a Recommendations API pod ID. Pass the option the shopper picked as `refinement`, and `default_title` (at most 100 characters) for the non-personalized set.
- `refineListing()`: `$listing` needs a `search_term`, a `group_id`, or both. The service keeps no filter state, so send `$refined->refinedListing()` as the next listing with the same `thread_id`. Leave `$refinement` null when the page opens, after a chip is applied and after the shopper changes filters. Chips follow the shopper's history, so don't cache them across shoppers.

Answers take longer than a search, so these requests use `CONSTRUCTOR_STREAM_TIMEOUT`. They are never retried: a repeat would add a second turn to the shopper's thread.

#### Streaming

Each method has a `*Streaming()` twin (`searchOverviewStreaming()`, `chatStreaming()`, `answerQuestionStreaming()`, `recommendationsStreaming()`, `refineListingStreaming()`) that passes Constructor's events to your callback as they arrive: `start`, `message`, `item_results` (with a ready-made `products` list added), `follow_up_refinement`, `article_reference`, `image_meta`, `recipe_info`, `recipe_instructions` and `end`. They return `false` when Constructor declined the input, and throw on `server_error` or a stream that ends early.

```php
return response()->stream(function () use ($query) {
    Constructor::insights()->searchOverviewStreaming($query, function (string $event, array $data) {
        if (in_array($event, ['message', 'item_results', 'end'], true)) {
            echo "event: {$event}\ndata: ".json_encode($data)."\n\n";
            ob_flush(); flush();
        }
    });
}, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
```

Render agent text as text, not HTML, as with the Shopping Agent.

**Tracking.** Constructor's JavaScript tracker records agent events (`trackAgentResultView`, `trackAgentResultClick`, ...). Pass `resultId` to the page for those, and each product's `result_id` with clicks on it. See [Implement AI Shopping Agent](https://docs.constructor.com/docs/products-ai-shopping-agents-learn-about-ai-shopping-agent-implement-ai-shopping-agent).

---

## 11. Behavioral tracking

Constructor ranks and personalizes results by learning from shopper behavior. **This package does not send tracking events.** The Constructor JavaScript beacon does, in the shopper's browser, using the [data attributes](https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking) on your pages.

What the server side must do:

1. **Expose `result_id`** from every search, browse, autocomplete and recommendation response to the page, as shown in [section 2](#pass-result_id-to-the-page). It is required on results-loaded events. On search and browse pages, also add the `cnstrc-data` script tag (`<x-constructor::search-results>` and `<x-constructor::browse-results>` add it; by hand, `@json($results->trackingData())`).
2. **Use the same client and session IDs as the beacon.** The package does this by reading the beacon's cookies (see [section 2](#shopper-identity-what-is-sent-with-each-request)).
3. **Mark up results** with item ID, name, variation ID and price (`data-cnstrc-item-*`), and conversion buttons with `data-cnstrc-btn="add_to_cart"`, so clicks and add-to-cart events can be attributed. Recommendations also need `data-cnstrc-item="recommendation"` and `data-cnstrc-strategy-id` ([section 8](#strategy-and-tracking)).
4. **Sponsored results** need `data-cnstrc-sl-campaign-id` and `data-cnstrc-sl-campaign-owner` ([section 3](#sponsored-and-promoted-results)).
5. **Product pages** need `data-cnstrc-product-detail` with the item ID, name, variation and price on the detail container (`data-cnstrc-product-detail-modal` for a quick view).

The `<x-constructor::...>` Blade components handle points 1, 3, 4 and 5 (see [section 2](#pass-result_id-to-the-page)):

```blade
<x-constructor::product-detail :product="$product">
    <h1>{{ $product['name'] }}</h1>
    <x-constructor::button action="add_to_cart">Add to cart</x-constructor::button>
    <x-constructor::button action="add_to_wishlist">Save</x-constructor::button>
</x-constructor::product-detail>
```

`product` can be a result from any results DTO or a catalog item from `Constructor::catalog()->getItem()`. Add `modal` for a quick-view dialog.

For AI agent results, track agent views and clicks with the JavaScript client's `trackAgent*` methods, using the `intent_result_id` and per-product `result_id` returned by `askShoppingAgent()`.

To check your tracking, compare, for one shopper, the client ID in a results request with the client ID in the beacon's events, and confirm the `result_id` in a results-loaded event matches the one in the response.

### Offline purchases and returns

[Offline behavioral actions API reference](https://docs.constructor.com/reference/v1-offline-behavioral-actions-create-actions)

Purchases and returns that happen away from the website (in store, by phone, through a sales rep) never reach the beacon. Send them with `BehaviorService` so Constructor learns from them too. Like catalog management, this endpoint uses `CONSTRUCTOR_API_TOKEN`, so call it from a queued job or a scheduled command.

```php
$behavior = Constructor::behavior();

$behavior->recordOfflinePurchases([[
    'order_id' => 'POS-10293',                  // recommended: events with the same order ID are merged
    'occurred_at' => $sale->completed_at,       // DateTimeInterface, date string, or `_dt` in Unix seconds (required)
    'user_id' => $sale->customer_id,            // the `ui` your website sends for this customer
    'store_id' => 'austin-tx',
    'items' => [                                // 1-500 items; item_id or item_name is required
        ['item_id' => 'TRG-780', 'price' => 999.99, 'count' => 1],
        ['item_id' => 'PELLETS-HICKORY', 'variation_id' => 'PELLETS-HICKORY-20LB', 'price' => 19.99, 'count' => 3],
    ],
]]);

$behavior->recordOfflineReturns([[
    'order_id' => 'POS-10293',
    'occurred_at' => now(),
    'items_returned' => [['item_id' => 'PELLETS-HICKORY', 'count' => 1]],   // required
    'items_kept' => [['item_id' => 'TRG-780']],
    'return_type' => 'partial',                 // full | partial
    'return_preference' => 'refund',            // refund | replacement
    'return_reason' => 'Wrong flavor',
]]);
```

Other documented fields: `revenue`, `lat` and `lon` on purchases; `items_cross_sold`, `revenue`, `return_expenses`, `refund`, `return_shipping_cost`, `return_drop_off`, `lat` and `lon` on returns. `recordOfflineActions()` accepts a mix of both as `['action' => 'purchase'|'return', 'data' => [...]]`.

- **Validation first.** Every action is checked (time, item lists, enum values) before anything is sent. A mistake throws `InvalidArgumentException` and nothing is sent.
- **Batches of 100.** Constructor accepts at most 100 actions per request, so larger lists are split. Requests are never retried. If one fails, the batches before it were already accepted; because events with the same `order_id` are merged, re-sending the whole list is safe when every action has one.
- **Match `ui`.** If you anonymize user IDs with `Constructor::resolveUserIdUsing()`, hash offline user IDs the same way, or Constructor sees two different people.
- The methods return the number of actions sent, and throw `ApiException` or `ConnectionException` on failure.

```php
// A scheduled command that sends yesterday's store sales
$sales = Sale::query()
    ->where('channel', 'store')
    ->whereDate('completed_at', today()->subDay())
    ->with('lines')
    ->get();

Constructor::behavior()->recordOfflinePurchases($sales->map(fn (Sale $sale) => [
    'order_id' => $sale->number,
    'occurred_at' => $sale->completed_at,
    'user_id' => $sale->customer_id,
    'store_id' => $sale->store_code,
    'items' => $sale->lines->map(fn ($line) => [
        'item_id' => $line->sku,
        'price' => (float) $line->unit_price,
        'count' => $line->quantity,
    ])->all(),
])->all());
```

### Shopper preferences

Some things you know about a shopper last longer than a session: their preferred fuel type, the size of grill they cook on, their loyalty tier, answers to a "what do you cook?" survey. Constructor's [User profile API](https://docs.constructor.com/reference/user-profile-user-preferences) takes these as preferences and uses them for personalization, segmentation and experiments.

```php
// In a controller, after the shopper saves a preferences form:
$saved = Constructor::preferences()->setForCurrentUser('fuel_type', $request->input('fuel_types'));
```

`setForCurrentUser()` sends the same `ui` as your storefront requests (`auth()->id()`, or your `resolveUserIdUsing()` resolver). It returns `false` and sends nothing when nobody is signed in or the shopper hasn't consented to personalization (`resolveConsentUsing()`, `CONSTRUCTOR_REQUIRE_CONSENT`).

From jobs and commands:

```php
use ConstructorIO\Laravel\Facades\Constructor;

// One preference for one user:
Constructor::preferences()->setUserPreference($hashedUserId, 'loyalty_tier', 'gold');

// Many at once: validated first, then sent 100 per request.
Constructor::preferences()->setPreferences(
    $customers->map(fn ($customer) => [
        'user_id' => hash_hmac('sha256', (string) $customer->id, config('app.key')),
        'preference_key' => 'loyalty_tier',
        'preference_values' => [$customer->loyalty_tier],
    ])->all()
);

// A full backfill: one {"preference_key", "preference_values", "ui"} object per line, optionally gzipped.
Constructor::preferences()->uploadPreferencesFile(storage_path('exports/preferences.jsonl.gz'));
```

Things to get right:

- **The token.** This API only accepts Bearer authentication, with a dashboard API token that has the `user_profile.user_preferences(w)` scope (Integration > API Integration > Add new). Set it as `CONSTRUCTOR_USER_PROFILE_TOKEN`, so the token can't do anything else. The API token is never used in its place. A missing scope gives a 403 `AuthenticationException`.
- **The same `ui` everywhere.** Preferences only help if they're keyed by the exact `ui` your storefront sends. If `resolveUserIdUsing()` hashes IDs, hash them the same way in batch jobs, as in the example above.
- **Personal data.** Only send preferences shoppers agreed you may use for personalization. The package never logs preference values. The API has no endpoint to read or delete preferences, so agree with Constructor how a deletion (right-to-erasure) request is handled.
- **Session facts don't belong here.** For temporary facts such as campaign source or location, use the `user_segments` option on search, browse and recommendation calls.
- **Failures.** Writes throw (`AuthenticationException`, `ApiException`, `ConnectionException`) and are never retried. If one batch fails, the earlier batches have already been saved.

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
| `indexed` | Whether the search index has been rebuilt with the change (`result.index_built`), or `null` if Constructor didn't say |

`DONE` means the change was ingested, not necessarily that it is searchable yet. If you need to know when shoppers can see it, check `indexed`.

The final status check happens at the deadline, so a task that finishes in the last poll interval is still reported as done. Task IDs must be positive integers; anything else throws `InvalidCatalogDataException`.

### Update individual items

For targeted changes to specific products, use the items API instead of files. (For a constant stream of small changes, see [the Catalog Batching Service](#high-frequency-updates-the-catalog-batching-service).)

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

Each method takes an optional `$section` and `$options` (`force`, `notification_email`; `on_missing` for `patchItems`). Lists are sent in batches of `CONSTRUCTOR_CATALOG_BATCH_SIZE` (`catalog.batch_size`, default 1,000, at most 10,000; the older `scout.batch_size` key is still read), and the methods return one task result per batch.

Writes and uploads are sent **exactly once**: a retried write after a timeout could start a second ingestion task. Catalog reads (`getItem()`, `listItems()`, `getTaskStatus()`) are retried like storefront calls. Items without an `id` (or a `name`, for `upsertItems`) throw `InvalidCatalogDataException` before anything is sent.

### Read items

```php
$item = $catalog->getItem('TRG-PRO780');   // null if it doesn't exist

$page = $catalog->listItems(options: ['page' => 1, 'per_page' => 100]);
// ['items' => [...], 'total_count' => 5321, 'next_page_cursor' => null]

$some = $catalog->listItems(options: ['ids' => ['TRG-PRO780', 'WEB-SPIRIT-E310']]);
```

For very large catalogs, walk with `next_page_cursor` instead of `page`.

### Variations

[Variations API reference](https://docs.constructor.com/reference/v2-variations-create-or-replace-variations)

Variations are the purchasable versions of an item: fuel types, sizes, colors. They work like items, and each one names its parent item with `item_id`:

```php
// Create or fully replace. id and item_id are required; name is optional.
$catalog->upsertVariations([
    ['id' => 'TRG-PRO780-LP', 'item_id' => 'TRG-PRO780', 'fuel_type' => 'Propane', 'price' => 999.99],
    ['id' => 'TRG-PRO780-NG', 'item_id' => 'TRG-PRO780', 'fuel_type' => 'Natural Gas', 'price' => 1049.99],
]);

// Update fields of existing variations. Only id is required.
$catalog->patchVariations([['id' => 'TRG-PRO780-NG', 'in_stock' => false]]);

$catalog->deleteVariations(['TRG-PRO780-OLD']);   // the parent item stays

$variation = $catalog->getVariation('TRG-PRO780-LP');                    // null if it doesn't exist
$page = $catalog->listVariations(options: ['item_ids' => ['TRG-PRO780']]);
// ['variations' => [...], 'total_count' => 2, 'next_page_cursor' => null]
```

The methods take the same `$section` and `$options` as their item equivalents (`force`, `notification_email`; `on_missing` for `patchVariations`), are batched the same way, and return one task result per batch. `listVariations()` also accepts `ids`, `page`, `per_page` (up to 1,000) and `next_page_cursor`.

### Item groups (categories)

[Item groups API reference](https://docs.constructor.com/reference/v2-item-groups-create-or-replace-item-groups)

Item groups are your category tree. Items join a group by listing its ID in `data.group_ids`, and category pages browse by that ID (`Constructor::browse('group_id', 'pellet-grills')`). Item groups belong to the whole index, so these methods take no `$section`.

```php
// Create or fully replace. id and name are required; parent_ids places the group in the tree.
$catalog->upsertItemGroups([
    ['id' => 'grills', 'name' => 'Grills'],
    ['id' => 'pellet-grills', 'name' => 'Pellet Grills', 'parent_ids' => ['grills'], 'image_url' => '/img/cat/pellet.jpg'],
]);

// Rename or move an existing group. Only id is required.
$catalog->patchItemGroups([['id' => 'pellet-grills', 'name' => 'Pellet Grills & Smokers']]);

$catalog->deleteItemGroups(['clearance-2024']);

$group = $catalog->getItemGroup('pellet-grills');   // ['id', 'name', 'parent_ids', 'data'] or null
$page = $catalog->listItemGroups(['per_page' => 1000]);
// ['item_groups' => [...], 'total_count' => 84]
```

- Fields other than `id`, `name` and `parent_ids` are moved into `data`, which Constructor limits to 5 kB per group.
- `patchItemGroups()` can't create groups: Constructor fails the whole task if one doesn't exist. Use `upsertItemGroups()` for new groups.
- `listItemGroups()` takes `ids`, `page` and `per_page` (Constructor's default is 20, maximum 1,000). It has no cursor.

To read the tree as shoppers see it (with item counts), use `Constructor::getBrowseGroups()` instead; these methods return the catalog records.

### High-frequency updates: the Catalog Batching Service

[Catalog Batching Service reference](https://docs.constructor.com/reference/v2-batching-items-update-items)

When prices or stock levels change many times a minute (from an ERP, an inventory feed or a queue listener), send them to Constructor's Catalog Batching Service. It accepts small requests without starting a task for each, so there is nothing to poll.

```php
$batching = Constructor::catalogBatching();

// Update items, creating any that don't exist. id and name are required on every item.
$result = $batching->patchItems([
    ['id' => 'TRG-PRO780', 'name' => 'Traeger Pro 780 Pellet Grill', 'price' => 899.99, 'in_stock' => true],
]);
$result['accepted'];   // how many records Constructor accepted

// Update variations, creating any that don't exist. id and item_id are required.
$batching->patchVariations([['id' => 'TRG-PRO780-NG', 'item_id' => 'TRG-PRO780', 'in_stock' => false]]);

$batching->deleteItems(['DISCONTINUED-1']);
$batching->deleteVariations(['TRG-PRO780-OLD']);
```

| | `Constructor::catalog()` | `Constructor::catalogBatching()` |
|---|---|---|
| Host | `ac.cnstrc.com` | `batching.catalog.cnstrc.com` (`CONSTRUCTOR_CATALOG_BATCHING_BASE_URL`) |
| Records per request | up to 10,000 | up to 100 (longer lists are split for you) |
| Result | a task per batch, to poll | `accepted` count; no task |
| Missing records on update | `on_missing`: `FAIL`, `IGNORE` or `CREATE` | always created |
| Item updates need | `id` | `id` and `name` |
| Item groups | yes | no |

- Both use `CONSTRUCTOR_API_TOKEN` and take an optional `$section` (default `CONSTRUCTOR_SECTION`).
- Requests are never retried automatically. A `RateLimitException` (429) means you are sending too fast: back off and send the same records again, for example by releasing a queued job with a delay.
- If one request of a long list fails, the exception is thrown and the rest aren't sent. Earlier requests were already accepted, so resending the whole list is safe.

### From the command line

Three Artisan commands cover the common catalog chores:

```bash
# Is everything configured, and are the credentials accepted?
php artisan constructor:verify

# Replace the catalog with these files and wait for Constructor to process them.
php artisan constructor:upload storage/app/catalog/items.jsonl \
    --variations=storage/app/catalog/variations.jsonl \
    --item-groups=storage/app/catalog/item_groups.jsonl \
    --wait

# Update only the fields in a file (e.g. prices), creating unknown items.
php artisan constructor:upload storage/app/catalog/prices.csv --operation=patch --patch-delta --on-missing=CREATE

# Check on a task later.
php artisan constructor:task 12345 --wait --timeout=900
```

`constructor:upload` options:

| Option | Meaning |
|--------|---------|
| `items` (argument), `--variations`, `--item-groups` | The files. Pass at least one; the format (CSV or JSONL) comes from the extension. |
| `--operation` | `create_or_replace` (default) replaces the whole section; `patch` replaces only the records in the files. Constructor has no delete upload: use `deleteItems()`. |
| `--patch-delta` | With `patch`: change only the fields present in the files. |
| `--on-missing` | With `--patch-delta`: `FAIL`, `IGNORE` or `CREATE` records that don't exist. |
| `--mode` | With `create_or_replace`: `full`, or `ids` when the files only list the IDs to keep. |
| `--section` | Index section (default `CONSTRUCTOR_SECTION`). |
| `--email` | Who Constructor emails if the upload fails. Repeat it for several addresses. |
| `--allow-large-removal` | Constructor's `force` flag: apply the upload even if it removes more than half of the catalog. |
| `--wait`, `--timeout` | Wait for the task (default timeout `CONSTRUCTOR_TASK_TIMEOUT`). |
| `--force` | Skip the confirmation a full replace asks for in production. |

The commands print the task's status, what changed per section, ingestion warnings and any error. They exit with 1 when the task fails or `--wait` times out, and with 2 for invalid arguments (nothing is sent). Error messages never include tokens or Constructor's response body; add `-v` to see the body, which usually explains a rejected upload.

### Merchandising configuration

Everything you configure in the dashboard's merchandising screens can also be managed from code with `Constructor::configuration()`. This is useful for keeping redirects and synonyms in version control, syncing facet display names from a PIM, or setting up a staging index the same way as production. It uses `CONSTRUCTOR_API_TOKEN`, and every call throws a `ConstructorException` on failure.

| Accessor | What it manages | Section? |
|----------|-----------------|----------|
| `redirectRules()` | Searches that go straight to a page ("gift card" → `/gift-cards`) | No, index-wide |
| `synonymGroups()` | Two-way synonyms ("bbq" = "barbecue" = "grill"), 1-20 phrases per group | Yes |
| `oneWaySynonyms()` | One-way synonyms ("smoker" also finds "pellet grill"), 1-50 child phrases | No, index-wide |
| `sortOptions()` | Sort options on results pages, identified by `sort_by` + `sort_order` | Yes |
| `facets()` | Facet (filter) configurations: display name, type, range buckets, hidden/protected | Yes |
| `facetOptions()` | How individual facet values appear: display names, positions, swatches, aliases | Yes |
| `refinedQueries()` | Searchandising rules for one search query: slot, boost, bury, hide, banner content | Yes |

Where a resource takes a section, pass it as the last argument (or `section` in the options); it defaults to `CONSTRUCTOR_SECTION`.

```php
$config = Constructor::configuration();

// Redirect rules
$rule = $config->redirectRules()->create([
    'url' => '/gift-cards',
    'matches' => [['pattern' => 'gift card', 'match_type' => 'PHRASE']],   // EXACT, PHRASE or UNORDERED
    'end_time' => '2026-12-31T23:59:59',
]);
$config->redirectRules()->update($rule['id'], ['url' => '/holiday-gift-cards']);
$config->redirectRules()->list(['status' => 'current', 'per_page' => 100]);

// Synonyms
$groupId = $config->synonymGroups()->create(['bbq', 'barbecue', 'grill']);
$config->synonymGroups()->replace($groupId, ['bbq', 'barbecue']);
$config->oneWaySynonyms()->create('smoker', ['pellet grill', 'offset smoker']);

// Sort options
$config->sortOptions()->create([
    'sort_by' => 'price',
    'sort_order' => 'ascending',
    'path_in_metadata' => 'data.price',
    'display_name' => 'Price: Low to High',
]);
$config->sortOptions()->update('price', 'ascending', ['position' => 1]);
$config->sortOptions()->delete([['sort_by' => 'rating', 'sort_order' => 'ascending']]);

// Facets and their options
$config->facets()->create(['name' => 'fuel_type', 'path_in_metadata' => 'facets.fuel_type', 'type' => 'multiple', 'display_name' => 'Fuel type']);
$config->facets()->create(['name' => 'price', 'path_in_metadata' => 'facets.price', 'type' => 'range', 'range_format' => 'boundaries']);   // a slider
$config->facets()->updateMany([['name' => 'brand', 'sort_order' => 'value']]);
$config->facetOptions()->updateMany('brand', [
    ['value' => 'weber', 'display_name' => 'Weber'],
    ['value' => 'traeger', 'display_name' => 'Traeger', 'position' => 1],
]);

// Searchandising for a search query
$config->refinedQueries()->create([
    'query' => 'smoker',
    'slot_rules' => [['rule' => ['item_id' => 'TRG-780', 'position' => 1]]],
    'boost_rules' => [['rule' => ['filters' => ['brand' => ['Traeger']], 'boost' => 5]]],          // -10 to 10
    'blacklist_rules' => [['rule' => ['item_ids' => ['DISCONTINUED-1']]]],
]);
```

Things to know:

- **Method names are the same everywhere:** `list()`, `get()` (returns `null` when not found), `create()`, `replace()` (overwrites: fields you leave out are reset), `update()` (changes only what you pass) and `delete()`. Bulk methods are `replaceMany()` / `updateMany()`, and `deleteAll()` on the synonym services.
- **Limits are handled for you:** `facets()->replaceMany()` sends 25 facets per request, `facets()->updateMany()` and `facetOptions()->updateMany()` 1,000, and `sortOptions()->delete()` 200. `sortOptions()->replaceMany()` takes at most 50 and throws `InvalidArgumentException` beyond that. `per_page` is capped at each endpoint's maximum.
- **Bad arguments throw before anything is sent:** an empty name, a non-numeric rule ID, a sort order other than `ascending`/`descending`, or a synonym group with no phrases.
- **Retries:** list and get calls are retried after connection errors, 429 and 5xx. Writes are never retried, so check the result and resend if you need to.
- `refinedQueries()` takes `response_section`: `results` (the default) for product rules, or `facets` for rules that slot or hide facets for the query.
- `synonymGroups()->deleteAll()` and `oneWaySynonyms()->deleteAll()` delete **every** synonym of that kind. There is no undo.

See the API reference for every field: [Configuration](https://docs.constructor.com/reference/configuration-facets) and [Searchandising](https://docs.constructor.com/reference/v1-searchandising-create-refined-query).

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

They behave like `search()` and `browse()` with `'section' => 'Recipes'`. Pass a different `section` in the options if yours is named differently. `getRecipe($recipeId, $options)` fetches the recipe by ID; pass `['section' => 'Your Section']` if it isn't `Recipes`.

`supportsRecipes()` (and the other `supports*()` methods) exist for code written against several search providers. Constructor supports all of them.

---

## 14. Quizzes

[Quizzes API reference](https://docs.constructor.com/reference/v1-quizzes-get-next-question) · [Question types](https://docs.constructor.com/reference/configuration-quizzes)

A quiz is a guided product finder ("Which grill is right for you?") built in the Constructor dashboard. Your page asks one question at a time, then shows the products that match the answers. Quiz requests go to `CONSTRUCTOR_QUIZZES_BASE_URL` (default `https://quizzes.cnstrc.com`) and carry the shopper's identity like other storefront calls.

```php
$quizzes = Constructor::quizzes();
```

### Asking the questions

```php
$step = $quizzes->getNextQuestion('grill-finder');            // no answers: the first question

$step->question['title'];      // "How do you like to cook?"
$step->type();                 // single | multiple | open | cover | single_filter_value | multiple_filter_values
$step->options();              // [['id' => 1, 'value' => 'Charcoal', 'images' => [...]], ...]
$step->isSkippable();
$step->quizSessionId;          // keep these two for the rest of the quiz
$step->quizVersionId;
```

On each later call, pass **every answer so far**, in order, plus the session and version from the first response:

```php
$step = $quizzes->getNextQuestion('grill-finder', $answers, [
    'quiz_session_id' => $sessionId,
    'quiz_version_id' => $versionId,
]);

if ($step->isComplete()) {
    // No more questions: show the results.
}
```

Each answer is one of:

| Question type | Answer |
|---------------|--------|
| `single`, `single_filter_value` | `[optionId]` |
| `multiple`, `multiple_filter_values` | `[optionId, optionId, ...]` |
| A skipped select question | `[]` or `null` |
| `open` | `true` if the shopper typed something, `false` if they skipped it. The text itself is never sent; Constructor doesn't store it. |
| `cover` | `QuizService::COVER_PAGE_SEEN` (`'seen'`) |

So `[[1], [3, 4], null, true, 'seen']` is sent as `a=1&a=3,4&a=&a=true&a=seen`.

### Results

```php
$results = $quizzes->getResults('grill-finder', $answers, ['brand' => 'Traeger'], [
    'quiz_session_id' => $sessionId,
    'quiz_version_id' => $versionId,
    'page' => 1,
    'per_page' => 12,
]);

$results->products;            // the same product shape as search results
$results->total;
$results->facets;
$results->filterExpression;    // the filter Constructor built from the answers
```

`getResults()` needs at least one answer and throws `InvalidArgumentException` otherwise. `getResultsConfig($quizId, ['quiz_version_id' => ...])` returns the results page title, description and answer summary configured in the dashboard.

### Tracking quiz results

Put the quiz IDs and `resultId` on the results container so the beacon can attribute clicks and add-to-carts ([Data-driven event tracking](https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking)):

```blade
<x-constructor::quiz-results :results="$results">
    @foreach ($results->products as $product)
        <x-constructor::item :product="$product">
            ...
            <x-constructor::button action="add_to_cart">Add to cart</x-constructor::button>
        </x-constructor::item>
    @endforeach
</x-constructor::quiz-results>
```

The container gets `data-cnstrc-quizzes` with `data-cnstrc-quiz-id`, `data-cnstrc-quiz-version-id`, `data-cnstrc-quiz-session-id`, `data-cnstrc-result-id` and `data-cnstrc-num-results`, from the `QuizResults` you pass.

### A quiz controller

```php
use ConstructorIO\Laravel\Facades\Constructor;
use Illuminate\Http\Request;

class GrillFinderController
{
    private const QUIZ_ID = 'grill-finder';

    public function show(Request $request)
    {
        // Answers travel in the form as JSON, so the quiz needs no server-side state.
        $answers = json_decode((string) $request->input('answers', '[]'), true) ?: [];
        $options = $request->only(['quiz_session_id', 'quiz_version_id']);

        $step = Constructor::quizzes()->getNextQuestion(self::QUIZ_ID, $answers, $options);

        if (! $step->isAvailable()) {
            return view('quiz.unavailable');
        }

        if ($step->isComplete()) {
            return view('quiz.results', [
                'results' => Constructor::quizzes()->getResults(self::QUIZ_ID, $answers, [], $options + ['per_page' => 12]),
            ]);
        }

        return view('quiz.question', ['step' => $step, 'answers' => $answers]);
    }
}
```

When a request fails (and `CONSTRUCTOR_THROW_EXCEPTIONS` is off), `getNextQuestion()` returns a step where both `isAvailable()` and `isComplete()` are false, so an outage never sends shoppers to an empty results page. `getResults()` returns empty results.

---

## 15. Laravel Scout

Install Scout 11 or later and set the driver:

```bash
composer require laravel/scout:^11.0
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

`getConstructorSection()` must not depend on attributes other than the model's key or class. Queued deletes restore models with only their keys loaded, so a section worked out from another attribute could point the delete at the wrong section.

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
- `whereIn('field', [])` matches nothing, so it returns no results without calling Constructor.
- `take()`, and Scout's total count when you use a `query()` callback, can ask for more than Constructor's 200 per request. The engine fetches the extra results in batches of 200, up to Constructor's 10,000-result limit.

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

`CONSTRUCTOR_SCOUT_SYNC_MODE` (`scout.sync_mode`) controls what a model save does:

| Mode | Request | Effect |
|------|---------|--------|
| `replace` (default) | `PUT /v2/items` | The item is replaced with exactly what the model sends |
| `patch` | `PATCH /v2/items` with `on_missing=CREATE` | Only the fields the model sends change. Fields set by other feeds (inventory, merchandising data) are kept, and missing items are created |

Use `patch` when other systems also write to the same items.

**Soft deletes.** With `scout.soft_delete` enabled and a model using `SoftDeletes`, each item gets `data.__soft_deleted` (`"0"` or `"1"`), and searches filter on it the way Scout expects, including `withTrashed()` and `onlyTrashed()`. Configure `__soft_deleted` as a **hidden facet** in the Constructor dashboard so the filter works and shoppers never see it.

`scout:index` and `scout:delete-index` are not supported: Constructor indexes are created in the dashboard. They throw a `ConstructorException` explaining this.

For a full initial load of a large catalog, a catalog file upload ([section 12](#12-catalog-management)) is faster than `scout:import`.

---

## 16. DTO reference

### SearchResults

Returned by `search()`, `browse()`, `browseItems()`, `browseCollection()`, `searchRecipes()` and `browseRecipes()`.

| Property | Type | Meaning |
|----------|------|---------|
| `products` | list | Products (see [What a product looks like](#what-a-product-looks-like)) |
| `total` | int | Matches across all pages (can exceed the 10,000 results that can be fetched) |
| `page` | int | Current page |
| `perPage` | int | Results per page |
| `facets` | array | Facets keyed by name |
| `groups` | list | Category tree for the results |
| `resultId` | ?string | Pass to the page for tracking |
| `redirect` | ?array | Set when a redirect rule matched: `url`, `rule_id`, `matched_terms`, `matched_user_segments` |
| `sortOptions` | list | Sort options configured in the dashboard |
| `refinedContent` | list | Content rules that matched |
| `relatedSearches` | list | Related search terms |
| `relatedBrowsePages` | list | Related category/browse pages, when enabled |
| `metadata` | array | `request` (Constructor's interpretation of the request), `request_id`, `result_id`, `experiments`, and when present `collection`, `features`, `result_sources` |

| Method | Returns |
|--------|---------|
| `isRedirect()` / `redirectUrl()` | Whether to redirect, and where |
| `trackingData()` | `request` and `result_id` for the page's `cnstrc-data` script tag |
| `hasMore()` | Whether there is another page that can be fetched |
| `totalPages()` | Number of pages that can be fetched (stops at `SearchResults::MAX_RETRIEVABLE_RESULTS`, 10,000 results) |
| `nextPageNumber()` | Next page, or 0 |
| `getOffset()` | Results before this page |
| `isEmpty()` / `count()` | Products on this page |
| `exceedsRetrievableLimit()` | Whether more results match than can be paged through (more than 10,000) |
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
| `trending` | Kept for 1.x compatibility; Constructor has no trending-searches endpoint, so it is always empty |
| `sections` | Every section exactly as Constructor returned it |
| `resultId` | Pass to the page for tracking |
| `metadata` | `request`, `request_id`, `result_id`, `experiments`, `total_num_results_per_section` |

Methods: `hasSuggestions()`, `hasProducts()`, `hasCategories()`, `hasTrending()`, `hasPopularProducts()`, `hasTopCategories()`, `hasZeroStateData()`, `isEmpty()`, `isAutocompleteEmpty()` (ignores zero-state content), `toArray()` (camelCase keys, for compatibility with 1.x front ends), and the factories `AutocompleteResults::empty()` and `AutocompleteResults::zeroState(...)`.

### RecommendationResults

Returned by `getRecommendations()` and `getItemRecommendations()`.

| Property | Meaning |
|----------|---------|
| `podId` | The pod |
| `title` | The pod's display name |
| `products` | Recommended products, each with `strategy` |
| `total` | Total available |
| `resultId` | Pass to the page for tracking |
| `metadata` | `request`, `request_id`, `result_id`, `pod`, `experiments` |

Methods: `isEmpty()`, `hasRecommendations()`, `count()`, `toArray()`, `RecommendationResults::empty($podId)`.

### RecommendationPageResults

Returned by `getRecommendationPage()`.

| Property | Meaning |
|----------|---------|
| `pageId` | The page |
| `title` | The page's display name |
| `pageType` | The page's configured type, e.g. `pdp` or `home` |
| `pods` | One `RecommendationResults` per pod, including empty ones, in page order. Each has its own `resultId` for tracking. |
| `resultId` | Identifies the page request. Not a beacon ID: track each pod with its own `resultId`. |
| `metadata` | `request`, `request_id`, `result_id`, `experiments` (also copied to each pod's `metadata`) |

Methods: `pod($podId)`, `podsWithResults()`, `isEmpty()`, `hasRecommendations()`, `toArray()`, `RecommendationPageResults::empty($pageId)`.

### QuizQuestion

Returned by `Constructor::quizzes()->getNextQuestion()`.

| Property | Meaning |
|----------|---------|
| `quizId` | The quiz |
| `question` | Constructor's `next_question` (`id`, `title`, `description`, `type`, `images`, `cta_text`, `is_skippable`, `options`, ...), or `null` when every question is answered |
| `quizVersionId`, `quizSessionId` | Pass back on every later call for this shopper |
| `totalQuestions` | Questions in the quiz, when reported |
| `raw` | The full response |

Methods: `isComplete()`, `isAvailable()`, `type()`, `options()`, `isSkippable()`, `toArray()`, `QuizQuestion::unavailable($quizId)`.

### QuizResults

Returned by `Constructor::quizzes()->getResults()`.

| Property | Meaning |
|----------|---------|
| `quizId`, `quizVersionId`, `quizSessionId` | For the tracking attributes |
| `products` | Matching products |
| `total`, `page`, `perPage` | Paging |
| `facets` | Facets keyed by name, when returned |
| `filterExpression` | The filter Constructor built from the answers |
| `resultId` | Pass to the page for tracking |
| `metadata` | `request`, `request_id`, `result_id`, `experiments` |

Methods: `isEmpty()`, `hasResults()`, `totalPages()`, `hasMore()`, `toArray()`, `QuizResults::empty($quizId)`.

### AgentInsight

Returned by every `Constructor::insights()` method.

| Property | Meaning |
|----------|---------|
| `mode` | `search`, `chat`, `qna`, `recommendations` or `query_refinement` |
| `status` | `complete`, `partial` (cut short), `declined` (HTTP 422: use traditional search) or `unavailable` (failed and logged) |
| `resultId` | The agent insight result ID, for tracking |
| `threadId` | Pass back as `thread_id` to continue the conversation |
| `message`, `route` | The agent's text, and where to send the shopper (`customer_support`) |
| `itemResults` | Sets of products: `result_id`, `title`, `text`, `request`, `products`, `listing` (refinement only) |
| `articles`, `images`, `recipes` | Cited articles (`url`, `title`, `image_url`, `abstract`), referenced images, recipes |
| `followUp` | `question`, `options` (text) and `choices` (one filter each), or `null` |
| `raw` | The full response |

Methods: `isComplete()`, `isPartial()`, `isDeclined()`, `hasContent()`, `products()`, `hasProducts()`, `followUpOptions()`, `followUpChoices()`, `refinedListing()`, `toArray()`, `AgentInsight::declined($mode)`, `AgentInsight::unavailable($mode)`.

---

## 17. Error handling

### The policy

| Call | On failure |
|------|------------|
| Search, browse, autocomplete, facets, groups, collections, recommendations, quiz results | Logs a warning and returns an empty result |
| `getNextQuestion()` (quizzes) | Logs and returns a step where `isAvailable()` is false |
| `getProductQuestions()` | Logs and returns `['questions' => []]` |
| Shopping Agent, `askProductQuestion()` | Throws |
| Catalog management, offline behavioral actions, Scout indexing | Throws |
| Missing configuration (API key, API token, agent domain) | Always throws `ConfigurationException` |

Storefront reads fail gracefully so a brief Constructor outage degrades a page instead of breaking it. To throw instead, for example in tests or to render your own fallback:

```env
CONSTRUCTOR_THROW_EXCEPTIONS=true
```

Logs go to `CONSTRUCTOR_LOG_CHANNEL` (or your default channel). They include the operation, exception class and HTTP status, never credentials, shopper identifiers or Constructor's response body. Exception messages follow the same rule: connection errors drop the request URL, and `ApiException` messages never include the response body (it can echo search terms or filter values). Use `$e->getResponseBody()` when you need it for debugging.

### Exceptions

All exceptions extend `ConstructorIO\Laravel\Exceptions\ConstructorException`:

| Exception | When |
|-----------|------|
| `ConfigurationException` | A required setting is missing |
| `ConnectionException` | Constructor couldn't be reached, or a stream was interrupted |
| `ApiException` | Constructor returned an error status. `getStatusCode()`, `getResponseBody()` (the body is never in the message) |
| `AuthenticationException` (extends `ApiException`) | 401: wrong key or token. 403: the token lacks permission |
| `RateLimitException` (extends `ApiException`) | 429: throttled. On storefront traffic, usually a missing `CONSTRUCTOR_BACKEND_TOKEN` |
| `InvalidArgumentException` | An argument Constructor would reject, caught before sending (e.g. more than 100 IDs for `browseItems()`, an offline purchase without a time, quiz results without answers) |
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

Only failures that might succeed on a second try are retried: connection errors, 429 and 5xx responses, and only for reads. Other 4xx errors (bad parameters, unknown pod) are never retried, and neither are catalog writes or uploads, because a repeated write could start a second ingestion task.

| Setting | Default | Meaning |
|---------|---------|---------|
| `CONSTRUCTOR_RETRY_ATTEMPTS` | 2 | Total attempts per request, at most 5 |
| `CONSTRUCTOR_RETRY_DELAY` | 100 | Milliseconds before the first retry; doubles each time, or follows the `Retry-After` header on a 429. Never more than 5 seconds |
| `CONSTRUCTOR_TIMEOUT` | 10 | Seconds for a normal request |
| `CONSTRUCTOR_STREAM_TIMEOUT` | 120 | Total seconds for a streaming agent response, including keep-alive pings |

`CONSTRUCTOR_SEARCH_BASE_URL`, `CONSTRUCTOR_AGENT_BASE_URL`, `CONSTRUCTOR_QUIZZES_BASE_URL`, `CONSTRUCTOR_IMAGE_SEARCH_BASE_URL`, `CONSTRUCTOR_CATALOG_BATCHING_BASE_URL`, `CONSTRUCTOR_PRODUCT_DETAILS_BASE_URL` and `CONSTRUCTOR_USER_PROFILE_BASE_URL` must use `https://`, because requests carry secret tokens. Plain `http://` is accepted only for `localhost` (for a local mock server); anything else throws `ConfigurationException`.

---

## 18. Testing your integration

### Fake Constructor

`Constructor::fake()` swaps the client for a recording fake. Nothing is sent to Constructor and no credentials are needed, so it works in CI as is:

```php
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Facades\Constructor;

public function test_search_page_lists_products(): void
{
    Constructor::fake([
        'search' => new SearchResults(
            products: [['id' => 'SMK-1', 'name' => 'Offset Smoker', 'url' => '/p/smk-1', 'image_url' => null, 'price' => 499]],
            total: 1,
            page: 1,
            perPage: 24,
            resultId: 'test-result',
        ),
    ]);

    $this->get('/search?q=smoker')->assertOk()->assertSee('Offset Smoker');

    Constructor::assertSearched('smoker');
}
```

**Stubs** are keyed by method name. A stub is either the value to return or a Closure that receives the call's arguments, with defaults filled in:

```php
Constructor::fake([
    'getRecommendations' => fn (string $podId, array $options) => $podId === 'pdp-similar' ? $similar : RecommendationResults::empty($podId),
]);

// Or later in the test:
Constructor::stub('getCollection', ['id' => 'summer-cookout', 'name' => 'Summer Cookout']);
```

A method you don't stub runs the real code against an offline client, so it returns its normal empty result (an empty `SearchResults`, `[]` or `null`) and still rejects bad arguments (for example more than 100 IDs for `browseItems()`).

**Assertions:**

| Assertion | Passes when |
|-----------|-------------|
| `assertSearched($query = null)` | `search()` ran, for any query, this query, or a call your Closure accepts (`$query, $filters, $options`) |
| `assertNotSearched($query = null)` | The opposite |
| `assertBrowsed($filterName = null, $filterValue = null)` | `browse()` ran, e.g. `assertBrowsed('group_id', 'gas-grills')`, or a Closure (`$filterName, $filterValue, $filters, $options`) |
| `assertAutocompleted($query = null)` | `autocomplete()` ran |
| `assertRecommended($podId = null)` | `getRecommendations()` or `getItemRecommendations()` asked for the pod. A Closure receives `$podId, $options, $itemId` |
| `assertCalled($method, $callback = null)` / `assertNotCalled(...)` | Any method, with an optional Closure over its arguments |
| `assertCalledTimes($method, $times)` | Exactly that many calls |
| `assertNothingCalled()` | No Constructor calls at all |

`Constructor::calls()` (or `calls('search')`) returns the recorded calls for your own checks.

**Services.** `Constructor::agent()`, `catalog()`, `catalogBatching()`, `quizzes()`, `behavior()`, `recommendations()` and `searchService()` return the real services wired to the offline client: they never reach the network, catalog writes are accepted, and tasks report DONE at once. To return specific data, mock the service; the accessor returns your mock:

```php
$this->mock(AgentService::class, fn ($mock) => $mock
    ->shouldReceive('getProductQuestions')
    ->andReturn(['questions' => ['Can it cold smoke?'], '_raw' => []]));
```

Classes that type-hint `ConstructorIO\Laravel\Constructor` or `SearchProvider` receive the fake too.

### Mock the facade

You can also mock the facade with Mockery and return DTOs:

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

## 19. Troubleshooting

### "CONSTRUCTOR_API_KEY is not set" (or "must be an https URL")

A `ConfigurationException` names the missing or invalid setting. Check `.env`, then clear cached config:

```bash
php artisan config:clear
php artisan constructor:verify
```

### Search returns no results

1. Set `CONSTRUCTOR_THROW_EXCEPTIONS=true` temporarily and inspect `$e->getResponseBody()` to see Constructor's error, or check the log for "Constructor search failed".
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
- `ConnectionException` mid-answer: the answer took longer than `CONSTRUCTOR_STREAM_TIMEOUT` in total, or the stream ended before Constructor's `end` event. Raise `CONSTRUCTOR_STREAM_TIMEOUT`, PHP's `max_execution_time` and your web server's proxy timeouts, and disable response buffering (`X-Accel-Buffering: no` for nginx).

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
- Every search returns nothing with soft deletes enabled: configure `__soft_deleted` as a hidden facet in Constructor, then re-import so every item has it.
- Fields set by another feed disappear after a model save: switch to `CONSTRUCTOR_SCOUT_SYNC_MODE=patch`.

### Performance

- Category images (`with_images`), `getFacetValuesWithImages()` and `getFirstProductImageFromCollection()` make extra requests. Turn on the [response cache](#the-built-in-response-cache) (`CONSTRUCTOR_CACHE_ENABLED=true`) for the first two; cache the third yourself.
- The response cache covers the category tree, facets and collections. Cache personalized data yourself, per client ID.
- Keep `CONSTRUCTOR_TIMEOUT` short (the default is 10 seconds) so a slow response doesn't hold up page rendering.

### Need more help?

- [Constructor documentation](https://docs.constructor.com/)
- [Constructor API reference](https://docs.constructor.com/reference)
- [Package issues](https://github.com/Debello-io/constructor-laravel/issues)
