---
name: add-constructor-endpoint
description: Step-by-step checklist for wrapping a new Constructor API endpoint (or a new option on an existing one) in this package the conventional way - verified against the docs MCP, routed through ApiClient, with typed results, Http::fake tests, facade docs, README and CHANGELOG. Use when asked to add support for a Constructor feature, endpoint or parameter.
---

# Add a Constructor endpoint

Follow the steps in order. Read `AGENTS.md` first if you haven't.

## 1. Confirm the contract in the docs

Use the `verify-against-constructor-docs` skill, step 2: get the exact host, path, method, auth, parameters (with `style`), limits and response schema from `mcp__constructor-docs__get-endpoint`. Note the spec title and operation ID; you'll cite them in the docblock and the PR.

## 2. Choose the home

| Endpoint kind | Goes in |
|---------------|---------|
| Search / browse / autocomplete / facets / groups / collections | `src/Services/SearchService.php` |
| Recommendation pods or pages | `src/Services/RecommendationService.php` |
| AI agents (agent.cnstrc.com) | `src/Services/AgentService.php` |
| Catalog, items, variations, tasks (needs API token) | `src/Services/CatalogService.php` |

If it's a storefront feature most apps will call, also add a delegating method to `src/Constructor.php` and, when it fits the contract, to `src/Contracts/SearchProvider.php`.

## 3. Build the request

- Storefront: `$this->api->getStorefront($path, $parameters, ParameterBuilder::identity($options))`. Shopper identity, `key`, `c` and backend headers are added for you.
- Catalog: `$this->api->catalog($method, $path, $query, $json)`. This adds Basic auth with the API token, `key` and `c`.
- `rawurlencode()` every dynamic path segment.
- Reuse `ParameterBuilder::forResults()` / `shared()` for filters, sorting, paging and formatting. Add a new friendly option to `ParameterBuilder` (and its docblock) only if it is reusable.
- JSON-string parameters (`format: json`) are encoded; deepObject parameters are passed as arrays and `QueryString` serializes them.
- Wrap storefront reads in `$this->attempt('operationName', fn () => ..., fn () => <empty DTO>)`. Agent and catalog methods throw.

## 4. Shape the result

- Products, facets, groups and collections go through `ResultTransformer`. Extend it rather than writing a new mapper.
- Return an existing DTO when it fits. Otherwise add a readonly DTO in `src/DataTransferObjects/` with `toArray()` and `empty()`.
- Keep `result_id` (needed for tracking) and anything a storefront needs to render (redirects, sponsored labels, refined content).

## 5. Document in code

- A method docblock covering what it's for, a short usage example, the options, and `@see https://docs.constructor.com/reference/<operation-id>`.
- Comments explain Constructor quirks (why), not mechanics (what).
- A facade `@method static` line in `src/Facades/Constructor.php`.
- Any new config key gets a commented entry in `config/constructor.php`, plus `.env.example` and README.

## 6. Test

In `tests/Feature/` (service behaviour) or `tests/Unit/` (pure helpers):

```php
Http::fake(['ac.cnstrc.com/*' => Http::response($fixture)]);

$results = app(SearchService::class)->newMethod(...);

Http::assertSent(function ($request) {
    return $this->pathOf($request) === '/expected/path'
        && $this->queryOf($request)['filters[brand]'] === ['Weber'];
});
```

Cover the happy path, parameter encoding, response mapping, an error status (graceful empty result or a typed exception) and `throw_exceptions=true`. Base fixtures on the response schema from the docs.

## 7. Finish

- `composer check` passes.
- README section with an example. CHANGELOG under `[Unreleased]` → `Added`.
- Run the `constructor-api-reviewer` agent on the diff.
