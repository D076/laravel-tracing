# Configuration

Publish the config to edit it:

```bash
php artisan vendor:publish --tag=tracing-config
```

## Inbound requests

| Variable | Default | Description |
|----------|---------|-------------|
| `TRACING_ENABLED` | `true` | Master switch. `false` fully disables the package at runtime: no inbound/outbound recording, no `trace_id` in `Context` (so it stops leaking into your application logs), no `X-Trace-Id` header, no UI routes. Migrations and config publishing stay available. |
| `TRACING_DRIVER` | `database` | `database` (sync) or `queue` (async) |
| `TRACING_QUEUE` | `null` | Queue name for async mode |
| `TRACING_QUEUE_CONNECTION` | `null` | Queue connection |
| `TRACING_MAX_BODY_SIZE` | `10000` | Max body size in bytes (truncation respects UTF-8 character boundaries) |
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
| `TRACING_OUTGOING_MAX_BODY_SIZE` | `10000` | Max body size in bytes (truncation respects UTF-8 character boundaries) |
| `TRACING_OUTGOING_PROPAGATE_TRACE_ID` | `false` | Add `X-Trace-Id` to outbound headers |
| `TRACING_OUTGOING_RETENTION_DAYS` | `30` | Retention in days (0 = never delete) |

## Web UI

| Variable | Default | Description |
|----------|---------|-------------|
| `TRACING_UI_ENABLED` | `true` | Enable the UI |
| `TRACING_UI_PATH` | `tracing` | URL prefix (`/tracing`) |
| `TRACING_UI_THEME` | `system` | Theme a visitor who has not picked one sees: `system`, `light`, `dark` or `bimbo-pink` |
| `TRACING_UI_ENABLED_THEMES` | *(all)* | Comma-separated list of themes the interface offers, e.g. `light,dark`. Empty offers all of them |

### UI theme

`system` follows the operating system's `prefers-color-scheme` and switches with it; `light` and `dark` pin the interface regardless of the OS. `bimbo-pink` is a light theme in pink, keeping the green/red reading of response classes.

The value is only the **default**. A visitor picks a theme with the switcher in the header, and the choice is stored per browser under the `tracing.theme` key in `localStorage`; from then on it wins over the config. The shell applies the stored value before the first paint, so switching themes does not cost a flash of the wrong one on every load.

A value naming no theme the stylesheet defines — a typo, or a theme removed in an upgrade — falls back to `system` instead of reaching the markup, so a misconfiguration renders the default interface rather than an unstyled one.

### Restricting the themes on offer

`TRACING_UI_ENABLED_THEMES` narrows what the switcher shows. Left empty — the default — it offers every theme the stylesheet ships. `TRACING_UI_ENABLED_THEMES=light,dark` offers those two and nothing else; an application that publishes the config can write the same as an array under `tracing.ui.enabled_themes`. An id naming no theme in the registry is ignored rather than treated as an error.

`system` is not a palette. It is the rule "follow `prefers-color-scheme`", which is a choice *between* `light` and `dark` — so its availability is **inferred, not configured**. Listing it while either of the two is switched off leaves a switch that changes nothing, so it is dropped:

| `TRACING_UI_ENABLED_THEMES` | Offered |
|---|---|
| *(empty)* | `system`, `light`, `dark`, `bimbo-pink` |
| `system,light,dark` | `system`, `light`, `dark` |
| `system,light` | `light` — `system` has no dark half to switch to |
| `light,dark` | `light`, `dark` — `system` was not asked for |
| `sepia` | `light` — see below |

The list never empties out. A value that would leave nothing — only unknown ids, or only `system` with nothing to derive from — falls back to `light`, because an interface with no palette is worse than one whose config was ignored.

The **default** (`TRACING_UI_THEME`) is reconciled against the same list: if it names a theme that is not on offer, the interface opens with `system` when that is available, and otherwise with the first theme that is. A visitor's stored choice is validated against the list too, so a theme switched off after they picked it stops applying on their next load.

Withdrawing a theme does not remove its palette from the compiled stylesheet — those few kilobytes ship in `app.css` regardless, since stripping them would mean generating the CSS per installation at runtime. The configuration decides what the interface *offers*, not what the bundle contains.

If exactly one theme remains, the switcher is not rendered at all.

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

`TRACING_ENABLED=false` is a master switch that disables the whole package at runtime (recording, `Context`/`trace_id`, the `X-Trace-Id` header, and the UI). To keep tracing on but exclude individual routes, use `ignore_paths` in the config (supports the `*` wildcard):

```php
'ignore_paths' => [
    'up',
    'horizon/*',
    'api/webhooks/*',
],
```

The UI path (`tracing/*`) is excluded automatically in `TracingServiceProvider::boot()`.

