# Database

## What is recorded

### `tracing_requests` (inbound)

| Column | Description |
|--------|-------------|
| `id` | X-Trace-Id (UUID7) — primary key |
| `tags` | Application-defined tags, JSON array of strings (nullable) — see [Configuration → Tags](configuration.md#tags) |
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
| `tags` | Application-defined tags, JSON array of strings (nullable) — see [Configuration → Tags](configuration.md#tags) |
| `method` | HTTP method |
| `url` | Full URL, query string included |
| `request_headers` | Headers (sensitive ones — `[REDACTED]`) |
| `request_body` | Request body (optional), stored exactly as it went on the wire |
| `response_status` | HTTP status, `null` on connection errors |
| `response_headers` | Response headers |
| `response_body` | Response body (optional) |
| `exception_class` | Exception FQCN (ConnectException, TransferException, etc.) |
| `exception_message` | Message |
| `duration_ms` | Request duration in milliseconds |

Unlike the inbound table, this one has no `query_params` / `body_params` columns.
Query parameters live inside `url` and form fields inside `request_body`, and the
UI derives both when it reads a record: the query string is parsed out of the URL,
and a body sent as `application/x-www-form-urlencoded` is shown as fields rather
than as one long line. Parsing goes through PHP's own `parse_str`, so bracket
syntax (`filter[city]=msk`, `ids[]=1&ids[]=2`) renders the same way it does for an
inbound request — including `parse_str`'s habit of rewriting dots and spaces in
top-level keys to underscores. A JSON or multipart body keeps its raw form.

Deriving rather than storing means records written by earlier versions display the
same way, at the cost of not being able to filter on a single query parameter
server-side; `?search=`/`?payload=` still match against the URL and the raw body.

## Schema

### `tracing_requests`
- `uuid` primary key (= X-Trace-Id)
- `jsonb` columns for headers, parameters, the exception, and `tags`
- Index on `created_at`; **GIN index on `tags`** (PostgreSQL only — required for JSON containment lookups)
- No `updated_at` column — rows are immutable

### `tracing_outgoing_requests`
- `uuid` primary key (UUID7)
- `trace_id` — indexed soft reference to `tracing_requests.id`, no FK constraint (works from jobs and CLI)
- `jsonb` for headers and `tags`
- **GIN index on `tags`** (PostgreSQL only)
- No `updated_at` column

`tags` is added by a separate additive migration (`..._add_tags_to_tracing_tables.php`), so existing installations upgrade without touching already-applied migrations.

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
