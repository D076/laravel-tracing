<?php

namespace D076\Tracing\Http\Controllers;

use D076\Tracing\Models\TracingRequest;
use D076\Tracing\Models\OutgoingRequest;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class TracingApiController extends Controller
{
    /**
     * LIKE escape character. Not a backslash: pgsql and mysql spell a literal
     * backslash inside ESCAPE '...' differently, '!' is portable across all three
     * supported drivers.
     */
    private const LIKE_ESCAPE = '!';

    /**
     * The clause every LIKE built by {@see likeContains()} must carry — without
     * it the escapes it inserts are matched as literal '!' characters, silently.
     */
    private const LIKE_ESCAPE_SQL = " escape '" . self::LIKE_ESCAPE . "'";

    /** Accepted ?date_from / ?date_to formats. A bare date bounds the whole day. */
    private const DATE_FORMATS = ['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s'];

    public function index(Request $request): JsonResponse
    {
        $query = TracingRequest::query();

        if ($raw = $request->query('status_group')) {
            $groups = is_array($raw) ? $raw : explode(',', (string) $raw);
            $groups = array_filter($groups, 'is_string');
            if ($groups) {
                $query->where(function ($q) use ($groups): void {
                    foreach ($groups as $group) {
                        match ($group) {
                            '2xx' => $q->orWhereBetween('response_status', [200, 299]),
                            '3xx' => $q->orWhereBetween('response_status', [300, 399]),
                            '4xx' => $q->orWhereBetween('response_status', [400, 499]),
                            '5xx' => $q->orWhereBetween('response_status', [500, 599]),
                            default => null,
                        };
                    }
                });
            }
        }

        if (($method = $this->stringQuery($request, 'method')) !== null) {
            $query->where('method', strtoupper($method));
        }

        if (($routePath = $this->stringQuery($request, 'route_path')) !== null) {
            $query->whereRaw('lower(route_path) like ?' . self::LIKE_ESCAPE_SQL, [$this->likeContains($routePath)]);
        }

        $this->applyDateRange($query, $request);

        if ($request->boolean('has_exception')) {
            $query->whereNotNull('exception');
        }

        $this->applyTagFilter($query, $request);

        if (($search = $this->stringQuery($request, 'search')) !== null) {
            $this->applySearch(
                $query,
                $search,
                textColumns: ['url'],
                jsonColumns: ['request_headers', 'response_headers', 'tags'],
                uuidColumns: ['id'],
            );
        }

        // Глубокий поиск — отдельный параметр, потому что сканирует тела и является
        // заведомо дорогим. Надмножество обычного search: «не нашлось обычным — ищу глубже».
        if (($payload = $this->stringQuery($request, 'payload')) !== null) {
            $this->applySearch(
                $query,
                $payload,
                textColumns: ['url', 'response_body'],
                jsonColumns: ['request_headers', 'response_headers', 'query_params', 'body_params', 'exception', 'tags'],
                uuidColumns: ['id'],
            );
        }

        $sortable = ['created_at', 'duration_ms', 'response_status'];
        $sort = in_array($request->query('sort'), $sortable, true)
            ? $request->query('sort')
            : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $perPage = min(max((int) $request->query('per_page', 50), 10), 200);

        $paginator = $query
            ->select(['id', 'tags', 'method', 'url', 'route_name', 'route_path', 'response_status', 'exception', 'duration_ms', 'ip_address', 'created_at'])
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->through(fn(TracingRequest $r) => [
                'id' => $r->id,
                'tags' => $r->tags,
                'method' => $r->method,
                'url' => $r->url,
                'route_name' => $r->route_name,
                'route_path' => $r->route_path,
                'response_status' => $r->response_status,
                'has_exception' => $r->exception !== null,
                'exception_class' => $r->exception['class'] ?? null,
                'duration_ms' => $r->duration_ms,
                'ip_address' => $r->ip_address,
                'created_at' => $r->created_at->toIso8601String(),
            ])->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $record = TracingRequest::findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $record->id,
                'tags' => $record->tags,
                'method' => $record->method,
                'url' => $record->url,
                'route_name' => $record->route_name,
                'route_path' => $record->route_path,
                'request_headers' => $record->request_headers,
                'query_params' => $record->query_params,
                'body_params' => $record->body_params,
                'response_status' => $record->response_status,
                'response_headers' => $record->response_headers,
                'response_body' => $record->response_body,
                'exception' => $record->exception,
                'authenticatable_id' => $record->authenticatable_id,
                'authenticatable_type' => $record->authenticatable_type,
                'duration_ms' => $record->duration_ms,
                'ip_address' => $record->ip_address,
                'user_agent' => $record->user_agent,
                'created_at' => $record->created_at->toIso8601String(),
            ],
        ]);
    }

    public function outgoingIndex(Request $request): JsonResponse
    {
        $query = OutgoingRequest::query();

        if (($traceId = $this->stringQuery($request, 'trace_id')) !== null) {
            $query->where('trace_id', $traceId);
        }

        if ($raw = $request->query('status_group')) {
            $groups = array_filter(is_array($raw) ? $raw : explode(',', (string) $raw), 'is_string');
            if ($groups) {
                $query->where(function ($q) use ($groups): void {
                    foreach ($groups as $group) {
                        match ($group) {
                            '2xx' => $q->orWhereBetween('response_status', [200, 299]),
                            '3xx' => $q->orWhereBetween('response_status', [300, 399]),
                            '4xx' => $q->orWhereBetween('response_status', [400, 499]),
                            '5xx' => $q->orWhereBetween('response_status', [500, 599]),
                            default => null,
                        };
                    }
                });
            }
        }

        if (($method = $this->stringQuery($request, 'method')) !== null) {
            $query->where('method', strtoupper($method));
        }

        $this->applyDateRange($query, $request);

        if ($request->boolean('has_exception')) {
            $query->whereNotNull('exception_class');
        }

        $this->applyTagFilter($query, $request);

        if (($search = $this->stringQuery($request, 'search')) !== null) {
            $this->applySearch(
                $query,
                $search,
                textColumns: ['url'],
                jsonColumns: ['request_headers', 'response_headers', 'tags'],
                uuidColumns: ['id', 'trace_id'],
            );
        }

        // См. комментарий в index(): дорогой скан вынесен в отдельный параметр.
        if (($payload = $this->stringQuery($request, 'payload')) !== null) {
            $this->applySearch(
                $query,
                $payload,
                textColumns: ['url', 'request_body', 'response_body', 'exception_message'],
                jsonColumns: ['request_headers', 'response_headers', 'tags'],
                uuidColumns: ['id', 'trace_id'],
            );
        }

        $sortable = ['created_at', 'duration_ms', 'response_status'];
        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $request->query('per_page', 50), 10), 200);

        $paginator = $query
            ->select(['id', 'trace_id', 'tags', 'method', 'url', 'response_status', 'exception_class', 'duration_ms', 'created_at'])
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->through(fn(OutgoingRequest $r) => [
                'id' => $r->id,
                'trace_id' => $r->trace_id,
                'tags' => $r->tags,
                'method' => $r->method,
                'url' => $r->url,
                'response_status' => $r->response_status,
                'has_exception' => $r->exception_class !== null,
                'exception_class' => $r->exception_class,
                'duration_ms' => $r->duration_ms,
                'created_at' => $r->created_at->toIso8601String(),
            ])->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function outgoingShow(string $id): JsonResponse
    {
        $record = OutgoingRequest::findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $record->id,
                'trace_id' => $record->trace_id,
                'tags' => $record->tags,
                'method' => $record->method,
                'url' => $record->url,
                'request_headers' => $record->request_headers,
                'request_body' => $record->request_body,
                'response_status' => $record->response_status,
                'response_headers' => $record->response_headers,
                'response_body' => $record->response_body,
                'exception_class' => $record->exception_class,
                'exception_message' => $record->exception_message,
                'duration_ms' => $record->duration_ms,
                'created_at' => $record->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Точный фильтр по тегам (?tag=a или ?tag[]=a&tag[]=b или ?tag=a,b).
     * Несколько тегов — по AND. Использует whereJsonContains: pgsql @> (GIN),
     * mysql JSON_CONTAINS, sqlite json_each — единый код без ветвления.
     *
     * @param \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    private function applyTagFilter(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        $raw = $request->query('tag');

        if ($raw === null) {
            return;
        }

        $tags = is_array($raw) ? $raw : explode(',', (string) $raw);

        foreach ($tags as $tag) {
            // Вложенный массив (?tag[][]=x) отсекаем: (string) на нём выдал бы
            // warning и отфильтровал по литералу "Array".
            if (!is_string($tag)) {
                continue;
            }

            $tag = trim($tag);

            if ($tag !== '') {
                $query->whereJsonContains('tags', $tag);
            }
        }
    }

    /**
     * Строковый query-параметр или null.
     *
     * Нельзя писать `if ($x = $request->query('x'))`: строка '0' в PHP falsy, и
     * фильтр молча не применился бы (запрос вернул бы ВСЁ вместо совпавшего).
     * Массив (?x[]=a) тоже отсекается здесь — иначе приведение (string) роняло бы
     * запрос в 500.
     */
    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Регистронезависимый поиск по подстроке в наборе колонок (OR между ними).
     *
     * ВАЖНО (инвариант безопасности): имена колонок — внутренние константы вызывающего
     * кода и НИКОГДА не приходят из запроса; в raw-SQL интерполируются только они.
     * Поисковый терм всегда передаётся биндингом.
     *
     * @param \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @param list<string> $textColumns Текстовые колонки — сравниваются напрямую
     * @param list<string> $jsonColumns JSON-колонки — предварительно приводятся к тексту
     * @param list<string> $uuidColumns Колонки точного совпадения, если терм является UUID
     */
    private function applySearch(
        \Illuminate\Database\Eloquent\Builder $query,
        string $term,
        array $textColumns,
        array $jsonColumns,
        array $uuidColumns,
    ): void {
        $isUuid = Str::isUuid($term);
        // getDriverName() резолвится только на конкретном Connection, поэтому берём
        // его ЗДЕСЬ, а не внутри замыкания (ConnectionInterface метод не объявляет).
        /** @var \Illuminate\Database\Connection $conn */
        $conn = $query->getConnection();
        $driver = $conn->getDriverName();
        $needle = $this->likeContains($term);

        $query->where(function ($q) use ($isUuid, $term, $uuidColumns, $textColumns, $jsonColumns, $driver, $needle): void {
            if ($isUuid) {
                foreach ($uuidColumns as $column) {
                    $q->orWhere($column, $term);
                }
            }

            foreach ($textColumns as $column) {
                $q->orWhereRaw("lower({$column}) like ?" . self::LIKE_ESCAPE_SQL, [$needle]);
            }

            foreach ($jsonColumns as $column) {
                $q->orWhereRaw(
                    'lower(' . $this->jsonTextExpression($column, $driver) . ') like ?' . self::LIKE_ESCAPE_SQL,
                    [$needle],
                );
            }
        });
    }

    /**
     * A user term turned into a case-insensitive LIKE substring pattern.
     *
     * mb_strtolower is mandatory: strtolower() is byte-wise and leaves Cyrillic
     * alone, while SQL lower() on Postgres does fold it — the mismatch made
     * '%Москва%' compared against lower(col) never match.
     *
     * Wildcards are escaped so that a term is matched literally: unescaped, a
     * search for "%" matched every row and "a_b" matched "a.b".
     */
    private function likeContains(string $term): string
    {
        $e = self::LIKE_ESCAPE;

        return '%' . str_replace([$e, '%', '_'], [$e . $e, $e . '%', $e . '_'], mb_strtolower($term, 'UTF-8')) . '%';
    }

    /**
     * Inclusive created_at range from ?date_from / ?date_to.
     *
     * @param \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    private function applyDateRange(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        if (($from = $this->dateQuery($request, 'date_from')) !== null) {
            $query->where('created_at', '>=', $from);
        }

        if (($to = $this->dateQuery($request, 'date_to', endOfDay: true)) !== null) {
            $query->where('created_at', '<=', $to);
        }
    }

    /**
     * A parsed date query parameter, or null when absent.
     *
     * Rejected with 422 rather than ignored. The raw string used to reach the
     * driver and Postgres answered "invalid input syntax for type timestamp"
     * with HTTP 500; ignoring it instead would trade that for a worse failure —
     * a 200 over an unfiltered result set. Carbon::parse() alone is no fix
     * either: it reads "x" as a military timezone and shifts the window by
     * hours, and a mistyped year as a valid date that quietly matches nothing.
     * Hence an explicit format list, which is also what the UI sends.
     */
    private function dateQuery(Request $request, string $key, bool $endOfDay = false): ?Carbon
    {
        $raw = $request->query($key);

        if ($raw === null || $raw === '') {
            return null;
        }

        // An array reaches here as ?date_from[]=... — rejected rather than
        // dropped, or the endpoint would answer 200 over an unfiltered set.
        if (!is_string($raw)) {
            $this->rejectDate($key);
        }

        foreach (self::DATE_FORMATS as $format) {
            try {
                // The '!' prefix zeroes every field the format leaves
                // unspecified; without it they are taken from "now" and the same
                // query would mean something different every minute.
                $date = Carbon::rawCreateFromFormat('!' . $format, $raw);
            } catch (InvalidFormatException) {
                continue;
            }

            // createFromFormat accepts overflow ('2026-02-31' rolls into March),
            // so round-tripping is what actually validates the value.
            if ($date->format($format) !== $raw) {
                continue;
            }

            return $endOfDay && $format === 'Y-m-d' ? $date->endOfDay() : $date;
        }

        $this->rejectDate($key);
    }

    /** @return never */
    private function rejectDate(string $key): void
    {
        $formats = implode(', ', self::DATE_FORMATS);

        throw new HttpResponseException(response()->json([
            'message' => "Invalid {$key}: expected one of {$formats}.",
            'errors' => [$key => ["The {$key} field must match one of {$formats}."]],
        ], 422));
    }

    /**
     * SQL-выражение приведения JSON-колонки к тексту для подстрочного LIKE-поиска.
     */
    private function jsonTextExpression(string $column, string $driver): string
    {
        return $driver === 'pgsql' ? "{$column}::text" : "CAST({$column} AS CHAR)";
    }
}