## Restricting tracing to specific routes (inbound)

`only_paths` is the mirror image of `ignore_paths`: an allowlist. While it is empty (the default) everything is traced except `ignore_paths`. Once it is non-empty, **only** matching routes are traced:

```php
'only_paths' => [
    'api/*',
    'webhooks/*',
],
```

`ignore_paths` keeps working and is subtracted from the allowlist, so a route matched by both is not traced:

```php
'only_paths'   => ['api/*'],      // trace the API…
'ignore_paths' => ['api/health'], // …except the health check
```

Patterns use the same `*` wildcard and the same `Request::is()` matching as `ignore_paths`.

Being outside the allowlist only suppresses **recording**. `trace_id` is still generated and propagated into `Context`, logs and outbound `Http::*` calls, and the `X-Trace-Id` response header is still sent — exactly as for an ignored path. To switch the package off entirely, use `TRACING_ENABLED=false`.

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

**Request body** — supports dot notation for nested keys; comparison is case-sensitive and matches the **whole key**, never a substring:

```php
// Inbound requests (body_params — array)
'masked_body_params' => [
    'password',           // $body['password']
    'password_confirmation',
    'current_password',
    'secret',
    'token',
    'access_token',
    'refresh_token',
    'api_token',
    'api_key',
    'client_secret',
    'private_key',
],

// Outbound requests (JSON and application/x-www-form-urlencoded bodies)
'outgoing' => [
    // request body (request_body)
    'masked_body_params' => [
        'password', 'secret', 'token', 'access_token', 'refresh_token',
        'api_token', 'api_key', 'client_secret', 'private_key',
    ],
    // response body (response_body); empty list disables masking
    'masked_response_body_params' => [
        'password', 'secret', 'token', 'access_token', 'refresh_token',
        'api_token', 'api_key', 'client_secret', 'private_key',
    ],
],
```

**Response body** (JSON only, when `store_response_body=true`) — masked before truncation; dot notation is supported:

```php
// Inbound responses
'masked_response_body_params' => [
    'password', 'secret', 'token', 'access_token', 'refresh_token',
    'api_token', 'api_key', 'client_secret', 'private_key',
],

// Outbound responses — under the 'outgoing' section (see above)
```

The two request lists differ by design: `password_confirmation` and `current_password` are things a client *sends*, so they are only on the inbound request list — a response echoing them would be a bug of its own, not a case to plan for.

> **Note:** matching is by exact key name, not by substring — `token` does **not** cover `access_token`, which is why every member of the family is spelled out above. A field named for your own domain (`stripe_token`, `otp`, `pin`) still has to be added by hand.

> **Note:** `password` masks only the top level. For a nested field, give the full path: `user.password` (`$body['user']['password']`), `data.api_key`. For routes with sensitive bodies (e.g. `POST /login`), you can also add the route to `ignore_paths`.

> **Publishing the config freezes these lists.** A published `config/tracing.php` supplies each masking list *whole* — lists are never merged element-by-element with the package's (see the 0.5.0 entry in the changelog). A default gaining a new key in a later release therefore does not reach an application that has published its config; copy the additions across by hand.

> **Form-encoded outbound bodies.** For `application/x-www-form-urlencoded` outbound bodies, masking is dispatched by `Content-Type` and the body is round-tripped through `parse_str` / `http_build_query`. Nested fields follow PHP's bracket syntax (`user[password]=...`) and are addressed via dot notation (`user.password`) in the masked-keys list. Bodies sent without an explicit `Content-Type` are treated as JSON for backward compatibility; bodies with unknown content types are not parsed and pass through unchanged (only truncated).

> **Character encoding.** Everything written to a record is valid UTF-8, so a strict backend (PostgreSQL with a UTF8 database) never rejects the `INSERT` over a stray byte and costs you the whole record. The rule is small, and the encoding is only ever taken from what the sender **declared** — nothing is inferred from the bytes:
>
> | Input | Stored as |
> |---|---|
> | Valid UTF-8 | unchanged |
> | `Content-Type: …; charset=windows-1251` (or any charset that converts) | converted to UTF-8 |
> | Anything else — a body | `[non-UTF-8 body, N bytes]` marker |
> | Anything else — a parameter, header or user agent | left as-is, then U+FFFD via the storage backstop |
>
> This applies equally to bodies and to request parameters, headers and `user_agent`, which arrive already parsed and so carry the legacy bytes inside individual values.
>
> **Charset detection is deliberately not used.** It cannot separate text from binary: Windows-1251 leaves exactly one byte of 256 undefined, so a strict detector accepts a JPEG, a gzip stream or a raw HMAC and converts it into fluent-looking Cyrillic that was never in the payload. Fencing that off by media type and control-byte ratios was tried and leaked on high-byte-only blobs, which carry no control bytes at all. For an audit trail, fabricated text is a worse failure than visibly missing text.
>
> **The trade-off:** legacy text from a sender that declares no charset is not recovered — you get the marker, or U+FFFD in a parameter, rather than readable Russian. In practice a sender that knows it is not UTF-8 says so in `Content-Type`. Query parameters are the weak spot, since a `GET` carries no `Content-Type` at all; if that matters for an integration you control, have it declare a charset or send UTF-8.
>
> One consequence worth knowing: a charset parameter is believed even if the payload turns out to be binary, because a single-byte charset converts any byte sequence. A sender that mislabels a blob as `text/plain; charset=windows-1251` gets garbage text stored. The alternative is a media-type blocklist, i.e. guessing again.

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

