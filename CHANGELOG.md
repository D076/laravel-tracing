# Changelog

All notable changes to `d076/laravel-tracing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the package is on `0.x`, minor versions may contain breaking changes; patch versions never do.

## [Unreleased]

## [0.2.1] - 2026-05-27

### Added
- GitHub Actions CI (`tests.yml`): PHP 8.3/8.4/8.5 × Laravel 11/12/13 matrix on SQLite, plus a separate cross-DB matrix for PostgreSQL and MySQL.
- README badges: CI status, PHP version, Laravel version, license.
- Both models override `getConnectionName()` so the custom connection is used automatically without calling `::on(...)` explicitly.
- Both migrations implement `getConnection()` so `php artisan migrate` creates `tracing_requests` and `tracing_outgoing_requests` on the configured connection, not the default one.
- `docs/configuration.md` gains a dedicated **"Separate database for tracing"** section with setup instructions, a docker-compose example, and an explanation of what changes under the hood.

### Fixed
- `TRACING_DB_CONNECTION` now correctly routes **all** tracing operations — writes, reads (UI + API), and pruning — to the configured connection. Previously only inserts used the custom connection; `prunable()` queries and the UI API silently fell back to the default database.

## [0.2.0] - 2026-05-27

### Added
- `trace_id` propagation across job boundaries via `Illuminate\Support\Facades\Context`. Queued jobs, broadcasted events, chains, and retries automatically inherit the parent request's `trace_id` — no setup required in the host app.
- Response body capture and masking for inbound requests (`tracing.store_response_body`, `tracing.masked_response_body_params`). Masking is applied before truncation.
- Response body masking for outbound requests (`tracing.outgoing.masked_response_body_params`).
- Named rate limiter `tracing-api` for the UI API, configurable via `tracing.rate_limit.*`. The host app can override it by registering its own `RateLimiter::for('tracing-api', ...)`.
- `cross-db` test group exercising the search endpoint against PostgreSQL, MySQL, and SQLite.

### Changed
- Documentation split from a single README into `docs/architecture.md`, `docs/configuration.md`, and `docs/database.md`. README now contains only a quick start.
- Minimum PHP version lowered to **8.3** (was 8.4). No code changes required.

### Security
- Outbound `application/x-www-form-urlencoded` request bodies now go through the same masking pipeline as JSON bodies. Previously sensitive fields (e.g. `password=...`) sent via `Http::asForm()` were stored in `tracing_outgoing_requests.request_body` in plain text. Masked keys are reused from `tracing.outgoing.masked_body_params`; nested fields follow PHP bracket syntax (`user[password]=...` ↔ `user.password`).

## [0.1.0] - 2026-05-25

Initial release.

### Added
- Inbound HTTP request tracing: captures method, URL, headers, body, response status, and duration into `tracing_requests`.
- Outbound HTTP request tracing via `Http` facade global middleware, persisted to `tracing_outgoing_requests`.
- `trace_id` (UUID7) generation; `X-Trace-Id` response header on all responses; optional propagation of `X-Trace-Id` to outbound requests.
- Configurable masking for headers and body parameters (dot-notation supported) on inbound requests and outbound JSON bodies.
- Two persistence modes: synchronous (`database`) and asynchronous (`queue`).
- Optional Vue SPA at `/tracing`, gated by the `viewTracing` ability (local environment by default).
- Retention via `php artisan model:prune` (`tracing.retention_days`, default 30).
- Cross-database SQL compatibility: PostgreSQL, MySQL, SQLite.

[Unreleased]: https://github.com/d076/laravel-tracing/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/d076/laravel-tracing/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/d076/laravel-tracing/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/d076/laravel-tracing/releases/tag/v0.1.0
