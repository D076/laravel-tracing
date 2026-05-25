<?php

namespace D076\Tracing\Middleware;

use D076\Tracing\Context\TraceId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TraceIdMiddleware
{
    public function __construct(private readonly TraceId $traceId)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->traceId->reset();

        $id = $this->traceId->get();

        $response = $next($request);

        $response->headers->set('X-Trace-Id', $id);

        return $response;
    }
}
