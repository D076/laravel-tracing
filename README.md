# d076/laravel-tracing

Composer-пакет для трейсинга входящих и исходящих HTTP-запросов в Laravel с трассировкой через `X-Trace-Id`.
Каждый входящий запрос получает уникальный UUID7; все исходящие запросы (через фасад `Http`) привязываются к нему через `trace_id`.

## Структура пакета

```
src/
├── Context/
│   ├── TracingContext.php              # Singleton с состоянием текущего входящего запроса
│   └── TraceId.php                    # Singleton для X-Trace-Id
├── Http/
│   ├── Controllers/
│   │   ├── TracingUiController.php    # SPA-шелл + раздача статики из resources/dist/
│   │   └── TracingApiController.php   # JSON API для UI
│   ├── Middleware/
│   │   └── TracingAuthMiddleware.php  # Проверяет gate viewTracing
│   └── routes.php                     # Маршруты UI и API
├── Jobs/
│   ├── PersistTracingRecord.php       # Queue job — входящие запросы
│   └── PersistOutgoingRecord.php      # Queue job — исходящие запросы
├── Middleware/
│   ├── TraceIdMiddleware.php          # Генерирует X-Trace-Id, добавляет в response
│   ├── TracingMiddleware.php          # Захват входящего запроса/ответа
│   └── OutgoingTracingMiddleware.php  # Guzzle middleware для Http фасада
├── Models/
│   ├── TracingRequest.php             # Входящие запросы
│   └── OutgoingRequest.php            # Исходящие запросы
├── Providers/
│   └── TracingServiceProvider.php
└── Services/
    ├── TracingService.php             # Persistence входящих запросов
    └── OutgoingTracingService.php     # Persistence исходящих запросов
config/
└── tracing.php
database/
└── migrations/
    ├── ..._create_tracing_requests_table.php
    └── ..._create_tracing_outgoing_requests_table.php
resources/                             # Vue SPA (см. resources/README.md)
├── js/
├── css/
├── views/
└── dist/                              # Pre-built assets, коммитятся в репо
```

Неймспейс `D076\Tracing\` смотрит в `src/` через PSR-4 маппинг в `composer.json`:

```json
"D076\\Tracing\\": "src/"
```

## Компоненты

### `Context/TraceId` (singleton)

Единственный источник правды для trace ID текущего запроса. Доступен для инжекции в логгере, Sentry-контексте, сервисах.

```php
$traceId->get();    // возвращает текущий ID (генерирует UUID7 лениво)
$traceId->reset();  // сброс (защита для Octane)
```

### `Context/TracingContext` (singleton)

Value-объект с состоянием одного входящего запроса. Заполняется последовательно:

| Этап | Источник | Что заполняется |
|------|---------|----------------|
| `handle()` | `TracingMiddleware` | method, url, headers, body, ip, user_agent |
| exception | `respondUsing` hook | exception |
| `terminate()` | `TracingMiddleware` | route_name, route_path, duration_ms |

### `Middleware/TraceIdMiddleware`

Генерирует UUID7, устанавливает в `TraceId` синглтон, добавляет `X-Trace-Id` в response headers. Работает независимо от `TRACING_ENABLED`.

### `Middleware/TracingMiddleware`

Захватывает данные входящего запроса в `TracingContext`. После отправки ответа (`terminate`) дополняет контекст роутом и длительностью, пишет запись в БД через `TracingService`.

### `Middleware/OutgoingTracingMiddleware`

Guzzle handler-stack middleware, регистрируемый через `Http::globalMiddleware()`. Оборачивает каждый вызов фасада `Http`, фиксирует URL, статус, заголовки, тела и длительность. Читает тела запроса/ответа через seekable stream с rewind — оригинальный запрос не повреждается.

Привязывает запись к входящему запросу через `TraceId::get()` → поле `trace_id`. Работает из контроллеров, jobs и CLI (в последних случаях `trace_id` отражает произвольный UUID7 текущего процесса, а не incoming request).

При включённом `propagate_trace_id` добавляет заголовок `X-Trace-Id` к исходящему запросу — полезно для распределённой трассировки.

### `Services/TracingService` / `OutgoingTracingService`

Строят payload, применяют маскировку заголовков и усечение тела, пишут синхронно (`database`) или диспатчат job (`queue`).

### `Providers/TracingServiceProvider`

Регистрирует синглтоны, подключает конфиг и миграции, добавляет `TraceIdMiddleware` и `TracingMiddleware` первыми в глобальный HTTP-стек, регистрирует `respondUsing` hook для захвата исключений, регистрирует `OutgoingTracingMiddleware` через `Http::globalMiddleware()`, поднимает UI.

## Жизненный цикл входящего запроса

```
Запрос
  ↓
