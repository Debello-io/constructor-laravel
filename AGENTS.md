# AGENTS.md

Guidance for anyone (human or AI agent) changing this repository. Read it before you edit code.

## What this is

`debello-io/constructor-laravel` is a Laravel package for [Constructor](https://constructor.com) product discovery: search, browse, autocomplete, recommendations, the AI Shopping Agent / Product Insights Agent, catalog management and a Laravel Scout engine. Constructor has no official PHP SDK, so this package calls the REST API directly and must follow Constructor's rules for a **backend integration** itself.

Supports PHP ^8.2 and Laravel 11, 12 and 13 (Laravel 13 needs PHP 8.3+).

## Architecture map

| Path | Responsibility |
|------|----------------|
| `src/Constructor.php` | Entry point and facade target. Delegates to the services; implements `Contracts\SearchProvider`. |
| `src/Facades/Constructor.php` | Facade. Keep its `@method` list in sync with `Constructor`. |
| `src/ConstructorServiceProvider.php` | Bindings (singletons), config publishing, Scout driver, 1.x aliases, `EncryptCookies::except` for beacon cookies. |
| `src/Http/ApiClient.php` | **The only place that sends HTTP**, including SSE streams (`openStorefrontStream()`) and multipart uploads (`uploadToCatalog()`). Storefront vs catalog requests, retry policy, error → exception mapping. `send()`, `storefrontRequest()` and `catalogRequest()` are private. |
| `src/Http/Path.php` | `Path::segment()`: encodes one dynamic path segment, including dot-only segments (`.`, `..`). Use it for **every** dynamic segment. |
| `src/Http/QueryString.php` | Builds Constructor-style query strings (repeated keys, deepObject brackets, RFC 3986). |
| `src/Support/ConstructorConfig.php` | Typed, validated view of `config/constructor.php`. Read config through this, not `config()`. |
| `src/Support/RequestContext.php` | Shopper identity for backend integration: `i`, `s`, `ui`, `c`, `_dt`, `origin_referrer` and headers. |
| `src/Support/ParameterBuilder.php` | Translates friendly options (`per_page`, `range_filters`, ...) into API parameters. Documents every option. |
| `src/Support/ResultTransformer.php` | Shared response → array mapping for products, facets, groups and collections. |
| `src/Services/SearchService.php` | Search, browse, browse by IDs, autocomplete, zero state, facets, groups, collections, recipes. |
| `src/Services/RecommendationService.php` | Recommendation pods. |
| `src/Services/AgentService.php` | AI Shopping Agent (`/v1/intent`, SSE) and Product Insights Agent (`/v1/item_questions`). |
| `src/Support/ServerSentEventParser.php` | Incremental SSE parser used by `AgentService`. |
| `src/Services/CatalogService.php` | Catalog uploads, `/v2/items`, task polling. Uses the API token. |
| `src/Scout/` | `ConstructorEngine` (Scout driver), `SearchResultCollection`, and the optional `ConstructorSearchable` model interface (`getConstructorSection()`, `toConstructorItem()`). |
| `src/Concerns/HandlesStorefrontFailures.php` | Graceful-failure policy for storefront reads. |
| `src/Exceptions/` | `ConstructorException` base, plus `ConfigurationException`, `ConnectionException`, `ApiException`, `AuthenticationException` (401/403), `RateLimitException` (429), `InvalidArgumentException` (bad caller input), `InvalidCatalogDataException`, `TaskTimeoutException`. |
| `src/DataTransferObjects/` | `SearchResults`, `AutocompleteResults`, `RecommendationResults` (readonly). |
| `src/Services/Search/ConstructorSandboxSearch.php`, `src/Services/ConstructorAgentService.php`, `src/Services/ConstructorService.php`, `src/Search/ConstructorEngine.php`, `src/Collections/`, `src/Contracts/SandboxSearchContract.php` | **Deprecated 1.x names**, `class_alias` stubs only. Never add code to them; remove in 3.0. |
| `config/constructor.php` | All settings, each with a comment explaining it. |
| `tests/` | PHPUnit + Orchestra Testbench. `tests/TestCase.php` has the shared helpers. |

## Credentials: three kinds, never mix them

| Config key | What it is | Where it may be sent |
|------------|------------|----------------------|
| `api_key` | Public index key (`key_...`) | `key` query parameter on every request |
| `api_token` | Secret dashboard API token | **Only** catalog endpoints, as HTTP Basic username with an empty password |
| `backend_token` | Secret backend security token from Constructor | **Only** the `x-cnstrc-token` header on storefront and agent requests |

Never fall back from one to another (the 1.x code sent `api_token` as `x-cnstrc-token`, which was wrong). Never put `api_token` on storefront requests. Never log any token.

## Backend-integration identity rules

Source: [Required parameters](https://docs.constructor.com/docs/integrating-with-constructor-direct-api-integration-required-parameters), [Backend integration options](https://docs.constructor.com/docs/integrating-with-constructor-retrieving-results-integration-approaches-backend-integration-options).

- `i` comes from the beacon cookie `ConstructorioID_client_id`, and `s` from `ConstructorioID_session_id`. Read them from the **raw `Cookie` header** (EncryptCookies would null them, and `$_COOKIE` is wrong on Octane).
- If `i` is missing, generate a UUID **once per request** and queue it as the cookie. If `s` is missing, send `1`.
- `s` is a positive integer. **Never** send a Laravel session ID as `s` or `ui`: it is a session credential.
- Send `ui` only for signed-in users (`auth()->id()` or `Constructor::resolveUserIdUsing()`). Guests get no `ui`.
- Without consent (`Constructor::resolveConsentUsing()` returns false), omit `i` and don't set the cookie.
- `c` is `cio-be-web-{company}` (or `cio-be-mobile-{company}`). One value, from `ConstructorConfig::$clientIdentifier`.
- Headers: forward the shopper's `User-Agent` (sanitized), and their IP in `X-Forwarded-For` as a single address via `Request::ip()` (relies on TrustProxies).
- `origin_referrer` is the current page URL **without its query string** (for AJAX/fetch calls, the `Referer` without its query string). Query strings can hold reset tokens, signatures or emails.
- Consent applies to explicit `client_id` overrides too. With `identity.require_consent=true`, shoppers count as not consenting until `resolveConsentUsing()` says otherwise.
- Endpoint `parameters` can't override `key` or identity values: `ApiClient::storefrontUrl()` merges them last. Change identity only through the identity overrides.
- `result_id` must reach the page (`data-cnstrc-result-id`) so beacon events are attributed. Keep it on every DTO.

## Request encoding rules

- Build query strings with `QueryString::build()`. Lists repeat the key (`us=a&us=b`); maps use deepObject (`filters[color]=red&filters[color]=blue`). Never rely on `http_build_query` or Guzzle's `query` option.
- Encode **every** dynamic path segment (queries, IDs, pod IDs, filter names and values) with `Path::segment()`. `1/2" grill grate #5` must stay one segment, and `..` must not become a parent path.
- Range filters are strings, `"min-max"` with `-inf` / `inf` for open ends. Boolean filter values are `True` / `False`.
- Parameters the API documents as JSON strings (`pre_filter_expression`, `variations_map`) are JSON-encoded; everything else is not.

## Error handling policy

- Storefront reads (search, browse, autocomplete, recommendations) log a warning and return an empty DTO. Set `throw_exceptions=true` to throw instead. Use `HandlesStorefrontFailures::attempt()`.
- Agent and catalog calls throw a `ConstructorException` subclass, with three deliberate exceptions: `AgentService::getProductQuestions()` degrades like a storefront read, `CatalogService::verifyCredentials()` returns false on 401/403, and `CatalogService::getItem()` returns null on 404.
- Exception messages never include response bodies, because they can echo search terms or filter values. The body is on `ApiException::getResponseBody()`.
- `ConfigurationException` (missing key, token or domain) always throws.
- Retries: only connection errors, 429 and 5xx, and only for reads, with bounded exponential backoff that honours `Retry-After` (capped at 5 s; `ApiClient::baseRequest()`). Never retry other 4xx errors, catalog writes or uploads. Never recurse to retry.

## Coding conventions

- Laravel Pint (`pint.json`, Laravel preset) and PHPStan via Larastan (`phpstan.neon.dist`) must pass.
- Type everything: parameters, returns, properties. Use `readonly` where possible and `array<string, mixed>`-style docblock generics.
- Mark parameters that hold URLs, tokens or shopper identifiers `#[\SensitiveParameter]` so they stay out of stack traces.
- Sponsored products are flagged by Constructor as `labels.is_retail_media` (plus `sl_campaign_id` / `sl_campaign_owner`). `ResultTransformer` normalizes this to `is_sponsored` on each product. Don't check `labels.is_sponsored`: Constructor doesn't send it.
- Comments explain **why**, especially Constructor quirks, and link the docs.constructor.com page that defines the behaviour. Don't narrate what the code obviously does.
- Names say what things are (`SearchService`, `CatalogService`). No leftovers from demo apps ("Sandbox", fashion/outfit helpers).
- No dead code, no commented-out code, no unused config keys.
- Public API changes need: a facade `@method` update, a README section, a CHANGELOG entry, and an UPGRADE.md note if they break.
- A new config key needs a comment in `config/constructor.php`, a property in `ConstructorConfig`, a line in `.env.example` and a README mention. Recent ones: `identity.require_consent`, `catalog.batch_size` (replaces `scout.batch_size`, which is still read as a fallback), `scout.sync_mode` (`replace` or `patch`). Base URLs must be https (http only for localhost).

## Verify against Constructor's docs first

Before you add or change any request code (path, method, auth, parameter, response field), check it against the **Constructor docs MCP** (`https://docs.constructor.com/mcp`, configured in `.mcp.json`):
`list-specs` → `list-endpoints` → `get-endpoint`, plus `search` / `fetch` for guides. Cite the spec or doc ID in your PR. If the docs don't cover something, say so explicitly; never guess.

## Testing

- Every test extends `tests/TestCase.php`, which calls `Http::preventStrayRequests()`, so fake every response with `Http::fake()`.
- `actAsShopperRequest($cookies, $server)` simulates a browser request with beacon cookies. `queryOf($request)` and `pathOf($request)` inspect what was sent, including repeated keys.
- Assert on the **outgoing request** (path, query, headers, auth), not just on the returned DTO.
- Cover the error paths (401/403/429/5xx, connection failure) and the "no HTTP request" (console/queue) context.

## Commands

```bash
composer install
composer test      # PHPUnit
composer lint      # Pint, check only
composer format    # Pint, fix
composer analyse   # PHPStan / Larastan
composer check     # lint + analyse + test: run before every commit
```

## Definition of done

1. `composer check` passes.
2. Behaviour verified against the docs MCP, and the doc IDs cited.
3. Tests assert the outgoing requests.
4. README, CHANGELOG and, for breaking changes, UPGRADE.md updated. Facade `@method` list in sync.
5. No secrets, tokens or shopper identifiers (`i`, `s`, `ui`, IP) in logs, exceptions or fixtures.

## Don't

- Don't send or log `api_token` / `backend_token` anywhere they don't belong.
- Don't use a Laravel session ID, IP hash or other made-up value as `i`, `s` or `ui`.
- Don't read `$_COOKIE`, `$_SERVER` or call `session()` in services. Go through `RequestContext`.
- Don't call `Http::` directly from services. Go through `ApiClient`.
- Don't add code to the deprecated alias files.
- Don't commit `.env` files or real credentials, even in tests.
