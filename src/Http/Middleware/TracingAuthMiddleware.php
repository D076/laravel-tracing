<?php

namespace D076\Tracing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class TracingAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::check('viewTracing'), 403, 'Forbidden.');

        return $next($request);
    }
}
