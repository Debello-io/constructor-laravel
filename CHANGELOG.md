# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Blade components that render the beacon's tracking markup: `<x-constructor::search-results>`, `browse-results`, `tracking-data`, `item`, `recommendations`, `recommendation-item`, `quiz-results`, `product-detail`, `generic-results` and `button`. They write every documented `data-cnstrc-*` attribute, escaped, and add the `cnstrc-data` script tag on search and browse pages (only when Constructor returned the results). Views can be published with `--tag=constructor-views`.

## [2.0.0] - 2026-11-13

A full review against Constructor's API reference and backend-integration documentation. See [UPGRADE.md](UPGRADE.md).

### Security
- `x-cnstrc-token` is only ever the backend security token. 1.x fell back to sending the secret API token.
- The Laravel session ID and IP-derived hashes are no longer sent to Constructor as `ui`.
- Storefront code no longer uses the API token. Collections are read from public browse endpoints.
- Every dynamic path segment is URL-encoded, so caller input can't change the endpoint or add query parameters on authenticated requests.
- Logs no longer contain request parameters, shopper identifiers or response headers.
- Beacon cookies are read from the request, not `$_COOKIE`, so they can't leak between requests on Octane.
- `origin_referrer` no longer includes the page's query string (reset tokens, emails).
- Forwarded User-Agent and identity values are sanitized; a malformed header no longer turns a search into an error page.
- Exception messages and logs no longer include Constructor's response body; URL parameters are `#[\SensitiveParameter]`; tokens are masked in dumps.
- Endpoint `parameters` can no longer override `key`, `c`, `i`, `s` or `ui`.
- Consent applies to explicit `client_id` values; new `identity.require_consent` for opt-in consent.
- API hosts must use https. The generated client ID cookie is `Secure` on https.
- `.`/`..` path segments are fully encoded.

### Added
- `Constructor` entry class: `Constructor::agent()`, `Constructor::catalog()`, `Constructor::recommendations()`, `Constructor::searchService()`.
- `Constructor::resolveConsentUsing()` and `Constructor::resolveUserIdUsing()`.
- Client ID cookie generation on a first visit. `s=1` when the session cookie is missing.
- `browseItems()`, search redirects, `resultId` on every result DTO, sort options, refined content, related searches, range facets, hierarchical facets and full facet options.
- Recommendation `term`, `filters`, `variation_id` and multiple `item_id`s.
- `Constructor::getRecommendationPage()` (`RecommendationService::getPage()`): every pod on a recommendation page in one request, deduplicated across pods, with `pod_overrides` for per-pod settings. Returns `RecommendationPageResults`; each pod carries its own `resultId` for tracking. Uses the beta `/recommendations/v1/pages/{page_id}` endpoint.
- Full AI Shopping Agent event support: `follow_up_refinement`, `server_error`, `article_reference`, `image_meta` and recipe events. Result IDs for tracking. `askProductQuestionStreaming()`.
- Catalog: `variations_file`, JSONL `format`, `patch_delta`, `on_missing`, `mode`, `notification_email`. `upsertItems()`, `patchItems()`, `deleteItems()`, `getItem()`, `listItems()`, `verifyCredentials()`.
- `Scout\ConstructorSearchable` interface for Scout models.
- Typed exceptions under `ConstructorIO\Laravel\Exceptions`.
- `throw_exceptions`, `log_channel`, `default_section`, `identity.*`, `stream_timeout`, `catalog.*` and `scout.batch_size` settings.
- Sponsored Listings support: `is_sponsored` (from `labels.is_retail_media` or `labels.is_sponsored`), `sponsored_campaign_id`, `sponsored_campaign_owner` on products.
- Per-section autocomplete filters: `section_filters` and `section_pre_filter_expression`.
- `SearchResults::trackingData()` for the beacon's `cnstrc-data` script tag; `resultId` on `AutocompleteResults`; `relatedBrowsePages`; `variation_slice`; collection `active_overriding_collection`; task `indexed`.
- `catalog.batch_size`, `scout.sync_mode` (replace or patch), `identity.require_consent`.
- `naturalLanguageSearch()` for voice and conversational queries (`/v1/search/natural_language/{query}`), returning `SearchResults`.
- `getFacetsWithOptions()` for facets and their options without a search (`/browse/facets_with_options`), paged at up to 10 facets per request.
- Variations API: `upsertVariations()`, `patchVariations()`, `deleteVariations()`, `getVariation()`, `listVariations()` (`/v2/variations`).
- Item groups (categories) API: `upsertItemGroups()`, `patchItemGroups()`, `deleteItemGroups()`, `getItemGroup()`, `listItemGroups()` (`/v2/item_groups`).
- Catalog Batching Service for frequent small updates: `Constructor::catalogBatching()` with `patchItems()`, `deleteItems()`, `patchVariations()`, `deleteVariations()`, and the `catalog_batching_base_url` setting.
- Test suite (PHPUnit + Testbench), Pint, Larastan (level 8, source and tests) and GitHub Actions CI with SHA-pinned actions and Dependabot.
- `AGENTS.md`, `CLAUDE.md`, the Constructor docs MCP server, Claude Code skills, a review subagent, CONTRIBUTING and SECURITY.
- Laravel 13 support. The Scout engine requires Scout 11.
- Quizzes: `Constructor::quizzes()` (`QuizService`) with `getNextQuestion()`, `getResults()` and `getResultsConfig()`, returning the new `QuizQuestion` and `QuizResults` DTOs. New `quizzes_base_url` setting (`CONSTRUCTOR_QUIZZES_BASE_URL`).
- Offline behavioral actions: `Constructor::behavior()` (`BehaviorService`) with `recordOfflinePurchases()`, `recordOfflineReturns()` and `recordOfflineActions()`, validated locally and sent in batches of 100.

