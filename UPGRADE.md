# Upgrading

## From 1.x to 2.0

2.0 aligns the package with Constructor's current API reference and backend-integration requirements. Several 1.x behaviours were incorrect and are fixed here. The fixes change what is sent to Constructor, and in a few places what comes back.

Estimated effort: **under an hour** for most apps. The 1.x class names still work.

### 1. Requirements and package name

- PHP **8.2+** and Laravel **11, 12 or 13**. Laravel 10 and PHP 8.1 are end-of-life and no longer supported.
- The package is now `debello-io/constructor-laravel`:
  ```bash
  composer remove timjohnbancroft/constructor-laravel
  composer require debello-io/constructor-laravel:^2.0
  ```
  The PHP namespace (`ConstructorIO\Laravel`) is unchanged.

### 2. Credentials: add the backend token

1.x sent your **API token** as the `x-cnstrc-token` header when `CONSTRUCTOR_BACKEND_TOKEN` was empty. Constructor documents these as two different credentials, so 2.0 never does this.

- **Action:** ask your Constructor contact for the backend security token and set `CONSTRUCTOR_BACKEND_TOKEN`. Without it, server traffic is likely to be throttled (HTTP 429).
- `CONSTRUCTOR_API_TOKEN` is now only used for catalog management, sent as HTTP Basic auth. 1.x used Bearer auth for uploads, which classic API tokens don't support.

### 3. Configuration

Re-publish the config: `php artisan vendor:publish --tag=constructor-config --force`. Then compare it with your old file.

| 1.x | 2.0 |
|---|---|
| `config/constructor-catalog.php` | Removed. Its export, sample, cleanup and Slack settings were never used. Upload and task settings moved to `constructor.catalog.*`. |
| `config/scout.php` `constructor.*` keys | Removed. The Scout engine reads `config/constructor.php`. |
| `agent_domain`, `agent_guard`, `agent_num_result_events`, `agent_num_results_per_event` | `agent.domain`, `agent.guard`, `agent.num_result_events`, `agent.num_results_per_event` (env names unchanged) |
| `retry_times` / `CONSTRUCTOR_RETRY_TIMES` | `retry_attempts` / `CONSTRUCTOR_RETRY_ATTEMPTS` (total attempts) |
| `retry_sleep` / `CONSTRUCTOR_RETRY_SLEEP` | `retry_delay` / `CONSTRUCTOR_RETRY_DELAY` (first delay, doubles per retry) |
| `timeout` default 30 | default 10; new `stream_timeout` (120) for agent streams |
| `client_identifier` default `cio-be-laravel-{app}` | default `cio-be-web-{app}`, the documented format |
| | New: `default_section`, `identity.*`, `throw_exceptions`, `log_channel`, `catalog.*`, `scout.batch_size` |

### 4. Renamed classes (deprecated aliases kept)

| 1.x | 2.0 |
|---|---|
| `Services\Search\ConstructorSandboxSearch` | `ConstructorIO\Laravel\Constructor` (facade target) and `Services\SearchService` / `Services\RecommendationService` |
| `Contracts\SandboxSearchContract` | `Contracts\SearchProvider` |
| `Services\ConstructorAgentService` | `Services\AgentService`, or `Constructor::agent()` |
| `Services\ConstructorService` | `Services\CatalogService`, or `Constructor::catalog()` |
| `Search\ConstructorEngine` | `Scout\ConstructorEngine` |
| `Collections\SearchResultCollection` | `Scout\SearchResultCollection` |
| `Traits\BackendRequestContext` | Removed. Replaced by `Support\RequestContext`, which is injected. |

The old names are `class_alias`es and will be removed in 3.0. Constructor arguments changed: resolve the services from the container (`app(SearchService::class)`) rather than calling `new ...($apiKey, $apiToken)`.

### 5. Shopper identity

| 1.x | 2.0 | Why |
|---|---|---|
| `getRecommendations()` sent the **Laravel session ID** (or an IP hash) as `ui` | `ui` is only sent for signed-in users | `ui` must identify a signed-in user. A Laravel session ID is also a session credential and must not leave your app. |
| Docs suggested `'session_id' => session()->getId()` | `session_id` must be the beacon's integer session number. Non-integers are ignored. | `s` is an integer session number. |
| Cookies read from `$_COOKIE` | Read from the request's `Cookie` header | Correct on Octane and other long-running servers |
| No client ID on a first visit | A UUID is generated and set as the beacon's cookie | Documented first-visit behaviour |
| | `Constructor::resolveConsentUsing()` and `Constructor::resolveUserIdUsing()` | Consent and anonymised user IDs |

**Action:** remove any `'session_id' => session()->getId()` or `'user_id' => session()->getId()` options you pass.

### 6. Search, browse and autocomplete