## Tags

Attach arbitrary, application-defined tags to traced records and search by them — the equivalent of `Telescope::tag()`. Tags are a `list<string>`; the package never invents keys, the format is entirely yours (`team:billing`, `tenant:42`, `flow:checkout`).

| Variable | Default | Description |
|----------|---------|-------------|
| `TRACING_TAGS_IN_LOGS` | `false` | Store tags in the **visible** `Context` (they then appear in every application log record). Default `false` keeps them in the **hidden** `Context` — propagated everywhere, but never added to your logs. |

### Tagging from application code

```php
use D076\Tracing\Facades\Tracing;

Tracing::tag('team:billing', 'tenant:42');  // add on top of existing tags
Tracing::setTags(['flow:checkout']);        // replace the whole set
Tracing::untag('tenant:42');                // remove specific tags
Tracing::clearTags();                       // drop all tags for this scope
Tracing::tags();                            // => list<string> currently in scope
```

Tags are deduplicated and trimmed; empty strings are ignored.

### Scope and propagation

Tags live in `Illuminate\Support\Facades\Context`, exactly like `trace_id`, which gives them the same reach with no extra wiring:

- **Inbound → outbound.** A tag set during a request is attached to every outbound `Http::*` call made later in that request, *and* to the inbound record itself.
- **Across job boundaries.** Queued jobs, events, chains, batches, and retries inherit the dispatching scope's tags; outbound calls inside the job carry them too.
- **Jobs / CLI without an inbound request.** Works the same — tag anywhere and the records written afterwards pick it up.

Timing is by design: the inbound record snapshots tags at `terminate()` (everything accumulated during the request), while each outbound record snapshots them when that call completes. So a tag added *after* an outbound call lands on the inbound record but not on that earlier outbound row.

Tags are reset at the start of every inbound request (alongside `trace_id`), so nothing leaks between requests under Octane.

### Searching by tags

| Parameter | Matching | Notes |
|-----------|----------|-------|
| `?tag=team:billing` | exact | Repeat or comma-separate for AND: `?tag=a,b` or `?tag[]=a&tag[]=b`. Uses the index on PostgreSQL. **Comma is reserved** by this syntax — a tag containing one can only be matched via the `?tag[]=` form. |
| `?search=billing` | substring | The standard search box — also matches URL and headers. |

Both parameters work on `/{ui.path}/api/requests` and `/{ui.path}/api/outgoing`. In the web UI, tags render as chips on list rows and detail pages; clicking a chip applies the exact-tag filter, and typing in the search box matches tags by substring.

### Storage

Tags are stored in a nullable `tags` JSON column on both tables, added by an additive migration. On PostgreSQL the migration also creates a **GIN index** on each `tags` column, so exact-tag filtering stays fast at scale (a plain btree index cannot serve JSON containment). Without tags, behaviour and storage are unchanged.

## Search

The UI has two search inputs, backed by two API parameters. They are separate on purpose: one is cheap enough to run on every keystroke, the other scans captured payloads and is deliberately opt-in.

| Parameter | Input | Covers |
|-----------|-------|--------|
| `?search=` | main box, live as you type | Trace ID / record id (exact, when the term is a UUID), URL, request **and** response headers, tags |
| `?payload=` | **Deep search** box, runs on Enter | everything `search` covers **plus** request/response bodies, query parameters, and the recorded exception |

Both work on `/{ui.path}/api/requests` and `/{ui.path}/api/outgoing`, are case-insensitive substring matches, and combine with every other filter (method, status, date range, tag) using AND. `payload` is a strict superset of `search`, so the natural workflow is: try the main box, and if it finds nothing, repeat the term in Deep search.

```
GET /tracing/api/requests?payload=%2B79023396677&date_from=2026-07-01
```

Percent-encode the term. A raw `+` in a query string decodes to a space — the UI handles this for you, but hand-written URLs must use `%2B`.

