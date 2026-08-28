<?php

use D076\Tracing\Models\TracingRequest;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Stands in for Illuminate\Http\Middleware\TrustProxies. What matters is not
 * what it does but *when*: a host application can only configure trusted
 * proxies from inside the global middleware stack, and the tracing middleware
 * is prepended to that stack, so it always runs first — before `$request->ip()`
 * knows anything about forwarded headers.
 */
final class TrustsTheProxy
{
    public function handle(Request $request, Closure $next)
    {
        Request::setTrustedProxies(['172.21.0.2', '10.0.0.5'], Request::HEADER_X_FORWARDED_FOR);

        return $next($request);
    }
}

beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.ignore_paths', []);

    Route::get('/probe', fn () => response('ok'));
});

afterEach(function () {
    // Trusted proxies are static on Symfony's Request and would leak into every
    // later test in the suite.
    Request::setTrustedProxies([], 0);
});

describe('behind a trusted proxy', function () {
    beforeEach(function () {
        $this->app->make(Kernel::class)->pushMiddleware(TrustsTheProxy::class);
    });

    it('records the client address, not the proxy that forwarded the request', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '172.21.0.2'])
            ->get('/probe', ['X-Forwarded-For' => '192.168.65.1'])
            ->assertOk();

        expect(TracingRequest::firstOrFail()->ip_address)->toBe('192.168.65.1');
    });

    it('records the original client through a chain of trusted proxies', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '172.21.0.2'])
            ->get('/probe', ['X-Forwarded-For' => '203.0.113.9, 10.0.0.5'])
            ->assertOk();

        expect(TracingRequest::firstOrFail()->ip_address)->toBe('203.0.113.9');
    });

    it('stops at the first hop it does not trust', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '172.21.0.2'])
            ->get('/probe', ['X-Forwarded-For' => '203.0.113.9, 198.51.100.4'])
            ->assertOk();

        expect(TracingRequest::firstOrFail()->ip_address)->toBe('198.51.100.4');
    });
});

describe('without a proxy', function () {
    it('records the connecting address', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get('/probe')
            ->assertOk();

        expect(TracingRequest::firstOrFail()->ip_address)->toBe('203.0.113.7');
    });

    it('ignores a forwarded header from an untrusted source', function () {
        // Nothing is trusted here, so X-Forwarded-For is attacker-controlled
        // input and must not reach the record.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get('/probe', ['X-Forwarded-For' => '198.51.100.1'])
            ->assertOk();

        expect(TracingRequest::firstOrFail()->ip_address)->toBe('203.0.113.7');
    });
});