- Endpoints are now `/v1/search/{query}` and `/v1/autocomplete/{prefix}`, as documented. Path segments are URL-encoded, so queries such as `1/2" grate #5` work.
- **Filters** are sent as `filters[name]=value`. 1.x sent a JSON `qs` blob.
- **Range filters** are sent as `price=50-200`. 1.x sent a `{min,max}` object, which the API doesn't accept.
- **Range facets** (price sliders) are now included in `$results->facets`, with `min`, `max` and `selected`. Each facet also has a full `options` list (`value`, `display_name`, `count`, `selected`, `data`). The 1.x `values` map is kept.
- `group_id` values are encoded once. 1.x double-encoded them; if your category IDs really contain encoded characters, pass them already encoded.
- `search('')` now returns no results. Use `browse()` for listing pages.
- New on `SearchResults`: `resultId` (1.x always returned null here), `redirect` / `isRedirect()` / `redirectUrl()`, `sortOptions`, `refinedContent`, `relatedSearches`, `isTotalCapped()`. Each product gains `variation_id`, `variations`, `variations_map`, `matched_terms`, `is_slotted`, `labels` and `strategy`.
- Autocomplete uses the documented `num_section_results_per_page`. Use the `results_per_section` option for custom sections. `AutocompleteResults::$sections` exposes every section.
- New: `browseItems(array $ids)`.
- Collections now use the public browse endpoints, `/browse/collections` and `/browse/collection_id/{id}`, and never send your API token. `getCollection()` returns `null` for an unknown collection; 1.x invented a name from the ID.
- `getAvailableFacets()` and `getFacetValuesWithImages()` use `/browse/facets` and `/browse/facet_options`. Option images come from the option's `image_url` data when set.
- `getRecipe()` looks the recipe up by ID. 1.x searched for the word "recipe" and filtered the first 100 results.
- `per_page` is capped at 200, the documented maximum.

### 7. Recommendations

- `$results->title` now holds the pod name; in 1.x it was always empty. `resultId` is new, and each product includes its `strategy`.
- `getItemRecommendations()` accepts one item ID or an array (for carts and bundles).
- New options: `term`, `filters` (`filters[name]=value`), `variation_id`, `pre_filter_expression`, `user_segments`.

### 8. AI agents

- `searchComplementaryProducts()` is **removed**. It built a clothing-specific "complete the outfit" prompt. Use a `complementary_items` recommendation pod instead:
  ```php
  Constructor::getItemRecommendations('pdp-complementary', $itemId, ['num_results' => 4]);
  ```
  You can also use the `item_results` returned by `askProductQuestion()`.
- Agent paths use `rawurlencode`, so spaces become `%20` rather than `+`.
- The Shopping Agent now handles every documented stream event. `follow_up_refinement` fills `refinements` and `follow_up_questions`, which were always empty in 1.x. `server_error` now throws; it used to be silently ignored.
- New return keys: `intent_result_id`, `result_ids`, `refinements`, `search_requests`, `alternative_search_requests`, `articles`, `images`, `recipe`, `route`. `_raw` now contains the list of received events.
- Streaming callbacks keep the 1.x event names (`start`, `message`, `products`, `follow_up`, `end`, `complete`) and add `error`, plus the raw names of other events.
- The answer endpoint only sends `num_results` when you pass it. New: `askProductQuestionStreaming()`.
- Agent errors throw `ConstructorException` subclasses instead of `\Exception`. `getProductQuestions()` still returns `['questions' => []]` on failure.

### 9. Catalog management

- `verify()` and the automatic `/v1/verify` pre-check are removed; that endpoint isn't in the API reference. Use `verifyCredentials(): bool`.
- `getCatalogInfo()` is removed; `GET /v1/catalog` doesn't exist. Use `listItems()`.
- `uploadCatalog()` sends `format=jsonl` for `.jsonl` files. In 1.x, JSONL was processed as CSV. New options: `variations_file`, `notification_email`, `mode`, `patch_delta`, `on_missing`. The return value always has a `task_id` key, which is `null` when Constructor answers 202.
- `waitForTaskCompletion($taskId, ?int $timeoutSeconds, ?int $pollIntervalSeconds)`: the 2nd and 3rd arguments were "max attempts" and "delay" in 1.x. It now treats `CANCELED` as finished and throws `TaskTimeoutException`.
- `getTaskStatus()` adds `error` and `warnings`.
- New: `upsertItems()`, `patchItems()`, `deleteItems()`, `getItem()`, `listItems()`.

### 10. Scout

- Items are sent in the documented shape, `{id, name, data: {...}}`. 1.x sent fields at the top level, which the API rejects, and swallowed the error. Implement `ConstructorSearchable::toConstructorItem()` for full control.
- The item ID is always the Scout key. Override `getScoutKey()` to index by SKU.
- `update()`, `delete()` and `flush()` now throw on failure, so queued syncs can retry. `flush()` processes the table in chunks.
- `paginate()` respects the page and per-page values. Searches carry the shopper's identity and backend token.
- The engine's 1.x extra methods (`autocomplete`, `browse`, `recommendations`, `getItem`) are removed. Use the facade or `CatalogService`.

### 11. Errors

- Storefront reads still return empty results on failure by default. Set `CONSTRUCTOR_THROW_EXCEPTIONS=true` to throw.
- Every exception extends `ConstructorException`. `AuthenticationException`, `RateLimitException` and `ApiException` carry the status code.
- Only connection errors, 429 and 5xx are retried. 1.x also retried 4xx errors.
- Browse requests are no longer logged at `info` level, and shopper identifiers are never logged.
