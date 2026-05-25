# Monitoring UI — Frontend

Vue 3 SPA для просмотра записей мониторинга. Собирается в `dist/` и раздаётся напрямую из модуля через PHP-контроллер — без публикации в `public/` и без зависимости от основного Vite-проекта.

## Стек

| | |
|---|---|
| **Vue 3** | Composition API, `<script setup>` |
| **Vue Router 4** | `createWebHistory` — чистые URL без `#` |
| **Tailwind CSS 3** | JIT, bundled в dist (только используемые классы) |
| **Vite 6** | Сборка, отдельный `vite.config.js` внутри `resources/` |

## Структура

```
resources/
├── js/
│   ├── app.js                  # Точка входа: Vue app + Router
│   ├── App.vue                 # Root-компонент, хедер с навигацией
│   ├── api.js                  # fetch-обёртки для всех API-эндпоинтов
│   ├── utils.js                # Форматирование: duration, time, CSS-классы
│   ├── components/
│   │   ├── StatusBadge.vue     # Цветной badge HTTP-статуса (2xx/4xx/5xx)
│   │   ├── MethodBadge.vue     # Цветной badge метода (GET/POST/...)
│   │   └── JsonViewer.vue      # <pre> с форматированным JSON, max-h с прокруткой
│   └── pages/
│       ├── RequestsList.vue    # Список входящих запросов с фильтрами
│       ├── RequestDetail.vue   # Детальная входящего + секция Outgoing HTTP
│       ├── OutgoingList.vue    # Список исходящих запросов с фильтрами
│       └── OutgoingDetail.vue  # Детальная исходящего + ссылка на входящий
├── css/
│   └── app.css                 # @tailwind base/components/utilities
├── views/
│   └── index.blade.php         # SPA-шелл: <div id="app"> + window.__monitoring
├── dist/                       # Pre-built assets — коммитятся в репо
│   ├── app.js
│   └── app.css
├── .gitignore                  # node_modules/
├── package.json
├── vite.config.js
├── tailwind.config.js
└── postcss.config.js
```

## Разработка

```bash
cd app/Support/Monitoring/resources

npm install
npm run build      # разовая сборка → dist/
npm run dev        # watch-режим, пересобирает при изменениях
```

После сборки **закоммитить `dist/`** — потребители модуля не нуждаются в Node.

## Роутинг

```
/monitoring                → RequestsList
/monitoring/:id            → RequestDetail
/monitoring/outgoing       → OutgoingList
/monitoring/outgoing/:id   → OutgoingDetail
```

Базовый путь берётся из `window.__monitoring.basePath`, который Blade-шаблон заполняет из `config('tracing.ui.path')`. Это позволяет менять префикс через `MONITORING_UI_PATH` без пересборки фронта.

PHP-маршрут `{any?}` с catch-all отдаёт SPA-шелл для всех этих адресов — `createWebHistory` работает без дополнительной конфигурации nginx.

## API-клиент (`api.js`)

Все запросы идут на `window.__monitoring.apiBase` (заполняется Blade-шаблоном):

| Функция | Эндпоинт |
|---|---|
| `fetchRequests(params)` | `GET /monitoring/api/requests` |
| `fetchRequest(id)` | `GET /monitoring/api/requests/:id` |
| `fetchOutgoing(params)` | `GET /monitoring/api/outgoing` |
| `fetchOutgoingRequest(id)` | `GET /monitoring/api/outgoing/:id` |

Все функции возвращают Promise. При HTTP-ошибке бросают `Error` с сообщением из тела ответа или `HTTP {status}`.

Параметры фильтруются: `null`, `undefined`, `''` и `false` не попадают в query string.

## Утилиты (`utils.js`)

```js
formatDuration(ms)   // 45 → '45ms', 1200 → '1.20s', null → '—'
durationClass(ms)    // CSS-класс: зелёный/жёлтый/красный по порогам 500ms / 1000ms
formatTime(iso)      // абсолютное время через toLocaleString()
timeAgo(iso)         // '5s ago', '3m ago', '2h ago' — для таблицы
```

## Компоненты

### `StatusBadge`

```vue
<StatusBadge :status="200" />   <!-- зелёный -->
<StatusBadge :status="422" />   <!-- жёлтый -->
<StatusBadge :status="500" />   <!-- красный -->
```

Цвета: `2xx` → emerald, `3xx` → sky, `4xx` → amber, `5xx` → red.

### `MethodBadge`

```vue
<MethodBadge method="GET" />    <!-- синий -->
<MethodBadge method="DELETE" /> <!-- красный -->
```

### `JsonViewer`

```vue
<JsonViewer :data="record.request_headers" />
```

Принимает объект, массив или JSON-строку. Форматирует через `JSON.stringify(..., null, 2)`. При `null`/`undefined` показывает `—`. Ограничен `max-h-80` с прокруткой.

## Связь входящих и исходящих запросов

`RequestDetail.vue` загружает входящий запрос и все исходящие, сделанные в его рамках, параллельно:

```js
const [main, out] = await Promise.all([
    fetchRequest(id),
    fetchOutgoing({ trace_id: id, per_page: 100, sort: 'created_at', direction: 'asc' }),
])
```

Если исходящие запросы есть — показывается секция **Outgoing HTTP** перед Meta.

`OutgoingDetail.vue` отображает `trace_id` как кликабельную ссылку на родительский входящий запрос (`RouterLink to="/:trace_id"`).

## Как добавить новую страницу

1. Создать `resources/js/pages/MyPage.vue`
2. Добавить маршрут в `app.js`:
   ```js
   { path: '/my-page', component: MyPage }
   ```
3. При необходимости добавить ссылку в `App.vue`
4. Добавить API-функцию в `api.js`
5. Пересобрать: `npm run build` и закоммитить `dist/`

## Как раздаются assets

`TracingUiController::asset(string $file)` читает файл из `resources/dist/` с защитой от path traversal (`realpath` + проверка что путь начинается с `dist/`). Отвечает с `Cache-Control: public, max-age=31536000, immutable`.

Для production с высокой нагрузкой можно вынести раздачу на nginx, опубликовав `dist/` в `public/vendor/monitoring/` и сконфигурировав location-блок.
