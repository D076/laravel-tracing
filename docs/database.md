# Database

## What is recorded

### `tracing_requests` (inbound)

| Column | Description |
|--------|-------------|
| `id` | X-Trace-Id (UUID7) — primary key |
| `method` | HTTP method |
| `url` | Full request URL |
| `route_name` | Laravel route name |
| `route_path` | URI pattern (`/api/users/{id}`), `null` for 404s |
| `request_headers` | Request headers (sensitive ones — `[REDACTED]`) |
| `query_params` | Query string parameters |
| `body_params` | Request body (POST/PUT/PATCH) |
| `response_status` | HTTP response status |
| `response_headers` | Response headers |
| `response_body` | Response body (optional, see config) |
| `exception` | jsonb `{class, message, file, line}` — present when an exception occurred |
| `authenticatable_id` | Authenticated user id |
| `authenticatable_type` | User morph type |
| `duration_ms` | Request handling time in milliseconds |
| `ip_address` | Client IP (IPv4/IPv6) |
| `user_agent` | User-Agent |

### `tracing_outgoing_requests` (outbound)

| Column | Description |
|--------|-------------|
| `id` | UUID7 — primary key |
| `trace_id` | Soft reference to `tracing_requests.id` (nullable — CLI/jobs) |
| `method` | HTTP method |
| `url` | Full URL |
| `request_headers` | Headers (sensitive ones — `[REDACTED]`) |
| `request_body` | Request body (optional) |
| `response_status` | HTTP status, `null` on connection errors |
| `response_headers` | Response headers |
| `response_body` | Response body (optional) |
| `exception_class` | Exception FQCN (ConnectException, TransferException, etc.) |
| `exception_message` | Message |
| `duration_ms` | Request duration in milliseconds |

## Schema

### `tracing_requests`
- `uuid` primary key (= X-Trace-Id)
- `jsonb` columns for headers, parameters, and the exception
- Index on `created_at`
- No `updated_at` column — rows are immutable

### `tracing_outgoing_requests`
- `uuid` primary key (UUID7)
- `trace_id` — indexed soft reference to `tracing_requests.id`, no FK constraint (works from jobs and CLI)
- `jsonb` for headers
- No `updated_at` column

## Driver compatibility

| | PostgreSQL | MySQL | SQLite |
|---|---|---|---|
| Migrations (`jsonb`) | ✅ native | ✅ → `json` | ✅ → `text` |
| Header search | ✅ | ✅ | ✅ |
| All other queries | ✅ | ✅ | ✅ |

## Example SQL queries

```sql
-- All 5xx responses in the last 24 hours
SELECT id, method, url, response_status, exception->>'class' AS exception_class, duration_ms
FROM tracing_requests
WHERE response_status >= 500
  AND created_at > NOW() - INTERVAL '24 hours'
ORDER BY created_at DESC;

-- Slow routes
SELECT route_path, AVG(duration_ms), COUNT(*)
FROM tracing_requests
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY route_path
HAVING AVG(duration_ms) > 500
ORDER BY AVG(duration_ms) DESC;

-- All outbound requests for a specific inbound one
SELECT method, url, response_status, duration_ms
FROM tracing_outgoing_requests
WHERE trace_id = '01966b3c-...'
ORDER BY created_at;

-- Slowest external services
SELECT
    regexp_replace(url, '^(https?://[^/]+).*', '\1') AS host,
    AVG(duration_ms)::int                             AS avg_ms,
    COUNT(*)                                          AS calls
FROM tracing_outgoing_requests
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY host
ORDER BY avg_ms DESC;
```
