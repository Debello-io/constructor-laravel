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

## Hardening built into the package

- **Error messages:** exception messages never contain tokens, request URLs or Constructor's response bodies. The body is only available from `ApiException::getResponseBody()`. Connection errors drop the URL Guzzle adds.
- **Stack traces:** parameters that hold URLs or shopper identifiers are marked `#[\SensitiveParameter]`, so error trackers that capture frame arguments don't record them. `ConstructorConfig` masks both tokens in `dump()` / `dd()` output.
- **https only:** configured Constructor hosts must use https (plain http is accepted only for `localhost`).
- **Path encoding:** every dynamic URL path segment is encoded, including `.` and `..`, so input can't change the endpoint.
- **Identity:** endpoint parameters can't override the API key or the shopper identity values. `origin_referrer` is sent without the page's query string, and forwarded headers are sanitized.
- **Consent:** set `CONSTRUCTOR_REQUIRE_CONSENT=true` to send no client ID (and set no client ID cookie) until `Constructor::resolveConsentUsing()` reports consent. Constructor's documentation only requires omitting the client ID; the user ID, session number, IP and user agent are still sent.
- **Retries:** catalog writes and uploads are never retried, so a timeout can't create duplicate ingestion tasks.
