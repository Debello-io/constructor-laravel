@AGENTS.md

## Claude Code notes

- **Constructor docs MCP**: `.mcp.json` registers `constructor-docs` (https://docs.constructor.com/mcp). Use its tools (`list-specs`, `list-endpoints`, `get-endpoint`, `search-endpoints`, `search`, `fetch`) to confirm every endpoint, parameter and response field before changing request code. Spec titles look like `Search (reference)`, `Browse (reference)`, `AI Shopping Agent (reference)` and `Catalog management (reference)`.
- **Skills** in `.claude/skills/`:
  - `verify-against-constructor-docs`: audit a service method against the API reference and produce a findings table.
  - `add-constructor-endpoint`: the checklist for wrapping a new endpoint the package way.
  - `release`: prepare a version (CHANGELOG, UPGRADE, tag). Never pushes or tags without explicit confirmation.
- **Subagent** `constructor-api-reviewer` (`.claude/agents/`): a read-only reviewer that checks a diff against the docs MCP and the rules in AGENTS.md. Use it before opening a PR that touches `src/Http`, `src/Services` or `src/Support`.
- **Hooks**: after each Edit/Write to a `.php` file, `.claude/hooks/format-php.sh` runs Pint on that file (only if `vendor/bin/pint` exists). Run `composer check` yourself before you say a change is done.
- **Permissions**: `.claude/settings.json` pre-approves the composer scripts, the read-only git commands and the docs MCP, and blocks reading `.env` files. Put personal overrides in `.claude/settings.local.json`, which is git-ignored.