### Changed
- Package renamed to `debello-io/constructor-laravel`.
- Consistent option names: `page`/`per_page` for paged lists and `limit` for the rest (recommendations, category tree, agent questions). `num_results`, `max_items` and `max_children` still work.
- Search and autocomplete use the documented `/v1/search` and `/v1/autocomplete` endpoints.
- Filters use `filters[name]=value`, range filters use `min-max`, and lists use repeated keys.
- Catalog requests use HTTP Basic auth with the API token, as documented.
- Retries only cover connection errors, 429 and 5xx, with exponential backoff.
- The default client identifier is `cio-be-web-{app}`.
- The Scout engine sends documented item payloads, respects pagination, delegates search to `SearchService` and throws on indexing failures.
- Renamed: `ConstructorSandboxSearch` → `Constructor` / `SearchService`, `SandboxSearchContract` → `SearchProvider`, `ConstructorAgentService` → `AgentService`, `ConstructorService` → `CatalogService`, `Search\ConstructorEngine` → `Scout\ConstructorEngine`. The old names remain as deprecated aliases.

### Fixed
- Queries containing `/`, `?`, `#` or `%` hit the wrong endpoint.
- `result_id` was always null for search and browse.
- Recommendation pod titles were always empty.
- Range filters and range facets didn't work.
- Shopping Agent follow-up questions were never captured, and stream errors were ignored.
- SSE parser state leaked between requests.
- JSONL uploads were processed as CSV.
- Task polling never finished for `CANCELED` tasks.
- Scout paginated results always returned page 1, and 429s triggered unbounded recursion.
- `getFirstProductImageFromCollection()` always returned null.
- 1.x class names failed as type-hints and in `instanceof` checks until autoloaded.
- Scout: soft-deleted models made every search return nothing; `whereIn()` with an empty list matched everything; `take()` and paginator totals were capped at 200; an item ID of `"0"` was dropped.
- Shopping Agent streams that were cut off counted as successful, and `stream_timeout` didn't bound the total duration.
- `getAvailableFacets()` stopped at 20 facets; `browseItems()` sent more IDs than Constructor accepts; pagination with `offset` reported the wrong page.
- Malformed entries in API responses no longer crash the page.
- Task polling now makes its last check at the deadline, and handles a missing status.

### Removed
- PHP 8.1, Laravel 10 and Laravel 11 support. Laravel 11 no longer gets security fixes, and Composer blocks installing it.
- `config/constructor-catalog.php`, which was unused, and `config/scout.php.example`.
- `searchComplementaryProducts()`, a fashion-specific prompt. Use a `complementary_items` pod.
- `ConstructorService::verify()` and `getCatalogInfo()`, which called undocumented endpoints.
- `Traits\BackendRequestContext`, replaced by `Support\RequestContext`.

## [1.3.0] - 2026-02-12

### Added
- Backend integration headers and query parameters for server-side API calls.

## [1.2.0] - 2026-01-30

### Added
- Laravel 12 support.

## [1.0.0] - 2024-01-30

### Added
- Initial release: search, browse, autocomplete, zero state, recommendations, collections, facets, recipes, AI Shopping Agent, Product Insights Agent, catalog uploads and a Scout engine.
