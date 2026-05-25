<?php

namespace D076\Tracing\Middleware;

use D076\Tracing\Context\TracingContext;
use D076\Tracing\Context\TraceId;
use D076\Tracing\Services\TracingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TracingMiddleware
{
    public function __construct(
        private readonly TraceId $traceId,
        private readonly TracingContext $context,
        private readonly TracingService $service,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        $this->context->reset();

        if (!config('tracing.enabled', true) || $this->isExcluded($request)) {
            $this->context->shouldRecord = false;

            return $next($request);
        }

        $this->context->traceId = $this->traceId->get();
        $this->context->method = $request->method();
        $this->context->url = $request->fullUrl();
        $this->context->ipAddress = $request->ip();
        $this->context->userAgent = $request->userAgent();
        $this->context->queryParams = $request->query() ?: null;
        $this->context->bodyParams = $this->captureBody($request);
        $this->context->requestHeaders = $this->service->maskHeaders(
            $request->headers->all(),
            config('tracing.masked_request_headers', [])
        );

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (!$this->context->shouldRecord || $this->context->traceId === null) {
            return;
        }

        $route = $request->route();
        $this->context->routeName = $route?->getName();
        $this->context->routePath = $route?->uri();

        if ($user = $request->user()) {
            $this->context->authenticatableId = $user->getKey();
            $this->context->authenticatableType = $user->getMorphClass();
        }

        $this->context->durationMs = (int) round(
            (microtime(true) - LARAVEL_START) * 1000
        );

        $this->service->persist($this->context, $response);
    }

    private function isExcluded(Request $request): bool
    {
        foreach (config('tracing.ignore_paths', []) as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    private function captureBody(Request $request): ?array
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        if (str_contains($request->header('Content-Type', ''), 'multipart/form-data')) {
            return $request->except(array_keys($request->allFiles())) ?: null;
        }

        return $request->all() ?: null;
    }
}
