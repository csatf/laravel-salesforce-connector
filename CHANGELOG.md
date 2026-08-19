# Changelog

All notable changes to this package are documented here. This project adheres to
[Semantic Versioning](https://semver.org/) and
[Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.2.0] - 2026-08-19

### Added
- Laravel 13 support: `illuminate/contracts` and `illuminate/support` widened to
  `^11.0 || ^12.0 || ^13.0`, and `orchestra/testbench` to `^9.0 || ^10.0 ||
  ^11.0`. Verified against Laravel 13.26.1 / Testbench 11.2.0 / PHP 8.4 — the
  full suite passes. `omniphx/forrest` ^3.0 already allowed `^13.0`.

### Note
- Running this package's *own* test suite resolves to Laravel 12, because the
  `csatf/laravel-devtools` dev dependency is still capped at `^12.0`. That is a
  development-only constraint and does not affect consuming applications, which
  never install devtools. Laravel 13 compatibility was verified by resolving the
  package without devtools. Widening devtools will lift the dev cap.

## [0.1.0] - 2026-08-19

Initial extraction from `csatf/compliance-api` (`8-salesforce-roster-endpoints`).
Connectivity layer only — object and field mappings stay in consuming apps.

### Added
- Package-managed Forrest configuration: supplies `config('forrest')` from `SF_*`
  env vars, so apps no longer carry a published vendor config file. Defaults
  changed from Forrest's own: `ClientCredentials` (was `WebServer`),
  `client.verify => true` (was `false`, i.e. TLS verification disabled), and
  `storage.type => cache` (was `session`). An app-published `config/forrest.php`
  still takes precedence; opt out with `SF_MANAGE_FORREST_CONFIG=false`.
- `SoqlClient` — lazy single authentication, `nextRecordsUrl` pagination via
  `fetchAll()`, and `first()` / `scalar()` / `pluck()` result helpers. Recovers
  from a token that vanished from the cache by re-authenticating and retrying
  once.
- `Soql` — escaping helpers for SOQL literals, `LIKE` patterns, and `IN` lists.
- `SoqlQuery` — a small builder for SELECT/WHERE/GROUP BY/ORDER BY/LIMIT/OFFSET,
  clamping OFFSET to the Salesforce ceiling of 2000 with `exceedsOffsetCeiling()`
  to detect the clamp.
- `FilterCompiler` — compiles a declarative filter map plus request values into
  escaped SOQL conditions, skipping absent and empty values.
- `SalesforceHealth` — connectivity probe that distinguishes authentication
  failure from field-level read failure. Uses `versions()` rather than
  `limits()`, which returns `403 API_DISABLED_FOR_ORG` under the Salesforce
  Integration license.
- `SalesforceFake` — test double that binds itself as the container's `forrest`
  instance, with query-shape response routing, multi-page responses, and
  assertions over the recorded SOQL.
- `docs/salesforce-org-setup.md` — the org-side playbook and gotchas table.

### Fixed
- Unescaped `LIKE` wildcards. `Soql::escapeLike()` neutralises `%` and `_`, which
  the extracted implementation did not. Previously a `starts_with` filter given
  `%` compiled to `LIKE '%%'` and matched every row, silently disabling the
  filter; `_` matched any single character.
