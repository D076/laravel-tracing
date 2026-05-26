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

// Outbound requests (JSON bodies only)
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
