# Contributing

Thanks for helping improve the Constructor integration for Laravel.

## Setup

```bash
git clone https://github.com/Debello-io/constructor-laravel.git
cd constructor-laravel
composer install
composer check
```

You don't need Constructor credentials to work on the package. The tests fake every HTTP call and block real requests.

## Commands

| Command | What it does |
|---------|--------------|
| `composer test` | PHPUnit (Orchestra Testbench) |
| `composer lint` | Check code style with Laravel Pint |
| `composer format` | Fix code style |
| `composer analyse` | Static analysis with PHPStan / Larastan |
| `composer check` | All of the above. Must pass before you open a PR. |

## How we work

[AGENTS.md](AGENTS.md) is the source of truth for architecture, conventions and rules. The short version:

- **Check Constructor's docs first.** Every endpoint, parameter and response field must match the [API reference](https://docs.constructor.com/reference). The docs are also available as an MCP server (`https://docs.constructor.com/mcp`, configured in `.mcp.json`) for AI-assisted work.
- **All HTTP goes through `ApiClient`**, and all shopper identity through `RequestContext`.
- **Never mix credentials** (API key, API token, backend token), and never log them or shopper identifiers.
- **Write readable code.** Type everything, give things intuitive names, and write comments that explain *why* and link the relevant Constructor doc.
- **Test the outgoing request** with `Http::fake()`, not only the returned value.
- **Document user-facing changes** in README.md and CHANGELOG.md (`[Unreleased]`), plus UPGRADE.md for breaking changes.

## Using Claude Code

The repo ships Claude Code configuration:

- `CLAUDE.md`: loads AGENTS.md
- `.mcp.json`: the Constructor docs MCP
- `.claude/skills/`: `verify-against-constructor-docs`, `add-constructor-endpoint`, `release`
- `.claude/agents/constructor-api-reviewer.md`: a read-only reviewer
- `.claude/settings.json`: safe command permissions and a Pint hook

Personal overrides go in `.claude/settings.local.json` (git-ignored).

## Pull requests

- Keep each PR to one topic, with a clear description and links to the Constructor docs you relied on.
- Fill in the PR template checklist.
- Report security issues privately. See [SECURITY.md](SECURITY.md).
