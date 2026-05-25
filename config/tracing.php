<?php

return [
    'enabled' => (bool) env('TRACING_ENABLED', true),

    /*
     | 'database' — синхронная запись в terminate()
     | 'queue'    — запись через очередь
     */
    'driver' => env('TRACING_DRIVER', 'database'),

    'queue' => env('TRACING_QUEUE', null),
    'queue_connection' => env('TRACING_QUEUE_CONNECTION', null),

    /*
     | Маршруты, исключённые из мониторинга.
     | Поддерживает wildcard * через Request::is().
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
        'admin',
        'admin/*',
        'docs',
        'docs/*',
        'tracing',
        'tracing/*',
    ],

    /*
     | Мониторинг исходящих HTTP-запросов через фасад Http.
     | Записи хранятся в таблице tracing_outgoing_requests.
     | Привязываются к входящему запросу через trace_id.
     */
    'outgoing' => [
        'enabled' => (bool) env('TRACING_OUTGOING_ENABLED', true),
        'driver' => env('TRACING_OUTGOING_DRIVER', 'database'),
        'queue' => env('TRACING_OUTGOING_QUEUE', null),
        'queue_connection' => env('TRACING_OUTGOING_QUEUE_CONNECTION', null),
        'store_request_body' => (bool) env('TRACING_OUTGOING_STORE_REQUEST_BODY', true),
        'store_response_body' => (bool) env('TRACING_OUTGOING_STORE_RESPONSE_BODY', true),
        'max_body_size' => (int) env('TRACING_OUTGOING_MAX_BODY_SIZE', 10000),
        'propagate_trace_id' => (bool) env('TRACING_OUTGOING_PROPAGATE_TRACE_ID', false),
        'masked_request_headers' => [
            'authorization',
            'x-api-key',
        ],
        'masked_body_params' => [
            'password',
            'secret',
            'token',
        ],
        'ignore_urls' => [],
        'retention_days' => (int) env('TRACING_OUTGOING_RETENTION_DAYS', 30),
    ],

    /*
     | Веб-интерфейс для просмотра записей трейсинга.
     | Доступен по адресу /{ui.path} (по умолчанию /tracing).
     |
     | Авторизация: зарегистрируй gate 'viewTracing' в AppServiceProvider.
     | По умолчанию доступ разрешён только в local-окружении.
     */
    'ui' => [
        'enabled' => (bool) env('TRACING_UI_ENABLED', true),
        'path' => env('TRACING_UI_PATH', 'tracing'),
        'middleware' => ['web'],
    ],

    /*
     | Поля тела запроса, которые заменяются на '[REDACTED]' перед сохранением.
     | Поддерживает dot-нотацию для вложенных ключей: 'user.password'.
     | Сравнение регистрозависимо.
     */
    'masked_body_params' => [
        'password',
        'password_confirmation',
        'current_password',
        'secret',
        'token',
        'private_key',
    ],

    /*
     | Заголовки запроса, которые заменяются на '[REDACTED]' перед сохранением.
     | Имена нечувствительны к регистру.
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
     | Заголовки ответа, которые заменяются на '[REDACTED]'.
     */
    'masked_response_headers' => [
        'set-cookie',
    ],

    /*
     | Максимальный размер тела запроса/ответа в символах.
     | Данные сверх лимита заменяются сводкой об усечении.
     */
    'max_body_size' => (int) env('TRACING_MAX_BODY_SIZE', 10000),

    /*
     | Сохранять ли тело ответа.
     */
    'store_response_body' => (bool) env('TRACING_STORE_RESPONSE_BODY', true),

    'store_response_body_only_json' => (bool) env('TRACING_STORE_RESPONSE_BODY_ONLY_JSON', true),

    /*
     | Кастомный DB connection для таблицы TRACING_requests.
     | null = использовать connection по умолчанию.
     */
    'connection' => env('TRACING_DB_CONNECTION', null),

    /*
     | Срок хранения записей в днях для команды model:prune.
     | 0 или null — автоочистка отключена.
     |
     | php artisan model:prune --model="D076\Tracing\Models\TracingRequest"
     */
    'retention_days' => (int) env('TRACING_RETENTION_DAYS', 30),
];
