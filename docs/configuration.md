# Configuration

Publish the config to edit it:

```bash
php artisan vendor:publish --tag=tracing-config
```

## Inbound requests

| Variable | Default | Description |
|----------|---------|-------------|
| `TRACING_ENABLED` | `true` | Toggle database recording (`X-Trace-Id` always works) |
| `TRACING_DRIVER` | `database` | `database` (sync) or `queue` (async) |
| `TRACING_QUEUE` | `null` | Queue name for async mode |
| `TRACING_QUEUE_CONNECTION` | `null` | Queue connection |
| `TRACING_MAX_BODY_SIZE` | `10000` | Max body size in characters |
| `TRACING_STORE_RESPONSE_BODY` | `true` | Store the response body |
| `TRACING_STORE_RESPONSE_BODY_ONLY_JSON` | `true` | Store the response body only if it is JSON |
| `TRACING_DB_CONNECTION` | `null` | DB connection (null = default) |
| `TRACING_RETENTION_DAYS` | `30` | Retention in days (0 = never delete) |

## Outbound requests

| Variable | Default | Description |
|----------|---------|-------------|
| `TRACING_OUTGOING_ENABLED` | `true` | Enable outbound tracing |
| `TRACING_OUTGOING_DRIVER` | `database` | `database` or `queue` |
| `TRACING_OUTGOING_QUEUE` | `null` | Queue name |
| `TRACING_OUTGOING_QUEUE_CONNECTION` | `null` | Queue connection |
| `TRACING_OUTGOING_STORE_REQUEST_BODY` | `true` | Store the request body |
| `TRACING_OUTGOING_STORE_RESPONSE_BODY` | `true` | Store the response body |
| `TRACING_OUTGOING_MAX_BODY_SIZE` | `10000` | Max body size in characters |
| `TRACING_OUTGOING_PROPAGATE_TRACE_ID` | `false` | Add `X-Trace-Id` to outbound headers |
| `TRACING_OUTGOING_RETENTION_DAYS` | `30` | Retention in days (0 = never delete) |

## Web UI

| Variable | Default | Description |
|----------|---------|-------------|
| `TRACING_UI_ENABLED` | `true` | Enable the UI |
| `TRACING_UI_PATH` | `tracing` | URL prefix (`/tracing`) |

### UI authorization

By default `/tracing` is accessible only in the `local` environment. Override the gate in `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewTracing', function ($user): bool {
    return $user?->isAdmin() ?? false;
});
```

The gate is registered by `TracingServiceProvider` only if it has not been defined already — `AppServiceProvider` boots first, so overriding it there is safe.

## API rate limiting

Throttling applies **only** to the JSON API (`/{ui.path}/api/*`); the SPA shell and assets are not rate-limited, so the interface always loads. The limit is keyed per user (by the polymorphic `type:id` pair), or per IP for guests.

| Variable | Default | Description |
|----------|---------|-------------|
| `TRACING_RATE_LIMIT_ENABLED` | `true` | Enable API throttling |
| `TRACING_RATE_LIMIT_MAX_ATTEMPTS` | `120` | Requests per window |
| `TRACING_RATE_LIMIT_DECAY_MINUTES` | `1` | Window length in minutes |

For full control, define your own limiter in `AppServiceProvider::boot()` (the package won't overwrite an already-defined `tracing-api`):

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('tracing-api', fn ($request) =>
    Limit::perMinute(300)->by($request->user()?->getMorphClass().':'.$request->user()?->getKey() ?? $request->ip())
);
```

## Excluding routes (inbound)

`TRACING_ENABLED=false` disables database recording (`X-Trace-Id` keeps working). To exclude individual routes, use `ignore_paths` in the config (supports the `*` wildcard):

```php
'ignore_paths' => [
    'up',
    'horizon/*',
    'api/webhooks/*',
],
```

The UI path (`tracing/*`) is excluded automatically in `TracingServiceProvider::boot()`.

## Excluding URLs (outbound)

```php
'outgoing' => [
    'ignore_urls' => [
        'https://internal-health-check/*',
        '*/metrics',
    ],
],
```

Patterns are matched via `fnmatch()` against the full URL.

## Header and body masking

Sensitive values are replaced with `[REDACTED]` before being written to the database.

**Headers** — configured separately for inbound and outbound; case-insensitive:

```php
'masked_request_headers' => ['authorization', 'cookie', 'x-api-key'],

'outgoing' => [
    'masked_request_headers' => ['authorization', 'x-api-key'],
],
```

**Request body** — supports dot notation for nested keys; comparison is case-sensitive:

```php
// Inbound requests (body_params — array)
'masked_body_params' => [
    'password',           // $body['password']
    'password_confirmation',
    'current_password',
    'secret',
    'token',
    'user.password',      // $body['user']['password']
    'data.api_key',       // $body['data']['api_key']
],

// Outbound requests (JSON and application/x-www-form-urlencoded bodies)
'outgoing' => [
    // request body (request_body)
    'masked_body_params' => ['password', 'secret', 'token'],
    // response body (response_body); empty list disables masking
    'masked_response_body_params' => ['password', 'secret', 'token', 'access_token', 'refresh_token'],
],
```

**Response body** (JSON only, when `store_response_body=true`) — masked before truncation; dot notation is supported:

```php
// Inbound responses
'masked_response_body_params' => ['password', 'secret', 'token', 'access_token', 'refresh_token'],