TraceIdMiddleware::handle()
  → reset TraceId
  → генерирует UUID7
  ↓
TracingMiddleware::handle()
  → reset TracingContext
  → наполняет контекст данными запроса
  ↓
[ роутинг, контроллер ]
  ↓
  ← при exception:
       respondUsing hook → TracingContext::exception = $e
       (срабатывает для ВСЕХ исключений, включая 404/403/429)
  ↓
TraceIdMiddleware  ← добавляет X-Trace-Id в response headers
  ↓
response->send()   ← клиент получает ответ
  ↓
TracingMiddleware::terminate()
  → дополняет контекст (route, duration)
  → TracingService::persist() → INSERT в tracing_requests
```

## Жизненный цикл исходящего запроса

```
Http::get('https://...')
  ↓
OutgoingTracingMiddleware.__invoke()  ← outermost в Guzzle HandlerStack
  → читает trace_id из TraceId singleton
  → записывает start = microtime(true)
  → опционально добавляет X-Trace-Id в заголовки
  ↓
[ buildBeforeSendingHandler → buildRecorderHandler → buildStubHandler → transport ]
  ↓
  ← .then(success):
       читает тело response (rewind после чтения)
       OutgoingTracingService::persist()
       → INSERT в tracing_outgoing_requests
  ← .then(failure / TransferException):
       записывает exception_class, exception_message
       если RequestException с ответом — записывает response_status
       OutgoingTracingService::persist()
