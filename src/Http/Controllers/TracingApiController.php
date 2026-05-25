<?php

namespace D076\Tracing\Http\Controllers;

use D076\Tracing\Models\TracingRequest;
use D076\Tracing\Models\OutgoingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class TracingApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TracingRequest::query();

        if ($raw = $request->query('status_group')) {
            $groups = is_array($raw) ? $raw : explode(',', $raw);
            $groups = array_filter($groups);
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

        if ($method = $request->query('method')) {
            $query->where('method', strtoupper($method));
        }

        if ($routePath = $request->query('route_path')) {
            $query->whereRaw('lower(route_path) like ?', ['%' . strtolower($routePath) . '%']);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        if ($request->boolean('has_exception')) {
            $query->whereNotNull('exception');
        }

        if ($search = $request->query('search')) {
            $isUuid = Str::isUuid($search);
            $query->where(function ($q) use ($search, $isUuid): void {
                if ($isUuid) {
                    $q->where('id', $search);
                }
                $term = strtolower($search);
                $jsonCast = $q->getConnection()->getDriverName() === 'pgsql'
                    ? 'request_headers::text'
                    : 'CAST(request_headers AS CHAR)';

                $q->orWhereRaw('lower(url) like ?', ['%' . $term . '%'])
                    ->orWhereRaw("lower({$jsonCast}) like ?", ['%' . $term . '%']);
            });
        }

        $sortable = ['created_at', 'duration_ms', 'response_status'];
        $sort = in_array($request->query('sort'), $sortable, true)
            ? $request->query('sort')
            : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $perPage = min(max((int) $request->query('per_page', 50), 10), 200);

        $paginator = $query
            ->select(['id', 'method', 'url', 'route_name', 'route_path', 'response_status', 'exception', 'duration_ms', 'ip_address', 'created_at'])
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->through(fn(TracingRequest $r) => [
                'id' => $r->id,
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

        if ($traceId = $request->query('trace_id')) {
            $query->where('trace_id', $traceId);
        }

        if ($raw = $request->query('status_group')) {
            $groups = array_filter(is_array($raw) ? $raw : explode(',', $raw));
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

        if ($method = $request->query('method')) {
            $query->where('method', strtoupper($method));
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        if ($request->boolean('has_exception')) {
            $query->whereNotNull('exception_class');
        }

        if ($search = $request->query('search')) {
            $isUuid = Str::isUuid($search);
            $query->where(function ($q) use ($search, $isUuid): void {
                if ($isUuid) {
                    $q->where('id', $search)->orWhere('trace_id', $search);
                }
                $q->orWhereRaw('lower(url) like ?', ['%' . strtolower($search) . '%']);
            });
        }

        $sortable = ['created_at', 'duration_ms', 'response_status'];
        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $request->query('per_page', 50), 10), 200);

        $paginator = $query
            ->select(['id', 'trace_id', 'method', 'url', 'response_status', 'exception_class', 'duration_ms', 'created_at'])
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->through(fn(OutgoingRequest $r) => [
                'id' => $r->id,
                'trace_id' => $r->trace_id,
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
}
