# d076/laravel-tracing

A Laravel package for tracing inbound and outbound HTTP requests.
Each inbound request gets a unique `X-Trace-Id` (UUID7); every outbound request made via the `Http` facade, plus any jobs and events dispatched from that request, **automatically** inherit the same `trace_id`.

Optionally ships a web UI at `/tracing` for browsing recorded requests.

## Requirements

- **PHP** 8.4+
- **Laravel** 11 / 12 / 13
- **Database**: PostgreSQL / MySQL / SQLite

## Installation

```bash
composer require d076/laravel-tracing
php artisan migrate
```

The service provider is auto-discovered via Laravel Package Auto-Discovery. No changes to `bootstrap/app.php` are required — middleware and the Guzzle handler are registered by the provider itself.

Publish the config (optional):

```bash
php artisan vendor:publish --tag=tracing-config
```

## Quick start

Works out of the box with sensible defaults:
- synchronous recording of inbound and outbound requests to the database;
- masking for common secrets (`password`, `token`, `authorization`, `access_token`, etc.);
- `X-Trace-Id` header on every response;
- UI at `/tracing`, accessible only in the `local` environment by default;
- API rate limit of 120 req/min.

Key switches:

| Variable | Default | Purpose |
|----------|---------|---------|
| `TRACING_ENABLED` | `true` | Record inbound requests |
| `TRACING_OUTGOING_ENABLED` | `true` | Record outbound requests |
| `TRACING_DRIVER` | `database` | `database` (sync) or `queue` (async via Horizon) |
| `TRACING_UI_ENABLED` | `true` | Web UI |
| `TRACING_RETENTION_DAYS` | `30` | Retention in days; cleaned via `php artisan model:prune` |

See [docs/configuration.md](docs/configuration.md) for the full reference.

## UI authorization

By default `/tracing` is accessible only in the `local` environment. In production, override the gate in `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewTracing', fn ($user) => $user?->isAdmin() ?? false);
```

## Using trace_id in your code

```php
use D076\Tracing\Context\TraceId;

Log::info('processing order', ['trace_id' => app(TraceId::class)->get()]);
```

Queued jobs require no setup — `trace_id` is automatically inherited from the parent HTTP request (see [docs/configuration.md → trace_id propagation to jobs](docs/configuration.md#trace_id-propagation-to-jobs)).

## Documentation

- **[Architecture](docs/architecture.md)** — package components, lifecycle of inbound and outbound requests.
- **[Configuration](docs/configuration.md)** — full env reference, masking, rate limiting, async mode, route exclusions, trace_id propagation to jobs, UI authorization.
- **[Database](docs/database.md)** — what is stored, table schemas, example SQL queries.

## License

MIT