```

## Что логируется

### `tracing_requests` (входящие)

| Поле | Описание |
|------|---------|
| `id` | X-Trace-Id (UUID7) — первичный ключ |
| `method` | HTTP метод |
| `url` | Полный URL запроса |
| `route_name` | Имя роута Laravel |
| `route_path` | URI-паттерн (`/api/users/{id}`), `null` для 404 |
| `request_headers` | Заголовки запроса (чувствительные — `[REDACTED]`) |
| `query_params` | Query string параметры |
| `body_params` | Тело запроса (POST/PUT/PATCH) |
| `response_status` | HTTP статус ответа |
| `response_headers` | Заголовки ответа |
| `response_body` | Тело ответа (опционально, см. конфиг) |
| `exception` | jsonb `{class, message, file, line}` — при наличии исключения |
| `authenticatable_id` | ID аутентифицированного пользователя |
| `authenticatable_type` | Morph-тип пользователя |
| `duration_ms` | Время обработки запроса в миллисекундах |
| `ip_address` | IP клиента (IPv4/IPv6) |
| `user_agent` | User-Agent |

### `tracing_outgoing_requests` (исходящие)

| Поле | Описание |
|------|---------|
| `id` | UUID7 — первичный ключ |
| `trace_id` | Soft-ref на `tracing_requests.id` (nullable — CLI/jobs) |
| `method` | HTTP метод |
| `url` | Полный URL |
| `request_headers` | Заголовки (чувствительные — `[REDACTED]`) |
| `request_body` | Тело запроса (опционально) |
| `response_status` | HTTP статус, `null` при connection error |
| `response_headers` | Заголовки ответа |
| `response_body` | Тело ответа (опционально) |
| `exception_class` | FQCN исключения (ConnectException, TransferException и др.) |
| `exception_message` | Сообщение |
| `duration_ms` | Время запроса в миллисекундах |

## Совместимость

| | PostgreSQL | MySQL | SQLite |
|---|---|---|---|
| Миграции (`jsonb`) | ✅ native | ✅ → `json` | ✅ → `text` |
| Поиск по заголовкам | ✅ | ✅ | ✅ |
| Все остальные запросы | ✅ | ✅ | ✅ |

Минимальная версия: **PHP 8.4**, **Laravel 11 / 12 / 13**.

## Установка

```bash
composer require d076/laravel-tracing
php artisan migrate
```

Провайдер регистрируется автоматически через Laravel Package Auto-Discovery. При отключённом auto-discovery добавить вручную в `bootstrap/providers.php`:

```php
D076\Tracing\Providers\TracingServiceProvider::class,
```

`TracingServiceProvider::boot()` автоматически добавляет middleware в глобальный стек и регистрирует Guzzle middleware — изменений в `bootstrap/app.php` не требуется.

### Публикация конфига (опционально)

```bash
php artisan vendor:publish --tag=tracing-config
```

## Конфигурация

### Входящие запросы

| Переменная | Дефолт | Описание |
|-----------|--------|---------|
| `TRACING_ENABLED` | `true` | Включить/выключить запись в БД (`X-Trace-Id` работает всегда) |
| `TRACING_DRIVER` | `database` | `database` (sync) или `queue` (async) |
| `TRACING_QUEUE` | `null` | Имя очереди для async-режима |
| `TRACING_QUEUE_CONNECTION` | `null` | Connection очереди |
| `TRACING_MAX_BODY_SIZE` | `10000` | Макс. размер тела в символах |
| `TRACING_STORE_RESPONSE_BODY` | `true` | Сохранять тело ответа |
| `TRACING_STORE_RESPONSE_BODY_ONLY_JSON` | `true` | Сохранять тело ответа только если это JSON |
| `TRACING_DB_CONNECTION` | `null` | DB connection (null = дефолтный) |
| `TRACING_RETENTION_DAYS` | `30` | Срок хранения записей в днях (0 = не удалять) |

### Исходящие запросы

| Переменная | Дефолт | Описание |
|-----------|--------|---------|
| `TRACING_OUTGOING_ENABLED` | `true` | Включить трейсинг исходящих |
| `TRACING_OUTGOING_DRIVER` | `database` | `database` или `queue` |
| `TRACING_OUTGOING_QUEUE` | `null` | Имя очереди |
| `TRACING_OUTGOING_QUEUE_CONNECTION` | `null` | Connection очереди |
| `TRACING_OUTGOING_STORE_REQUEST_BODY` | `true` | Сохранять тело запроса |
| `TRACING_OUTGOING_STORE_RESPONSE_BODY` | `true` | Сохранять тело ответа |
| `TRACING_OUTGOING_MAX_BODY_SIZE` | `10000` | Макс. размер тела в символах |
| `TRACING_OUTGOING_PROPAGATE_TRACE_ID` | `false` | Добавлять `X-Trace-Id` в исходящие заголовки |
| `TRACING_OUTGOING_RETENTION_DAYS` | `30` | Срок хранения (0 = не удалять) |

### Веб-интерфейс

| Переменная | Дефолт | Описание |
|-----------|--------|---------|
| `TRACING_UI_ENABLED` | `true` | Включить UI |
| `TRACING_UI_PATH` | `tracing` | Префикс URL (`/tracing`) |

### Rate limiting API

Троттлинг применяется **только** к JSON-API (`/{ui.path}/api/*`); SPA-оболочка и ассеты не ограничиваются, поэтому интерфейс всегда грузится. Лимит считается на пользователя (по полиморфному `тип:id`), для гостя — по IP.

| Переменная | Дефолт | Описание |
|-----------|--------|---------|
| `TRACING_RATE_LIMIT_ENABLED` | `true` | Включить троттлинг API |
| `TRACING_RATE_LIMIT_MAX_ATTEMPTS` | `120` | Запросов за окно |
| `TRACING_RATE_LIMIT_DECAY_MINUTES` | `1` | Длина окна в минутах |

Полный контроль — определите свой limiter в `AppServiceProvider::boot()` (пакет не перезапишет уже заданный `tracing-api`):

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('tracing-api', fn ($request) =>
    Limit::perMinute(300)->by($request->user()?->getMorphClass().':'.$request->user()?->getKey() ?? $request->ip())
);
```

### Исключение маршрутов (входящие)

`TRACING_ENABLED=false` отключает запись в БД, `X-Trace-Id` продолжает работать. Для исключения отдельных маршрутов — `ignore_paths` в конфиге (поддерживает wildcard `*`):

```php
'ignore_paths' => [
    'up',
    'horizon/*',
    'api/webhooks/*',
],
```

UI-путь (`tracing/*`) исключается автоматически в `TracingServiceProvider::boot()`.

### Исключение URL (исходящие)

```php
'outgoing' => [
    'ignore_urls' => [
        'https://internal-health-check/*',
        '*/metrics',
    ],
],
```

Паттерны проверяются через `fnmatch()` по полному URL.

### Маскировка заголовков и тела

Чувствительные значения заменяются на `[REDACTED]` до записи в БД.

**Заголовки** — настраивается отдельно для входящих и исходящих, регистронезависимо:

```php
'masked_request_headers' => ['authorization', 'cookie', 'x-api-key'],

