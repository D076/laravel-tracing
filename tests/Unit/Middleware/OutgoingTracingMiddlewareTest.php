<?php

use D076\Tracing\Middleware\OutgoingTracingMiddleware;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;

uses(RefreshDatabase::class);

function captureOutgoingRequest(RequestInterface $request): RequestInterface
{
    $captured = null;

    $handler = function (RequestInterface $req, array $options) use (&$captured) {
        $captured = $req;

        return new FulfilledPromise(new Response(200));
    };

    $fn = app(OutgoingTracingMiddleware::class)($handler);
    $fn($request, [])->wait();

    return $captured;
}

describe('OutgoingTracingMiddleware::isIgnored', function () {
    beforeEach(function () {
        $this->middleware = app(OutgoingTracingMiddleware::class);
        $this->isIgnored = fn (RequestInterface $req): bool => (new ReflectionMethod($this->middleware, 'isIgnored'))
            ->invoke($this->middleware, $req);

        config()->set('tracing.outgoing.ignore_urls', [
            '*://metrics.internal/*',
            'https://foo.test/health',
        ]);
    });

    it('ignores a url matching a wildcard pattern', function () {
        expect(($this->isIgnored)(new Request('GET', 'https://metrics.internal/push')))->toBeTrue();
    });

    it('ignores a url matching an exact pattern', function () {
        expect(($this->isIgnored)(new Request('GET', 'https://foo.test/health')))->toBeTrue();
    });

    it('does not ignore a non-matching url', function () {
        expect(($this->isIgnored)(new Request('GET', 'https://api.example.com/users')))->toBeFalse();
    });
});

describe('OutgoingTracingMiddleware trace-id propagation', function () {
    beforeEach(function () {
        config()->set('tracing.outgoing.enabled', true);
        config()->set('tracing.outgoing.ignore_urls', []);
    });

    it('adds the X-Trace-Id header when propagation is enabled', function () {
        config()->set('tracing.outgoing.propagate_trace_id', true);

        $captured = captureOutgoingRequest(new Request('GET', 'https://api.test/x'));

        expect($captured->hasHeader('X-Trace-Id'))->toBeTrue()
            ->and($captured->getHeaderLine('X-Trace-Id'))->not->toBe('');
    });

    it('does not add the X-Trace-Id header when propagation is disabled', function () {
        config()->set('tracing.outgoing.propagate_trace_id', false);

        $captured = captureOutgoingRequest(new Request('GET', 'https://api.test/x'));

        expect($captured->hasHeader('X-Trace-Id'))->toBeFalse();
    });
});
