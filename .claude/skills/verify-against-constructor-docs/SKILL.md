---
name: verify-against-constructor-docs
description: Audit a service method (or a whole service) in this package against Constructor's official API reference through the constructor-docs MCP, and report mismatches in path, method, auth, parameters, encoding and response shape. Use before changing request code, when reviewing a PR that touches src/Http, src/Services or src/Support, or when asked "is this up to date with Constructor's docs?".
---

# Verify against Constructor docs

Goal: every request this package sends matches the current Constructor API reference. Only report a mismatch you have **confirmed in the MCP**. Label anything the docs don't cover as "not documented".

## 1. Identify what the code sends

For the method under review, read the code path end to end:

- `src/Services/*Service.php`: endpoint path, endpoint parameters, response mapping
- `src/Support/ParameterBuilder.php`: how options become parameters
- `src/Http/ApiClient.php`: host, auth (storefront vs catalog), headers, retry
- `src/Http/QueryString.php`: serialization (repeated keys, deepObject)
- `src/Support/RequestContext.php`: identity parameters (`i`, `s`, `ui`, `c`, `_dt`, `origin_referrer`) and headers
- `src/Support/ResultTransformer.php` and `src/DataTransferObjects/*`: which response fields are kept

If there is a test for it, run it and use `Http::fake` recordings to see the exact URL.

## 2. Find the endpoint in the docs

1. `mcp__constructor-docs__list-specs` lists spec titles, e.g. `Search (reference)`, `Browse (reference)`, `Autocomplete (reference)`, `Recommendations (reference)`, `AI Shopping Agent (reference)`, `Catalog management (reference)`, `Catalog Batching Service (reference)`.
2. `mcp__constructor-docs__list-endpoints` with that title gives you the exact path and method.
3. `mcp__constructor-docs__get-endpoint` with path, method and title returns parameters, security, servers and response schemas.
4. Use `mcp__constructor-docs__search-endpoints` when you only know a parameter or keyword.
5. For cross-cutting rules, `mcp__constructor-docs__fetch` these:
   - `constructor/docs/integrating-with-constructor-direct-api-integration-required-parameters`
   - `constructor/docs/integrating-with-constructor-retrieving-results-integration-approaches-backend-integration-options`
   - `constructor/reference/main-authentication`
   - `constructor/reference/constructor-concepts-results-response-structure`

## 3. Compare, item by item

| Check | What to look for |
|-------|------------------|
| Host | `servers[].url` (ac.cnstrc.com, agent.cnstrc.com, batching host...) |
| Path & method | Exact path, including `/v1` prefix; every dynamic segment `rawurlencode`d |
| Auth | `security: []` means public, so no `api_token`. Private endpoints need Basic auth with the API token as username. Required scopes. |
| Required params | Present, with correct names and types (e.g. `s` integer) |
| Param style | `style: deepObject, explode: true` → `QueryString` brackets. Arrays → repeated keys. `format: json` → JSON string. |
| Limits | `maximum` / `maxItems` / `maxLength` enforced or clamped (per_page 200, prefix 200 chars, batch sizes) |
| Backend headers | `x-cnstrc-token` (backend token only), `X-Forwarded-For` (single IP), `User-Agent` forwarded |
| Response mapping | Fields read from the right place (e.g. `result_id` is top level; `pod` is under `response`). Useful fields not dropped. Enum values handled (task statuses, facet types, SSE event names). |
| Errors | Documented status codes map to the right exception |
| Docs/comments | Docblocks, README and config comments agree with the spec |

## 4. Report

Produce a table, most severe first:

| Severity | Location (`file:line`) | Code does | Docs say (spec + endpoint or doc ID) | Fix |
|----------|------------------------|-----------|--------------------------------------|-----|

Severity: **critical** (data loss, credential exposure, every call fails), **high** (feature broken or wrong data), **medium** (degraded or missing documented option), **low** (cosmetic, docs).

Then list documented capabilities the package doesn't wrap yet, one line each, if relevant.
