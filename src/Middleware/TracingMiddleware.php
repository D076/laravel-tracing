<?php

namespace D076\Tracing\Middleware;

use D076\Tracing\Context\TracingContext;
use D076\Tracing\Context\TraceId;
use D076\Tracing\Services\TracingService;
use D076\Tracing\Support\BodyEncoding;
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
        $this->context->reset();

        $this->context->startedAt = microtime(true);

        if (!config('tracing.enabled') || $this->isExcluded($request)) {
            $this->context->shouldRecord = false;

            return $next($request);
        }

        $this->context->traceId = $this->traceId->get();
        $this->context->method = $request->method();
        $this->context->url = $request->fullUrl();
        $this->context->ipAddress = $request->ip();
        // Parameters and headers arrive already parsed, so toUtf8() does not apply:
        // a legacy client sending charset=windows-1251 puts its bytes inside
        // individual values. Unnormalized they survive the write only as U+FFFD.
        $charset = BodyEncoding::charsetFromContentType($request->header('Content-Type'));
        $userAgent = $request->userAgent();
        $this->context->userAgent = is_string($userAgent)
            ? BodyEncoding::toUtf8Value($userAgent, $charset)
            : null;
        $this->context->queryParams = BodyEncoding::toUtf8Deep($request->query() ?: null, $charset);
        $this->context->bodyParams = BodyEncoding::toUtf8Deep($this->captureBody($request), $charset);
        $this->context->requestHeaders = BodyEncoding::toUtf8Deep(
            $this->service->maskHeaders(
                $request->headers->all(),
                config('tracing.masked_request_headers')
            ),
            $charset,
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
            (microtime(true) - ($this->context->startedAt ?? microtime(true))) * 1000
        );

        $this->service->persist($this->context, $response);
    }

    private function isExcluded(Request $request): bool
    {
        // A non-empty only_paths turns recording into an allowlist; ignore_paths
        // is still subtracted from it, so an ignored path stays ignored.
        $onlyPaths = (array) config('tracing.only_paths');

        if ($onlyPaths !== [] && !$this->matchesAny($request, $onlyPaths)) {
            return true;
        }

        return $this->matchesAny($request, (array) config('tracing.ignore_paths'));
    }

    /** @param array<int, string> $patterns */
    private function matchesAny(Request $request, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function captureBody(Request $request): ?array
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        // Uploaded files are dropped rather than serialized. No media-type check:
        // allFiles() is empty on anything that is not a file upload, so except()
        // degrades to all() on its own — and an upload arriving under a
        // Content-Type we did not anticipate is covered too.
        return $request->except(array_keys($request->allFiles())) ?: null;
    }
}
