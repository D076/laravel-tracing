<?php

use D076\Tracing\Context\TracingContext;
use D076\Tracing\Middleware\TracingMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

function runTracingMiddleware(Request $request): TracingContext
{
    app(TracingMiddleware::class)->handle($request, fn () => new Response('ok'));

    return app(TracingContext::class);
}

describe('TracingMiddleware path exclusion', function () {
    beforeEach(function () {
        config()->set('tracing.enabled', true);
        config()->set('tracing.ignore_paths', ['admin', 'api/*']);
        config()->set('tracing.only_paths', []);
    });

    it('excludes an exactly-matched path', function () {
        $ctx = runTracingMiddleware(Request::create('/admin', 'GET'));

        expect($ctx->shouldRecord)->toBeFalse()
            ->and($ctx->traceId)->toBeNull();
    });

    it('excludes a wildcard-matched path', function () {
        $ctx = runTracingMiddleware(Request::create('/api/users', 'GET'));

        expect($ctx->shouldRecord)->toBeFalse();
    });

    it('records a non-matching path', function () {
        $ctx = runTracingMiddleware(Request::create('/home', 'GET'));

        expect($ctx->shouldRecord)->toBeTrue()
            ->and($ctx->traceId)->not->toBeNull();
    });

    it('does not record when tracing is disabled', function () {
        config()->set('tracing.enabled', false);

        $ctx = runTracingMiddleware(Request::create('/home', 'GET'));

        expect($ctx->shouldRecord)->toBeFalse();
    });
});

describe('TracingMiddleware only_paths allowlist', function () {
    beforeEach(function () {
        config()->set('tracing.enabled', true);
        config()->set('tracing.ignore_paths', []);
    });

    it('records every path when the allowlist is empty', function () {
        config()->set('tracing.only_paths', []);

        expect(runTracingMiddleware(Request::create('/anything', 'GET'))->shouldRecord)->toBeTrue();
    });

    it('records an exactly-matched path', function () {
        config()->set('tracing.only_paths', ['api/orders']);

        $ctx = runTracingMiddleware(Request::create('/api/orders', 'GET'));

        expect($ctx->shouldRecord)->toBeTrue()
            ->and($ctx->traceId)->not->toBeNull();
    });

    it('records a wildcard-matched path', function () {
        config()->set('tracing.only_paths', ['api/*']);

        expect(runTracingMiddleware(Request::create('/api/orders/5', 'GET'))->shouldRecord)->toBeTrue();
    });

    it('excludes a path outside the allowlist', function () {
        config()->set('tracing.only_paths', ['api/*']);

        $ctx = runTracingMiddleware(Request::create('/home', 'GET'));

        expect($ctx->shouldRecord)->toBeFalse()
            ->and($ctx->traceId)->toBeNull();
    });

    it('matches against any of several allowlist patterns', function () {
        config()->set('tracing.only_paths', ['api/*', 'webhooks/*']);

        expect(runTracingMiddleware(Request::create('/webhooks/stripe', 'GET'))->shouldRecord)->toBeTrue()
            ->and(runTracingMiddleware(Request::create('/api/users', 'GET'))->shouldRecord)->toBeTrue()
            ->and(runTracingMiddleware(Request::create('/internal/ping', 'GET'))->shouldRecord)->toBeFalse();
    });

    it('still applies ignore_paths inside the allowlist', function () {
        config()->set('tracing.only_paths', ['api/*']);
        config()->set('tracing.ignore_paths', ['api/health']);

        expect(runTracingMiddleware(Request::create('/api/health', 'GET'))->shouldRecord)->toBeFalse()
            ->and(runTracingMiddleware(Request::create('/api/orders', 'GET'))->shouldRecord)->toBeTrue();
    });

    it('does not record when tracing is disabled, whatever the allowlist says', function () {
        config()->set('tracing.enabled', false);
        config()->set('tracing.only_paths', ['api/*']);

        expect(runTracingMiddleware(Request::create('/api/orders', 'GET'))->shouldRecord)->toBeFalse();
    });

    it('treats a missing allowlist key as empty', function () {
        config()->offsetUnset('tracing.only_paths');

        expect(runTracingMiddleware(Request::create('/anything', 'GET'))->shouldRecord)->toBeTrue();
    });
});

describe('TracingMiddleware body capture', function () {
    beforeEach(function () {
        config()->set('tracing.enabled', true);
        config()->set('tracing.ignore_paths', []);
        config()->set('tracing.only_paths', []);
    });

    it('does not capture a body for GET requests', function () {
        $ctx = runTracingMiddleware(Request::create('/x', 'GET', ['q' => '1']));

        expect($ctx->bodyParams)->toBeNull();
    });

    it('does not capture a body for DELETE requests', function () {
        $ctx = runTracingMiddleware(Request::create('/x', 'DELETE'));

        expect($ctx->bodyParams)->toBeNull();
    });

    it('captures the body for POST requests', function () {
        $ctx = runTracingMiddleware(Request::create('/x', 'POST', ['name' => 'John']));

        expect($ctx->bodyParams)->toBe(['name' => 'John']);
    });

    it('excludes uploaded files but keeps fields for multipart requests', function () {
        $request = Request::create(
            '/upload',
            'POST',
            ['name' => 'John'],
            [],
            ['avatar' => UploadedFile::fake()->create('doc.pdf', 10)],
            ['CONTENT_TYPE' => 'multipart/form-data'],
        );

        $ctx = runTracingMiddleware($request);

        expect($ctx->bodyParams)->toBe(['name' => 'John']);
    });
});
