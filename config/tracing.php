<?php

/**
 * Casts an env() value to int, falling back to $default when the value is not
 * numeric. Needed because `env('X')` returns '' (not the default) for a key
 * that is present in .env but empty — e.g. `TRACING_MAX_BODY_SIZE=` — and a
 * bare `(int) ''` silently produces 0, which for max_body_size means "do not
 * truncate" rather than "use the default". An explicit `0` is numeric and is
 * kept as-is: that is the documented opt-out from truncation.
 */
$tracingIntEnv = static fn (mixed $value, int $default): int => is_numeric($value) ? (int) $value : $default;

return [
    'enabled' => (bool) env('TRACING_ENABLED', true),

    /*
     | 'database' — written synchronously in terminate()
     | 'queue'    — written through a queued job
     */
    'driver' => env('TRACING_DRIVER', 'database'),

    'queue' => env('TRACING_QUEUE', null),
    'queue_connection' => env('TRACING_QUEUE_CONNECTION', null),

    /*
     | Routes excluded from tracing.
     | Supports the * wildcard through Request::is().
     */
    'ignore_paths' => [
        'up',
        '_ignition/*',
        '_debugbar/*',
        'horizon',
        'horizon/*',
        'telescope',
        'telescope/*',
        'log-viewer',
        'log-viewer/*',
        'livewire*',
        'docs',
        'docs/*',
        'tracing',
        'tracing/*',
    ],

    /*
     | Route allowlist. Empty (the default) traces everything except
     | ignore_paths. Non-empty traces ONLY the routes it matches — ignore_paths
     | still applies and is subtracted from the allowlist.
     | Supports the * wildcard through Request::is().
     |
     | 'only_paths' => ['api/*'],
     */
    'only_paths' => [],

    /*
     | Application-defined tags on records (the counterpart of Telescope::tag()),
     | applied through D076\Tracing\Facades\Tracing::tag('...').
     |
     | in_logs=false (the default) keeps tags in HIDDEN Context, out of the
     | application's log entries; true puts them in visible Context, where
     | Laravel adds them to the context of every log line.
     */
    'tags' => [
        'in_logs' => (bool) env('TRACING_TAGS_IN_LOGS', false),
    ],

    /*
     | Tracing of outbound HTTP requests made through the Http facade.
     | Records live in tracing_outgoing_requests and link back to the inbound
     | request through trace_id.
     */
    'outgoing' => [
        'enabled' => (bool) env('TRACING_OUTGOING_ENABLED', true),
        'driver' => env('TRACING_OUTGOING_DRIVER', 'database'),
        'queue' => env('TRACING_OUTGOING_QUEUE', null),
        'queue_connection' => env('TRACING_OUTGOING_QUEUE_CONNECTION', null),
        'store_request_body' => (bool) env('TRACING_OUTGOING_STORE_REQUEST_BODY', true),
        'store_response_body' => (bool) env('TRACING_OUTGOING_STORE_RESPONSE_BODY', true),
        // In bytes, like the inbound max_body_size — see its note below.
        // 0 (or null) disables truncation. A non-numeric env value (e.g. an
        // empty TRACING_OUTGOING_MAX_BODY_SIZE=) falls back to the default
        // instead of silently casting to 0 — see $tracingIntEnv above.
        'max_body_size' => $tracingIntEnv(env('TRACING_OUTGOING_MAX_BODY_SIZE', 10000), 10000),
        'propagate_trace_id' => (bool) env('TRACING_OUTGOING_PROPAGATE_TRACE_ID', false),
        'masked_request_headers' => [
            'authorization',
            'x-api-key',
        ],
        'masked_body_params' => [
            'password',
            'secret',
            'token',
            'access_token',
            'refresh_token',
            'api_token',
            'api_key',
            'client_secret',
            'private_key',
        ],
        // Fields of the RESPONSE body returned by the external API, replaced
        // with '[REDACTED]'. JSON bodies only; an empty list disables masking
        // and the body is merely truncated.
        'masked_response_body_params' => [
            'password',
            'secret',
            'token',
            'access_token',
            'refresh_token',
            'api_token',
            'api_key',
            'client_secret',
            'private_key',
        ],
        'ignore_urls' => [],
        'retention_days' => (int) env('TRACING_OUTGOING_RETENTION_DAYS', 30),
    ],

    /*
     | Web interface for browsing traced records, served at /{ui.path}
     | (/tracing by default).
     |
     | Authorization: define the 'viewTracing' gate in AppServiceProvider.
     | Without one, access is allowed in the local environment only.
     */
    'ui' => [
        'enabled' => (bool) env('TRACING_UI_ENABLED', true),
        'path' => env('TRACING_UI_PATH', 'tracing'),
        // Applied to a visitor who has not picked one; their own choice wins
        // from then on. Values as below; an unknown one falls back to an
        // enabled theme rather than reaching the markup.
        'theme' => env('TRACING_UI_THEME', 'system'),
        // Offered in the switcher: 'system', 'light', 'dark', 'bimbo-pink'.
        // Empty offers all. 'system' drops out unless both 'light' and 'dark'
        // are enabled — it is a choice between them, not a palette.
        'enabled_themes' => env('TRACING_UI_ENABLED_THEMES', []),
        'middleware' => ['web'],
    ],

    /*
     | Rate limiting for the JSON API (/{ui.path}/api/*). Applies to the API
     | ONLY — the SPA shell and its assets are never throttled. Counted per
     | user (by morph type + id), or per IP for a guest.
     |
     | Overriding it from the application:
     |  - the numbers: the env vars below, or a published config;
     |  - off entirely: TRACING_RATE_LIMIT_ENABLED=false;
     |  - full control: RateLimiter::for('tracing-api', ...) in
     |    AppServiceProvider — the package never replaces a limiter that is
     |    already defined.
     */
    'rate_limit' => [
        'enabled' => (bool) env('TRACING_RATE_LIMIT_ENABLED', true),
        'max_attempts' => (int) env('TRACING_RATE_LIMIT_MAX_ATTEMPTS', 120),
        'decay_minutes' => (int) env('TRACING_RATE_LIMIT_DECAY_MINUTES', 1),
    ],

    /*
     | Request body fields replaced with '[REDACTED]' before storage.
     | Dot notation addresses nested keys: 'user.password'.
     | Matching is case-sensitive and by WHOLE key name, never by substring:
     | 'token' does not mask 'access_token' — list every name you want masked.
     */
    'masked_body_params' => [
        'password',
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

    /*
     | Request headers replaced with '[REDACTED]' before storage.
     | Header names are matched case-insensitively.
     */
    'masked_request_headers' => [
        'authorization',
        'cookie',
        'x-api-key',
        'x-csrf-token',
        'x-xsrf-token',
        'php-auth-pw',
    ],

    /*
     | Response headers replaced with '[REDACTED]'.
     */
    'masked_response_headers' => [
        'set-cookie',
    ],

    /*
     | RESPONSE body fields replaced with '[REDACTED]' before storage (JSON
     | only). Applies when store_response_body=true; dot notation supported.
     | An empty list disables masking — the body is still truncated.
     */
    'masked_response_body_params' => [
        'password',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'api_key',
        'client_secret',
        'private_key',
    ],

    /*
     | Maximum request/response body size, in BYTES — that is what storage, the
     | column limit and the queue payload are denominated in. A body over the
     | limit is cut on a character boundary and marked '...[truncated]';
     | oversized body_params are replaced with a truncation summary instead.
     |
     | Multi-byte text costs proportionally more of this budget (a Cyrillic
     | character is 2 bytes, an emoji 4) because it costs proportionally more
     | disk. Raise the limit if you want more of such bodies kept.
     |
     | 0 (or null) disables truncation — the body is stored whole.
     |
     | On MySQL the payload columns are `text`, capped at 65535 bytes.
     |
     | A non-numeric env value (e.g. an empty TRACING_MAX_BODY_SIZE=) falls
     | back to the default instead of silently casting to 0 and disabling
     | truncation — see $tracingIntEnv above.
     */
    'max_body_size' => $tracingIntEnv(env('TRACING_MAX_BODY_SIZE', 10000), 10000),

    /*
     | Whether to store the response body.
     */
    'store_response_body' => (bool) env('TRACING_STORE_RESPONSE_BODY', true),

    'store_response_body_only_json' => (bool) env('TRACING_STORE_RESPONSE_BODY_ONLY_JSON', true),

    /*
     | Custom DB connection for the tracing tables.
     | null uses the application's default connection.
     */
    'connection' => env('TRACING_DB_CONNECTION', null),

    /*
     | How long records are kept, in days, for the model:prune command.
     | 0 or null disables automatic pruning.
     |
     | php artisan model:prune --model="D076\Tracing\Models\TracingRequest"
     */
    'retention_days' => (int) env('TRACING_RETENTION_DAYS', 30),
];
