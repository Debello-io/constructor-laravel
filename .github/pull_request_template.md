## What & why

<!-- What does this change, and why? Link issues. -->

## Constructor docs

<!-- Spec + endpoint or doc IDs you verified against, e.g. "Search (reference) GET /v1/search/{search_query}" -->

## Checklist

- [ ] Verified endpoints, parameters and response fields against the Constructor docs MCP / API reference
- [ ] All HTTP goes through `ApiClient`; shopper identity through `RequestContext`
- [ ] No credentials or shopper identifiers in logs, exceptions or fixtures
- [ ] Tests assert the outgoing request (`Http::fake`), including error paths
- [ ] `composer check` passes (Pint, PHPStan, PHPUnit)
- [ ] Facade `@method` list, README and CHANGELOG (`[Unreleased]`) updated
- [ ] UPGRADE.md updated for breaking changes
