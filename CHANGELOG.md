# Changelog

All notable changes to `d076/laravel-tracing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the package is on `0.x`, minor versions may contain breaking changes; patch versions never do.

## [Unreleased]

## [0.2.0] - 2026-05-26

### Added
- `trace_id` propagation across job boundaries via `Illuminate\Support\Facades\Context`. Queued jobs, broadcasted events, chains, and retries automatically inherit the parent request's `trace_id` — no setup required in the host app.
- Response body capture and masking for inbound requests (`tracing.store_response_body`, `tracing.masked_response_body_params`). Masking is applied before truncation.
- Response body masking for outbound requests (`tracing.outgoing.masked_response_body_params`).
- Named rate limiter `tracing-api` for the UI API, configurable via `tracing.rate_limit.*`. The host app can override it by registering its own `RateLimiter::for('tracing-api', ...)`.
- `cross-db` test group exercising the search endpoint against PostgreSQL, MySQL, and SQLite.

### Changed
- Documentation split from a single README into `docs/architecture.md`, `docs/configuration.md`, and `docs/database.md`. README now contains only a quick start.

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

[Unreleased]: https://github.com/d076/laravel-tracing/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/d076/laravel-tracing/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/d076/laravel-tracing/releases/tag/v0.1.0