Search terms are matched **literally**: `%` and `_` are escaped rather than treated as LIKE wildcards, so searching for `100%` finds `100%` and not every record.

### Date filters

`?date_from=` and `?date_to=` accept exactly three formats:

As an upper bound (`?date_to`), each format is rounded up to the end of the unit it leaves unspecified, so the range is always inclusive of its own last second:

| Format | Example | `date_from` starts at | `date_to` ends at |
|---|---|---|---|
| `Y-m-d` | `2026-07-01` | `00:00:00` | `23:59:59` (end of day) |
| `Y-m-d H:i` | `2026-07-01 14:30` | `14:30:00` | `14:30:59` (end of minute) |
| `Y-m-d H:i:s` | `2026-07-01 14:30:15` | `14:30:15` | `14:30:15` (exact second) |

Anything else — a different format, a date that does not exist (`2026-02-31`), a relative string (`yesterday`), or an array (`?date_from[]=`) — is answered with **422** and a validation error naming the parameter. Rejecting is deliberate: the raw string previously reached the driver and PostgreSQL failed the request with `invalid input syntax for type timestamp`, while parsing leniently would have been worse still — `Carbon::parse()` reads `x` as a military timezone and silently shifts the window by hours, and treats a mistyped year as a valid date matching nothing, both answering `200` over a wrong result set. The web UI uses date inputs and always sends `Y-m-d`, so only hand-written API calls are affected.

Values are interpreted in the application timezone, the same one `created_at` is written in. A local time that does not exist because of a DST jump is rejected as invalid.

### Case-insensitivity and non-ASCII terms

Matching is case-insensitive, and that holds for non-ASCII text (Cyrillic, accented Latin, …) on **PostgreSQL and MySQL** — searching `Москва`, `москва`, or `МОСКВА` returns the same rows.

On **SQLite** it does not: SQLite's `lower()` only folds ASCII unless built with ICU, so a non-ASCII term matches only in the exact case it was stored. SQLite is supported for development and the test suite; this limitation does not affect a PostgreSQL or MySQL deployment. (SQLite also stores JSON with `\uXXXX` escapes, so non-ASCII values inside JSON columns are not substring-matchable there at all.)

### Why Deep search is separate

`LIKE '%term%'` cannot use a btree index, so `payload` performs a sequential scan over the payload columns. On a package designed to capture **100 %** of traffic, those columns are the bulk of the data, and an unbounded deep search on a large table is slow and competes with the ongoing insert load. Keeping it behind its own input means the cost is only paid when someone deliberately asks for it.

**The most effective way to speed it up is to narrow the date range** — `created_at` is indexed, so bounding the window bounds the scan.

### What Deep search cannot find

Some values are genuinely absent from storage; this is not a search bug:

- **Truncated payloads.** Bodies larger than `max_body_size` (default `10000` **bytes**) are cut and marked `...[truncated]`; anything past the cut is unsearchable. An oversized `body_params` is replaced wholesale with `{"_truncated": true, "_original_size": N}`. The budget is in bytes because that is what storage, the column limit and the queue payload cost; the cut itself lands on a character boundary, never mid-character. Multi-byte text therefore keeps proportionally fewer characters — a Cyrillic body about half as many as a Latin one — because it occupies proportionally more disk. Raise the limit if you want more of such bodies kept.

  Set `max_body_size` (or `outgoing.max_body_size`) to `0` to disable truncation and store bodies whole — mind the column ceiling below before you do. Only a numeric env value is honoured this way: an empty or non-numeric `TRACING_MAX_BODY_SIZE` (e.g. a blank `TRACING_MAX_BODY_SIZE=` in `.env`) falls back to the default of `10000` rather than casting to `0` and silently disabling truncation.

  On MySQL the payload columns are `text`, capped at **65535 bytes**, so a `max_body_size` near or above that will fail the write — and since a failed write is logged and swallowed, the whole trace record is lost, not just the body. Keep the limit comfortably under it, or widen the columns to `longtext` yourself; the package does not ship that migration, because altering these tables rebuilds them and blocks writes for as long as it takes.
- **Skipped response bodies.** With `store_response_body_only_json=true` (the default) a non-JSON response body is never stored.
- **Masked values.** Fields listed in `masked_body_params` / `masked_request_headers` / `masked_response_body_params` are stored as `[REDACTED]`. Adding, say, `phone` to a masked list makes that value permanently unsearchable — by design.

### Privacy

Deep search turns the UI into a full-text grep over every captured payload, which at 100 % capture means all PII flowing through your application. It exposes nothing that a detail page does not already show, so it is governed by the same `viewTracing` gate — but it makes that data far easier to mine in bulk. Keep the gate tight in production (see [UI authorization](#ui-authorization)).

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
