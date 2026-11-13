# Constructor for Laravel

[![CI](https://github.com/Debello-io/constructor-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/Debello-io/constructor-laravel/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A Laravel package for [Constructor](https://constructor.com) product discovery: search, browse, autocomplete, recommendations, the AI Shopping Agent and Product Insights Agent, catalog management, and a Laravel Scout engine.

Constructor has official client libraries for JavaScript, Node, Java, Python and .NET, but not PHP. This package calls the REST API directly and handles the **backend-integration requirements** for you: shopper identity, forwarded headers, the security token and the client identifier. Every endpoint and parameter has been checked against the [Constructor API reference](https://docs.constructor.com/reference).

- [Requirements](#requirements)
- [Installation](#installation)
- [Credentials](#credentials)
- [Backend integration checklist](#backend-integration-checklist)
- [Usage](#usage)
- [Error handling](#error-handling)
- [Testing your app](#testing-your-app)
- [Configuration reference](#configuration-reference)
- [Upgrading from 1.x](#upgrading-from-1x)
- [Contributing](#contributing)

For a full walkthrough with complete controller and view examples, see the **[User Guide](docs/USER_GUIDE.md)**.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13 (Laravel 13 needs PHP 8.3+)
- Optional: Laravel Scout 11 for the Scout engine

## Installation

```bash
composer require debello-io/constructor-laravel
php artisan vendor:publish --tag=constructor-config
```

## Credentials

Constructor uses three different credentials. **They are not interchangeable.**

```env
# Public. Identifies your index. Dashboard: Integration > API Integration.
CONSTRUCTOR_API_KEY=key_xxxxxxxx

# Secret. Only for catalog management (uploads, items, tasks).
CONSTRUCTOR_API_TOKEN=tok_xxxxxxxx

# Secret. The backend "security token" Constructor gives you. It is sent as the
# x-cnstrc-token header on every search/browse/autocomplete/recommendation/agent
# request. Without it, requests from your servers are likely to be throttled.
CONSTRUCTOR_BACKEND_TOKEN=xxxxxxxx

# Required for the AI Shopping Agent.
CONSTRUCTOR_AGENT_DOMAIN=your-agent-domain
```

| Credential | Used for | Sent as |
|---|---|---|
| API key | Every request | `key` query parameter |
| API token | Catalog management only | HTTP Basic username |
| Backend token | Storefront and agent requests | `x-cnstrc-token` header |

See [Authentication](https://docs.constructor.com/reference/main-authentication) and [Required parameters](https://docs.constructor.com/docs/integrating-with-constructor-direct-api-integration-required-parameters).

## Backend integration checklist

When your server calls Constructor on behalf of a shopper, Constructor still has to know who that shopper is. The package sends all of this automatically from the current request:

| Value | Source |
|---|---|
| `i` (client ID) | The beacon's `ConstructorioID_client_id` cookie. On a first visit, a UUID is generated and set as that cookie. |
| `s` (session number) | The beacon's `ConstructorioID_session_id` cookie, or `1` until it exists. |
| `ui` (user ID) | `auth()->id()`, **only** for signed-in shoppers. |
| `c` (client identifier) | `cio-be-web-{your APP_NAME}`, or `CONSTRUCTOR_CLIENT_IDENTIFIER`. |
| `User-Agent`, `X-Forwarded-For` | The shopper's browser and IP address. |
| `x-cnstrc-token` | `CONSTRUCTOR_BACKEND_TOKEN`. |

Your part:

1. **Load the Constructor beacon on every page.** It sets the cookies above and sends the behavioral events (clicks, add to cart, purchases) that Constructor learns from. This package does not send tracking events.
2. **Expose the tracking data on the page.** The beacon reads it from data attributes. On search and browse pages, also add the `cnstrc-data` script tag:
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
                @if ($product['price']) data-cnstrc-item-price="{{ $product['price'] }}" @endif
                @if ($product['is_sponsored'])
                    data-cnstrc-sl-campaign-id="{{ $product['sponsored_campaign_id'] }}"
                    data-cnstrc-sl-campaign-owner="{{ $product['sponsored_campaign_owner'] }}"
                @endif>
               ...
               <button data-cnstrc-btn="add_to_cart">Add to cart</button>
           </div>
       @endforeach
   </div>

   <script id="cnstrc-data" type="application/json">@json($results->trackingData())</script>
   ```
   Browse pages use `data-cnstrc-browse` with `data-cnstrc-filter-name` / `data-cnstrc-filter-value` instead of the search term. Recommendation widgets use `data-cnstrc-recommendations`. See [Data-driven event tracking](https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking) and the [User Guide](docs/USER_GUIDE.md#behavioral-tracking).
3. **Configure [trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies)** if you're behind a load balancer or CDN. Otherwise every request appears to come from the proxy's IP.
4. **Respect consent.** If a shopper hasn't consented to personalization, the client ID must be omitted:
   ```php
   // AppServiceProvider::boot()
   Constructor::resolveConsentUsing(fn (?Request $request) => $request?->cookie('consent') === 'all');
   ```
   For opt-in regimes (GDPR), also set `CONSTRUCTOR_REQUIRE_CONSENT=true` so nobody is identified until your resolver says so. Without consent the package omits `i`, as Constructor requires; `ui`, `s`, IP and user agent are still sent.
   If your consent banner sets that cookie from JavaScript, add it to `EncryptCookies::except([...])`. Otherwise Laravel can't decrypt it and `cookie()` returns `null`.
5. **Anonymize `ui`** (recommended by Constructor). Use a keyed hash: a plain hash of a sequential ID is easy to reverse.
   ```php
   Constructor::resolveUserIdUsing(fn (?Request $request) => $request?->user()
       ? hash_hmac('sha256', (string) $request->user()->getAuthIdentifier(), config('app.key'))
       : null);
   ```
   Register both resolvers once, in a service provider's `boot()`. They are process-wide, so registering one per request (for example in middleware) on Octane would leak into other shoppers' requests.
6. **Don't share cached personalized results** between shoppers. If you cache, include the client ID and user ID in the cache key. Full-page caches and CDNs must not cache responses that carry the generated `ConstructorioID_client_id` cookie (`Cache-Control: private`), or every shopper would share one ID.
7. **Use the `web` middleware group** for routes that call Constructor. The first-visit client ID cookie is queued with `Cookie::queue()`, which needs `AddQueuedCookiesToResponse`. On stateless `api` routes, let the beacon set the cookie, or pass `client_id` / `session_id` explicitly.
8. **Hide the token in debugging tools.** If you use Telescope, add `x-cnstrc-token` to `Telescope::hideRequestHeaders()`.

See [Backend integration options](https://docs.constructor.com/docs/integrating-with-constructor-retrieving-results-integration-approaches-backend-integration-options).

## Usage

```php
use ConstructorIO\Laravel\Facades\Constructor;
```

You can also inject `ConstructorIO\Laravel\Contracts\SearchProvider` (or `ConstructorIO\Laravel\Constructor`) instead of using the facade.

### Search

```php
$results = Constructor::search('pellet grill', [
    'brand' => ['Traeger', 'Weber'],     // values for one facet are ORed
    'fuel_type' => 'Pellet',             // different facets are ANDed
], [
    'page' => 1,
    'per_page' => 24,                    // max 200
    'sort_by' => 'price',
    'sort_order' => 'ascending',
    'range_filters' => ['price' => ['min' => 300, 'max' => 1500]],
]);

if ($results->isRedirect()) {            // a redirect rule matched in the dashboard
    return redirect($results->redirectUrl());
}

foreach ($results->products as $product) {
    $product['name'];      $product['price'];   $product['image_url'];
    $product['url'];       $product['_raw'];    // every catalog field
    $product['is_sponsored'];                   // Sponsored Listing: show a "Sponsored" badge
}

$results->total;           // Constructor serves at most 10,000 of them: totalPages() stops there
$results->facets;          // ['brand' => ['name' => 'Brand', 'type' => 'checkbox_list', 'options' => [...], 'values' => [...]]]
$results->sortOptions;     // sort options configured in the dashboard
$results->resultId;        // pass to the page for tracking
```

Voice and conversational queries go to `naturalLanguageSearch()`, which takes the same filters and options and returns the same `SearchResults`. Constructor turns phrases like "under 500 dollars" into filters:

```php
$results = Constructor::naturalLanguageSearch('show me a gas grill for under 500 dollars');
```

Every option (`offset`, `filter_match_types`, `pre_filter_expression`, `hidden_fields`, `fmt_options`, `variations_map`, `user_segments`, `lang`, `parameters`, ...) is documented on [`ParameterBuilder`](src/Support/ParameterBuilder.php).

### Browse (category, brand and collection pages)

```php
$grills = Constructor::browse('group_id', 'gas-grills', ['brand' => 'Weber'], ['per_page' => 24]);
$weber  = Constructor::browse('brand', 'Weber');

$categories = Constructor::getBrowseGroups(['max_items' => 10, 'max_children' => 5]);

$collections = Constructor::getCollections(['max_items' => 10]);
$collection  = Constructor::getCollection('summer-cookout');      // name, data, or null
$products    = Constructor::browseCollection('summer-cookout');

$recentlyViewed = Constructor::browseItems(['TRG-780', 'WEB-SPIRIT-E325']);
```

Facets with their options, for a filter sidebar or "Shop by" menu without running a search (at most 10 facets per request; page through the rest):

```php
$page = Constructor::getFacetsWithOptions(['page' => 1]);
$page['facets']['brand']['options'];   // same shape as $results->facets
$page['has_more'];
```

### Autocomplete

```php
$results = Constructor::autocomplete('web', [
    'sections' => [
        'suggestions' => ['limit' => 5],
        'products' => ['limit' => 6],
    ],
]);

$results->suggestions;   // [['term' => 'weber grill', 'matched_terms' => [...]], ...]
$results->products;
$results->sections;      // every section returned, including custom ones

// Empty, focused search box:
$zeroState = Constructor::getZeroStateData(['recommendation_pod_id' => 'zero-state-bestsellers']);
```

### Recommendations

```php
$bestsellers = Constructor::getRecommendations('home-bestsellers', ['num_results' => 8]);

// Item-based pods (alternatives, complementary items, bundles). Pass one ID or several (a cart).
$accessories = Constructor::getItemRecommendations('pdp-complementary', 'TRG-780', ['num_results' => 4]);

$accessories->title;     // the pod's display name
$accessories->products;
```

Pod-specific parameters are supported: `term` (query recommendations), `filters` (filtered items), `variation_id`. See [Recommendations](https://docs.constructor.com/reference/v1-recommendations-get-pod-results).

### AI Shopping Agent

```php
$agent = Constructor::agent();

$reply = $agent->askShoppingAgent('A smoker for a small patio, under $500');
$reply['message'];               // the agent's text
$reply['products'];              // recommended products
$reply['refinements'];           // follow-up questions with options
$reply['thread_id'];             // pass back to continue the conversation

$followUp = $agent->askShoppingAgent('Something electric instead', $reply['thread_id']);
```

Stream the answer to the browser as it is generated. Forward only the events your UI needs: `complete` carries the raw response, including any hidden fields you requested.

```php
use ConstructorIO\Laravel\Exceptions\ConstructorException;

return response()->stream(function () use ($agent, $query) {
    $send = function (string $event, mixed $data) {
        echo "event: {$event}\ndata: ".json_encode($data)."\n\n";
        ob_flush(); flush();
    };

    try {
        $agent->askShoppingAgentStreaming($query, function (string $event, mixed $data) use ($send) {
            if (in_array($event, ['start', 'message', 'products', 'follow_up', 'end'], true)) {
                $send($event, $data);
            }
        });
    } catch (ConstructorException $e) {
        report($e);
        $send('error', ['message' => 'The assistant is unavailable. Please try again.']);
    }
}, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
```

Agent text can be influenced by what shoppers type, so render it as text (`textContent`, not `innerHTML`) and only link to URLs on your own domain.

### Product Insights Agent

```php
$questions = $agent->getProductQuestions('TRG-780');          // ['questions' => ['Can it cold smoke?', ...]]

$answer = $agent->askProductQuestion('Can it cold smoke cheese?', 'TRG-780');
$answer['answer'];
$answer['follow_up_questions'];
$answer['item_results'];                                       // related products
```

### Catalog management

Requires `CONSTRUCTOR_API_TOKEN`.

```php
$catalog = Constructor::catalog();

// Full replace. Format (csv or jsonl) comes from the file extension.
$upload = $catalog->uploadCatalog(storage_path('catalog/items.jsonl'), 'create_or_replace', [
    'variations_file' => storage_path('catalog/variations.jsonl'),
    'item_groups_file' => storage_path('catalog/item_groups.jsonl'),
    'notification_email' => 'catalog-alerts@example.com',
]);

// Partial update that only changes the fields you send.
$catalog->uploadCatalog(storage_path('catalog/price-updates.jsonl'), 'patch', ['patch_delta' => true]);

$status = $catalog->waitForTaskCompletion($upload['task_id']);   // throws TaskTimeoutException
$status['successful'];   $status['warnings'];
$status['indexed'];      // DONE can come before the change is searchable

// Individual items (batched automatically, max 10,000 per request)
$catalog->upsertItems([['id' => 'TRG-780', 'name' => 'Pro 780', 'data' => ['price' => 999]]]);
$catalog->patchItems([['id' => 'TRG-780', 'data' => ['price' => 899]]]);
$catalog->deleteItems(['OLD-SKU-1']);
$catalog->getItem('TRG-780');
```

Large uploads can take minutes, so run them from a queued job. See [Catalog management](https://docs.constructor.com/reference/v1-catalog-create-or-replace-catalog).

### Laravel Scout

```env
SCOUT_DRIVER=constructor
```

```php
use ConstructorIO\Laravel\Scout\ConstructorSearchable;
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
            'data' => [
                'url' => route('products.show', $this),
                'image_url' => $this->image_url,
                'price' => $this->price,
                'group_ids' => $this->categories->pluck('slug')->all(),
            ],
        ];
    }
}

$products = Product::search('smoker')->where('brand', 'Traeger')->paginate(24);
```

Requires Laravel Scout 11. The item ID is the model's Scout key. Without `ConstructorSearchable`, `toSearchableArray()` is used: `name` stays at the top level and every other field moves into `data`. Scout searches go through the same storefront client, so they carry the shopper's identity too.

- `CONSTRUCTOR_SCOUT_SYNC_MODE=patch` sends model saves as partial updates, keeping fields other feeds maintain (inventory, merchandising data). The default, `replace`, replaces the whole item.
- With `scout.soft_delete`, items carry `data.__soft_deleted` (`"0"`/`"1"`); configure `__soft_deleted` as a hidden facet in Constructor so Scout's filter works.
- Most retailers sync the catalog with file uploads and use Scout only for incremental updates.

## Error handling

| Call | On failure |
|---|---|
| Search, browse, autocomplete, recommendations, facets, groups, collections | Logs a warning and returns an empty result, so a page still renders. Set `CONSTRUCTOR_THROW_EXCEPTIONS=true` to throw instead. |
| AI agents, catalog management, Scout indexing | Throws. |
| Missing API key, token or agent domain | Always throws `ConfigurationException`. |

Every exception extends `ConstructorIO\Laravel\Exceptions\ConstructorException`:

- `AuthenticationException`: 401 or 403
- `RateLimitException`: 429. On storefront calls this usually means a missing `CONSTRUCTOR_BACKEND_TOKEN`.
- `ApiException`: any other error status; see `getStatusCode()` and `getResponseBody()`. Messages never include the response body, because it can echo search terms or filter values.
- `ConnectionException`: Constructor could not be reached
- `TaskTimeoutException`, `InvalidCatalogDataException`: catalog management
- `InvalidArgumentException`: an argument Constructor would reject (e.g. more than 100 IDs for `browseItems()`), thrown before any request

Only reads are retried, and only after connection errors, 429 and 5xx responses, with exponential backoff that honours `Retry-After` (at most 5 seconds). Other 4xx responses, catalog writes and uploads are never retried. Logs and exception messages never include tokens, response bodies or shopper identifiers, and URL-holding parameters are hidden from stack traces.

## Testing your app

Mock the facade:

```php
use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use ConstructorIO\Laravel\Facades\Constructor;

Constructor::shouldReceive('search')->andReturn(new SearchResults(
    products: [['id' => 'TRG-780', 'name' => 'Pro 780']], total: 1, page: 1, perPage: 24,
));
```

Or fake the HTTP layer with `Http::fake(['ac.cnstrc.com/*' => Http::response([...])])`.

## Configuration reference

Every setting is documented in [`config/constructor.php`](config/constructor.php) and [`.env.example`](.env.example). The main ones:

| Env | Default | Purpose |
|---|---|---|
| `CONSTRUCTOR_API_KEY` | | Public index key |
| `CONSTRUCTOR_API_TOKEN` | | Catalog management |
| `CONSTRUCTOR_BACKEND_TOKEN` | | `x-cnstrc-token` header |
| `CONSTRUCTOR_CLIENT_IDENTIFIER` | `cio-be-web-{APP_NAME}` | `c` parameter |
| `CONSTRUCTOR_SECTION` | `Products` | Default index section |
| `CONSTRUCTOR_GENERATE_CLIENT_ID` | `true` | Create the beacon's client ID cookie on a first visit |
| `CONSTRUCTOR_SEND_USER_ID` | `true` | Send `auth()->id()` as `ui` |
| `CONSTRUCTOR_REQUIRE_CONSENT` | `false` | Withhold `i` until `resolveConsentUsing()` returns true |
| `CONSTRUCTOR_AGENT_DOMAIN` | | AI Shopping Agent domain |
| `CONSTRUCTOR_TIMEOUT` / `CONSTRUCTOR_STREAM_TIMEOUT` | `10` / `120` | Seconds |
| `CONSTRUCTOR_RETRY_ATTEMPTS` / `CONSTRUCTOR_RETRY_DELAY` | `2` / `100` | Total attempts (max 5) / first delay in ms |
| `CONSTRUCTOR_THROW_EXCEPTIONS` | `false` | Throw on storefront failures |
| `CONSTRUCTOR_LOG_CHANNEL` | default | Where failures are logged |
| `CONSTRUCTOR_NOTIFICATION_EMAIL` | | Catalog failure notifications |
| `CONSTRUCTOR_CATALOG_BATCH_SIZE` | `1000` | Items per `/v2/items` request (max 10,000) |
| `CONSTRUCTOR_SCOUT_SYNC_MODE` | `replace` | `replace` or `patch` model saves |

## Upgrading from 1.x

2.0 fixes the integration to match Constructor's documentation, which changes some behaviour. The 1.x class names still work as deprecated aliases. See **[UPGRADE.md](UPGRADE.md)** for the full list.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and [AGENTS.md](AGENTS.md). The repository is set up for [Claude Code](https://claude.com/claude-code): the Constructor docs MCP server, skills and a review subagent are configured in `.mcp.json` and `.claude/`. Run `composer check` before opening a pull request.

Security issues: see [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
