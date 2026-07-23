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
├── Listeners/
│   └── RecordOutgoingRequest.php      # Records outbound requests via Http client events
├── Middleware/
│   ├── TraceIdMiddleware.php          # Generates X-Trace-Id, adds it to the response
│   ├── TracingMiddleware.php          # Captures the inbound request/response
│   └── OutgoingTracingMiddleware.php  # Guzzle middleware — injects X-Trace-Id only
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

Guzzle handler-stack middleware, registered via `Http::globalMiddleware()`. Its primary job is request mutation: when `propagate_trace_id=true`, it adds an `X-Trace-Id` header to outbound requests (useful for distributed tracing). Mutating the outgoing request is only possible at the middleware level — events cannot do it.

Recording normally lives in `RecordOutgoingRequest` (see below), because a Guzzle middleware's `->then(onRejected)` only catches *rejected promises* and silently misses exceptions thrown synchronously inside the handler stack — e.g. some connection failures.

The one case the events miss is when the caller enables Guzzle's `http_errors => true` (non-default): a `4xx`/`5xx` then becomes a rejected promise that Laravel marshals without dispatching `ResponseReceived`/`ConnectionFailed`. Because this middleware sits *inside* Guzzle's `http_errors` middleware, it still sees the fulfilled response and records it as a narrow fallback. It records nothing in the default `http_errors => false` mode (events handle everything) and never for `2xx` or rejected promises — so there is no double-recording.

### `Listeners/RecordOutgoingRequest`

Records every outbound request by listening to the Laravel Http client events:

- `RequestSending` — stamps the start time and `TraceId::get()`, keyed by the PSR request object id.
- `ResponseReceived` — fires for **any** response (including 4xx/5xx); persists status, headers, bodies, and duration.
- `ConnectionFailed` — fires when there is no response at all (timeout, DNS failure, connection refused); persists the exception with a null status.

The framework guarantees one of `ResponseReceived` / `ConnectionFailed` fires for every request, so coverage no longer depends on Guzzle promise/handler-stack semantics. Duration is the delta between `RequestSending` and the terminal event, correlated by the PSR request object identity (stable across these events, even for concurrent `Http::pool()` requests).

Ties the record to the inbound request via `TraceId::get()` → the `trace_id` column. Works from controllers, jobs, and CLI. The listener is a **singleton** — otherwise its in-flight start-time map would not survive between event dispatches.

### `Services/TracingService` / `OutgoingTracingService`

Build the payload, normalize bodies to UTF-8 (`Support/BodyEncoding`), apply header and body masking (request and response), truncate (UTF-8-safe via `mb_strcut`), substitute any residual non-UTF-8 bytes in JSON columns (`cleanForStorage`), and either persist synchronously (`database`) or dispatch a job (`queue`). UTF-8 normalization guarantees a strict backend (PostgreSQL) never rejects the write over an invalid byte sequence.

### `Providers/TracingServiceProvider`

Registers singletons, wires up the config and migrations, prepends `TraceIdMiddleware` and `TracingMiddleware` to the global HTTP middleware stack, registers a `respondUsing` hook for exception capture, registers `OutgoingTracingMiddleware` via `Http::globalMiddleware()` and the `RecordOutgoingRequest` listener on the Http client events, registers the `tracing-api` named rate limiter (unless the app has defined one), and boots the UI.

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
OutgoingTracingMiddleware.__invoke()  ← Guzzle HandlerStack
  → if propagate_trace_id: adds X-Trace-Id header (request mutation only)
  ↓
RequestSending event
  → RecordOutgoingRequest: stamps start = microtime(true) + TraceId::get(),
    keyed by the PSR request object id
  ↓
[ transport: real HTTP, or Http::fake stub ]
  ↓
  ← ResponseReceived event (any status, incl. 4xx/5xx):
       reads request/response bodies (rewinds afterwards)
       body masking (JSON / form), truncation
       OutgoingTracingService::persist()
       → INSERT into tracing_outgoing_requests
  ← ConnectionFailed event (no response: timeout / DNS / refused):
       records exception_class, exception_message, null status
       OutgoingTracingService::persist()
```

Both terminal events compute duration from the matching `RequestSending` stamp and tie the row to the inbound request via `trace_id`.
