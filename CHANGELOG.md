# Changelog

All notable changes to `d076/laravel-tracing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the package is on `0.x`, minor versions may contain breaking changes; patch versions never do.

## [Unreleased]

## [0.3.3] - 2026-07-24

### Fixed
- `TRACING_ENABLED=false` is now a true master switch. Previously it only stopped inbound database recording: `TraceIdMiddleware` still ran and pushed a `trace_id` into `Illuminate\Support\Facades\Context` — which Laravel automatically attaches to every log record, so the id leaked into the host app's logs even with tracing "off" and no record was ever written. Outbound requests also kept being recorded, because they were gated by the independent `TRACING_OUTGOING_ENABLED` rather than the master flag. With the package disabled, the service provider now short-circuits its entire runtime: no middleware, no `Context`/`trace_id`, no `X-Trace-Id` response header, no outbound recording, and no UI routes. Migrations and config publishing stay registered, so the package still installs and `migrate`s while disabled.

### Changed
- **Behavior change** (only affects `TRACING_ENABLED=false`): the `X-Trace-Id` response header is no longer emitted when tracing is disabled. Previously the header was set on every response regardless of the flag. If your app disables tracing but relies on `X-Trace-Id`, keep `TRACING_ENABLED=true` and exclude routes via `ignore_paths` instead.

## [0.3.2] - 2026-07-23

### Fixed
- Non-UTF-8 response/request bodies no longer break persistence on a strict backend. Bodies are now normalized to UTF-8 before masking and truncation via the new `Support\BodyEncoding` helper: a body in a legacy charset declared in `Content-Type` (e.g. `windows-1251`) is transcoded, an undeclared legacy charset is detected best-effort, and a body that is neither valid UTF-8 nor decodable is stored as a `[non-UTF-8 body, N bytes]` marker. Previously such a body was written verbatim and a PostgreSQL UTF8 database aborted the whole `INSERT` with `invalid byte sequence for encoding "UTF8"`, so the record was lost (`Tracing: failed to persist ...`). SQLite/MySQL stored the raw bytes silently and were unaffected.
- Body truncation no longer splits a multi-byte UTF-8 character. `substr()` cut on a byte boundary and could leave a dangling lead byte (e.g. a Cyrillic body truncated mid-character), which a strict backend then rejected. Truncation now uses `mb_strcut`, keeping the byte budget while respecting character boundaries.
- Incoming records are no longer dropped when a JSON column (`body_params`, `query_params`, request/response headers) contains a non-UTF-8 byte. Eloquent's `array` cast `json_encode`d such a value to `false` and threw `JsonEncodingException`, which `persist()` swallowed — losing the record on **any** driver. Payloads now pass through `BodyEncoding::cleanForStorage()`, substituting invalid bytes with U+FFFD before the write.

## [0.3.1] - 2026-06-11

### Fixed
- Outbound `4xx`/`5xx` responses are now recorded even when the caller opts into Guzzle's `http_errors => true` (non-default). In that mode Guzzle turns the response into a rejected promise that Laravel marshals without dispatching a client event, so the `ResponseReceived`/`ConnectionFailed` listeners introduced in 0.3.0 never saw it. `OutgoingTracingMiddleware` sits inside Guzzle's `http_errors` middleware, still observes the fulfilled response, and records it. The default `http_errors => false` mode is unchanged — events remain the sole recorder, and there is no double-recording (verified for `2xx`, `4xx`/`5xx`, and connection failures under both modes).

## [0.3.0] - 2026-06-11

### Fixed
- Outbound requests that fail **without a response** — connection refused, DNS failure, read/connect timeout — are now recorded in `tracing_outgoing_requests`. Previously the Guzzle middleware attached its recording via a promise `->then(onRejected)` callback, which only observes *rejected* promises and silently missed exceptions thrown synchronously inside the handler stack, so these failures were never logged. Ordinary `4xx`/`5xx` responses were unaffected and are still recorded.
- Octane: inbound `duration_ms` is now measured from a per-request timestamp instead of the process-global `LARAVEL_START` constant. Under a long-lived Octane worker the constant is immutable and kept the first request's start time, so every later request reported an inflated duration. Timing under PHP-FPM is unaffected.

### Changed
- Outbound request **recording** moved from the `Http` facade global Guzzle middleware to the HTTP client events `ResponseReceived` and `ConnectionFailed`. The framework guarantees one of these fires for every request (any status, plus connection failures), so coverage no longer depends on Guzzle promise/handler-stack semantics. `OutgoingTracingMiddleware` is retained solely to inject the `X-Trace-Id` header when `outgoing.propagate_trace_id` is enabled — request mutation is only possible at the middleware level, not from an event.
- `exception_class` for connection-level failures is now `Illuminate\Http\Client\ConnectionException` (previously the underlying Guzzle class such as `GuzzleHttp\Exception\ConnectException`). Anything querying or alerting on that column value should account for the new value.

### Notes
- Edge case: if the host app explicitly opts into Guzzle's `http_errors => true` (not Laravel's default), a `4xx`/`5xx` becomes a rejected promise for which Laravel dispatches neither `ResponseReceived` nor `ConnectionFailed`, so that response is not recorded. With the default `http_errors => false` every response is captured. *(Fixed in 0.3.1.)*

## [0.2.4] - 2026-06-11

### Added
- Web UI: list filters, sorting, and pagination are now persisted in the URL query string. They survive navigation to a detail view and back (browser **Back**), and a filtered list can be bookmarked or shared via its URL.
- Web UI: detail views can be opened in a new tab — Cmd/Ctrl/Shift + click or middle-click on a table row, or the **↗** link that appears on row hover.

## [0.2.3] - 2026-05-28

### Changed
- Service provider is no longer auto-discovered. Register `D076\Tracing\Providers\TracingServiceProvider` explicitly in `bootstrap/providers.php`.

## [0.2.2] - 2026-05-27

### Added
- `phpbench` benchmark suite (`benchmarks/`) measuring per-request overhead in four modes; results table added to README.
- PHPStan level 6 via `larastan/larastan` (`phpstan.neon`); added as a CI step before tests.
- Generic array types (`array<string, mixed>`, `list<string>`, `array<string, list<string>>`) on all public service and model signatures.

### Fixed
- `TracingApiController`: `getDriverName()` was called inside a `where()` closure on `ConnectionInterface`, which does not declare that method. Moved outside the closure where the Eloquent Builder's concrete connection type is known.

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

[Unreleased]: https://github.com/d076/laravel-tracing/compare/v0.3.3...HEAD
[0.3.3]: https://github.com/d076/laravel-tracing/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/d076/laravel-tracing/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/d076/laravel-tracing/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/d076/laravel-tracing/compare/v0.2.4...v0.3.0
[0.2.4]: https://github.com/d076/laravel-tracing/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/d076/laravel-tracing/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/d076/laravel-tracing/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/d076/laravel-tracing/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/d076/laravel-tracing/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/d076/laravel-tracing/releases/tag/v0.1.0
