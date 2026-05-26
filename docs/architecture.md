# Architecture

## Package structure

```
src/
├── Context/
│   ├── TracingContext.php              # Singleton holding the current inbound request state
│   └── TraceId.php                     # Singleton for X-Trace-Id
├── Http/
│   ├── Controllers/
│   │   ├── TracingUiController.php    # SPA shell + serves static assets from resources/dist/
│   │   └── TracingApiController.php   # JSON API for the UI
│   ├── Middleware/
│   │   └── TracingAuthMiddleware.php  # Checks the viewTracing gate
│   └── routes.php                     # UI and API routes
├── Jobs/
│   ├── PersistTracingRecord.php       # Queue job — inbound requests
│   └── PersistOutgoingRecord.php      # Queue job — outbound requests
├── Middleware/
│   ├── TraceIdMiddleware.php          # Generates X-Trace-Id, adds it to the response
│   ├── TracingMiddleware.php          # Captures the inbound request/response
│   └── OutgoingTracingMiddleware.php  # Guzzle middleware for the Http facade
├── Models/
│   ├── TracingRequest.php             # Inbound requests
│   └── OutgoingRequest.php            # Outbound requests
├── Providers/
│   └── TracingServiceProvider.php
└── Services/
    ├── TracingService.php             # Persistence for inbound requests
    └── OutgoingTracingService.php     # Persistence for outbound requests
config/
└── tracing.php
database/
└── migrations/
    ├── ..._create_tracing_requests_table.php
    └── ..._create_tracing_outgoing_requests_table.php
resources/                             # Vue SPA (see resources/README.md)
├── js/
├── css/
├── views/
└── dist/                              # Pre-built assets, committed to the repo
```

The `D076\Tracing\` namespace maps to `src/` via PSR-4:

```json
"D076\\Tracing\\": "src/"
```

## Components

### `Context/TraceId` (singleton)

Source of truth for the current request's trace ID. Stores the value in `Illuminate\Support\Facades\Context` — thanks to this, the id is automatically inherited by queued jobs, broadcasted events, chains, and retries (see [Configuration → trace_id propagation to jobs](configuration.md#trace_id-propagation-to-jobs)).

```php
$traceId->get();    // returns the current id (reads from Context, or generates a UUID7)
$traceId->reset();  // resets the singleton cache (Context is left intact; clear it separately if needed)
```

### `Context/TracingContext` (singleton)

Value object holding the state of a single inbound request. Filled in sequentially:

| Stage | Source | Filled in |
|-------|--------|-----------|
| `handle()` | `TracingMiddleware` | method, url, headers, body, ip, user_agent |
| exception | `respondUsing` hook | exception |
| `terminate()` | `TracingMiddleware` | route_name, route_path, duration_ms |

### `Middleware/TraceIdMiddleware`

At the start of every request, calls `Context::forget('tracing.trace_id')` and `TraceId::reset()`, generates a UUID7, and adds `X-Trace-Id` to the response headers. Runs regardless of `TRACING_ENABLED`.

### `Middleware/TracingMiddleware`

Captures inbound request data into `TracingContext`. After the response is sent (`terminate`), augments the context with route info and duration, and writes the row to the database via `TracingService`.

### `Middleware/OutgoingTracingMiddleware`

Guzzle handler-stack middleware, registered via `Http::globalMiddleware()`. Wraps every call through the `Http` facade and captures URL, status, headers, bodies, and duration. Reads request/response bodies via a seekable stream with rewind — the original request stays intact.

Ties the record to the inbound request via `TraceId::get()` → the `trace_id` column. Works from controllers, jobs, and CLI.

When `propagate_trace_id=true`, adds an `X-Trace-Id` header to outbound requests — useful for distributed tracing.

### `Services/TracingService` / `OutgoingTracingService`

Build the payload, apply header and body masking (request and response), truncate, and either persist synchronously (`database`) or dispatch a job (`queue`).

### `Providers/TracingServiceProvider`

Registers singletons, wires up the config and migrations, prepends `TraceIdMiddleware` and `TracingMiddleware` to the global HTTP middleware stack, registers a `respondUsing` hook for exception capture, registers `OutgoingTracingMiddleware` via `Http::globalMiddleware()`, registers the `tracing-api` named rate limiter (unless the app has defined one), and boots the UI.

## Inbound request lifecycle

```
Request
  ↓
TraceIdMiddleware::handle()
  → Context::forget + TraceId::reset
  → generates UUID7, stores in Context
  ↓
TracingMiddleware::handle()
  → resets TracingContext
  → fills the context with request data
  ↓
[ routing, controller ]
  ↓
  ← on exception:
       respondUsing hook → TracingContext::exception = $e
       (fires for ALL exceptions, including 404/403/429)
  ↓
TraceIdMiddleware  ← adds X-Trace-Id to response headers
  ↓
response->send()   ← client receives the response
  ↓
TracingMiddleware::terminate()
  → augments the context (route, duration)
  → TracingService::persist() → INSERT into tracing_requests
```

## Outbound request lifecycle

```
Http::get('https://...')
  ↓
OutgoingTracingMiddleware.__invoke()  ← outermost in the Guzzle HandlerStack
  → reads trace_id via TraceId::get() (from Context, or generates one)
  → records start = microtime(true)
  → optionally adds X-Trace-Id to headers
  ↓
[ buildBeforeSendingHandler → buildRecorderHandler → buildStubHandler → transport ]
  ↓
  ← .then(success):
       reads the response body (rewinds afterwards)
       body masking (JSON), truncation
       OutgoingTracingService::persist()
       → INSERT into tracing_outgoing_requests
  ← .then(failure / TransferException):
       records exception_class, exception_message
       if a RequestException carries a response — records response_status
       OutgoingTracingService::persist()
```