'outgoing' => [
    'masked_request_headers' => ['authorization', 'x-api-key'],
],
```

**Тело запроса** — поддерживает dot-нотацию для вложенных ключей, сравнение регистрозависимо:

```php
// Входящие запросы (body_params — массив)
'masked_body_params' => [
    'password',           // $body['password']
    'password_confirmation',
    'current_password',
    'secret',
    'token',
    'user.password',      // $body['user']['password']
    'data.api_key',       // $body['data']['api_key']
],

// Исходящие запросы (только JSON-тела)
'outgoing' => [
    // тело запроса (request_body)
    'masked_body_params' => ['password', 'secret', 'token'],
    // тело ответа (response_body); пустой список — маскирование выключено
    'masked_response_body_params' => ['password', 'secret', 'token', 'access_token', 'refresh_token'],
],
```

**Тело ответа** (только JSON, при `store_response_body=true`) — маскируется до усечения, dot-нотация поддерживается:

```php
// Входящие ответы
'masked_response_body_params' => ['password', 'secret', 'token', 'access_token', 'refresh_token'],

// Исходящие ответы — в секции 'outgoing' (см. выше)
```

> **Важно:** `password` маскирует только верхний уровень. Для вложенного поля укажите полный путь: `user.password`. Для маршрутов с чувствительными телами (например, `POST /login`) также можно добавить маршрут в `ignore_paths`.

### Async-режим (queue)

```dotenv
TRACING_DRIVER=queue
TRACING_QUEUE=tracing

TRACING_OUTGOING_DRIVER=queue
TRACING_OUTGOING_QUEUE=tracing
```

Записи обрабатываются через Horizon без блокировки ответа клиенту.

### Автоочистка старых записей

Обе модели реализуют `MassPrunable`. Добавить в планировщик (`routes/console.php`):

```php
Schedule::command('model:prune', [
    '--model' => \D076\Tracing\Models\TracingRequest::class,
])->daily();

Schedule::command('model:prune', [
    '--model' => \D076\Tracing\Models\OutgoingRequest::class,
])->daily();
```

При `RETENTION_DAYS=0` prune-запрос возвращает 0 строк — случайного удаления всех записей не произойдёт.

## Авторизация UI

По умолчанию доступ к `/tracing` разрешён только в `local`-окружении. Переопределить gate в `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewTracing', function ($user): bool {
    return $user->isAdmin();
});
```

Gate определяется в `TracingServiceProvider` только если он ещё не зарегистрирован — `AppServiceProvider` загружается первым, поэтому переопределение через него безопасно.

## База данных

### `tracing_requests`
- `uuid` как первичный ключ (= X-Trace-Id)
- `jsonb` для заголовков, параметров и исключения
- Индекс на `created_at`
- `updated_at` отсутствует — записи иммутабельны

### `tracing_outgoing_requests`
- `uuid` как первичный ключ (UUID7)
- `trace_id` — индексированный soft-ref на `tracing_requests.id`, без FK-constraint (работает из jobs и CLI)
- `jsonb` для заголовков
- `updated_at` отсутствует

### Примеры запросов

```sql
-- Все 5xx за последние 24 часа
SELECT id, method, url, response_status, exception->>'class' AS exception_class, duration_ms
FROM tracing_requests
WHERE response_status >= 500
  AND created_at > NOW() - INTERVAL '24 hours'
ORDER BY created_at DESC;

-- Медленные маршруты
SELECT route_path, AVG(duration_ms), COUNT(*)
FROM tracing_requests
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY route_path
HAVING AVG(duration_ms) > 500
ORDER BY AVG(duration_ms) DESC;

-- Все исходящие запросы конкретного входящего
SELECT method, url, response_status, duration_ms
FROM tracing_outgoing_requests
WHERE trace_id = '01966b3c-...'
ORDER BY created_at;

-- Самые медленные внешние сервисы
SELECT
    regexp_replace(url, '^(https?://[^/]+).*', '\1') AS host,
    AVG(duration_ms)::int                             AS avg_ms,
    COUNT(*)                                          AS calls
FROM tracing_outgoing_requests
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY host
ORDER BY avg_ms DESC;
```