// Outbound responses — under the 'outgoing' section (see above)
```

> **Note:** `password` masks only the top level. For a nested field, give the full path: `user.password`. For routes with sensitive bodies (e.g. `POST /login`), you can also add the route to `ignore_paths`.

> **Form-encoded outbound bodies.** For `application/x-www-form-urlencoded` outbound bodies, masking is dispatched by `Content-Type` and the body is round-tripped through `parse_str` / `http_build_query`. Nested fields follow PHP's bracket syntax (`user[password]=...`) and are addressed via dot notation (`user.password`) in the masked-keys list. Bodies sent without an explicit `Content-Type` are treated as JSON for backward compatibility; bodies with unknown content types are not parsed and pass through unchanged (only truncated).

## Async mode (queue)

```dotenv
TRACING_DRIVER=queue
TRACING_QUEUE=tracing

TRACING_OUTGOING_DRIVER=queue
TRACING_OUTGOING_QUEUE=tracing
```

Records are processed through Horizon without blocking the client response.

## trace_id propagation to jobs

The parent HTTP request's `trace_id` is automatically inherited by any job/event/chain/retry dispatched from that request — no application code required. Outbound `Http::*` calls inside the job are recorded with the same `trace_id` as the parent's row in `tracing_requests`.

Under the hood: `TraceIdMiddleware` puts the id into `Illuminate\Support\Facades\Context`, Laravel itself serializes Context on dispatch and restores it in the worker, and `TraceId` reads from there. Works for anything that uses the `Context` machinery: queued jobs, broadcasted events, chained jobs, batches, scheduled retries.

```php
// In your controller — nothing special required:
public function __invoke()
{
    ProcessOrderJob::dispatch($orderId);
    // Inside the job, Http::post(...) goes out with this request's trace_id.
}
```

For non-HTTP entry points (artisan commands, the scheduler), the id is generated on first access and also auto-added to Context, so jobs dispatched from there inherit it too.

## Separate database for tracing

By default the two tracing tables (`tracing_requests`, `tracing_outgoing_requests`) are created in your application's primary database. For production workloads where you log 100 % of traffic this means constant inserts and large table growth alongside your business data. A dedicated database isolates that load completely.

### Why bother?

| Concern | Without isolation | With isolation |
|---------|------------------|----------------|
| Disk growth | Tracing rows compete with app data on the same volume | Tracing DB can live on a separate, cheaper volume |
| Query plan pollution | Large tracing tables affect the query planner for unrelated tables | Completely separate statistics |
| Backup / restore | You must back up or restore tracing data together with app data | Independent schedules; tracing data can be excluded from app backups |
| Connection pool pressure | Tracing writes share the same pool as app queries | Dedicated connection pool |

### Setup

**1. Add a connection in `config/database.php`:**

```php
'connections' => [
    // Your existing connections …

    'tracing' => [
        'driver'   => 'pgsql',          // or 'mysql' / 'sqlite'
        'host'     => env('TRACING_DB_HOST', '127.0.0.1'),
        'port'     => env('TRACING_DB_PORT', '5432'),
        'database' => env('TRACING_DB_DATABASE', 'tracing'),
        'username' => env('TRACING_DB_USERNAME', 'tracing'),
        'password' => env('TRACING_DB_PASSWORD', ''),
        'charset'  => 'utf8',
        'prefix'   => '',
        'search_path' => 'public',
        'sslmode'  => 'prefer',
    ],
],
```

**2. Point the package at that connection:**

```dotenv
TRACING_DB_CONNECTION=tracing
```

**3. Run migrations** — they detect `TRACING_DB_CONNECTION` automatically and create both tables on the right database:

```bash
php artisan migrate
```

That's it. Writes, reads (UI + API), and pruning all route through the same `tracing` connection automatically. Your application's primary database is never touched by tracing traffic.

### docker-compose example (dedicated Postgres)

```yaml
services:
  app:
    environment:
      TRACING_DB_CONNECTION: tracing
      TRACING_DB_HOST: tracing_db
      TRACING_DB_DATABASE: tracing
      TRACING_DB_USERNAME: tracing
      TRACING_DB_PASSWORD: secret

  tracing_db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: tracing
      POSTGRES_USER: tracing
      POSTGRES_PASSWORD: secret
    volumes:
      - tracing_db_data:/var/lib/postgresql/data
    tmpfs:
      - /tmp

volumes:
  tracing_db_data:
```

### What changes under the hood

When `TRACING_DB_CONNECTION` is set:

- **Migrations** — both `tracing_requests` and `tracing_outgoing_requests` are created on that connection, not the default one.
- **Writes** — `TracingRequest` and `OutgoingRequest` models override `getConnectionName()` and route all inserts to the configured connection.
- **Reads** — the web UI and its JSON API query the same connection; no cross-database joins.
- **Pruning** — `model:prune` deletes from the correct connection.

Your primary database is completely unaware of tracing.

## Pruning old records

Both models implement `MassPrunable`. Add to the scheduler (`routes/console.php`):

```php
Schedule::command('model:prune', [
    '--model' => \D076\Tracing\Models\TracingRequest::class,
])->daily();

Schedule::command('model:prune', [
    '--model' => \D076\Tracing\Models\OutgoingRequest::class,
])->daily();
```

When `RETENTION_DAYS=0` the prune query returns 0 rows — there is no risk of accidentally deleting everything.
