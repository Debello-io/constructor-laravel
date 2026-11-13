# Security Policy

## Reporting a vulnerability

Please **do not** open a public issue for security problems. Report them privately with GitHub's [private vulnerability reporting](https://github.com/Debello-io/constructor-laravel/security/advisories/new) for this repository.

Include what you found, how to reproduce it and the versions affected. We aim to reply within a few working days and will coordinate a fix and disclosure with you.

Vulnerabilities in Constructor's own service should be reported to Constructor directly.

## Supported versions

| Version | Supported |
|---------|-----------|
| 2.x | Yes |
| 1.x | No. Please upgrade (see UPGRADE.md). |

## How the package handles credentials

Constructor uses three different credentials. The package keeps them apart:

| Setting | Sensitivity | Sent where |
|---------|-------------|-----------|
| `CONSTRUCTOR_API_KEY` | Public | `key` query parameter on every request |
| `CONSTRUCTOR_API_TOKEN` | **Secret** | Only catalog-management endpoints, via HTTP Basic auth |
| `CONSTRUCTOR_BACKEND_TOKEN` | **Secret** | Only the `x-cnstrc-token` header on server-side storefront and agent requests |

- Secrets are never sent to the browser, put in URLs or written to logs.
- Shopper identifiers (client ID, session number, user ID, IP address) are forwarded to Constructor as its backend-integration docs require, but are never logged by the package.
- The Laravel session ID is never sent to Constructor.
- Keep `.env` out of version control, and rotate a token in the Constructor dashboard if it leaks.
