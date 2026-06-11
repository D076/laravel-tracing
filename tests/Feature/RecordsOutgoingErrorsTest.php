<?php

use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.outgoing.enabled', true);
    config()->set('tracing.outgoing.driver', 'database');
    config()->set('tracing.outgoing.ignore_urls', []);
});

it('records an outgoing request that returns 500', function () {
    Http::fake(['*' => Http::response('internal error', 500)]);

    Http::get('https://api.example.com/boom');

    expect(OutgoingRequest::count())->toBe(1);

    $record = OutgoingRequest::first();
    expect($record->method)->toBe('GET')
        ->and($record->url)->toContain('api.example.com/boom')
        ->and($record->response_status)->toBe(500)
        ->and($record->response_body)->toContain('internal error')
        ->and($record->exception_class)->toBeNull();
});

it('records an outgoing request that fails to connect (no response)', function () {
    Http::fake(function () {
        throw new ConnectException(
            'cURL error 7: Failed to connect',
            new Psr7Request('GET', 'https://api.example.com/down'),
        );
    });

    try {
        Http::get('https://api.example.com/down');
    } catch (ConnectionException) {
        // Laravel marshals the Guzzle ConnectException into a ConnectionException — expected.
    }

    expect(OutgoingRequest::count())->toBe(1);

    $record = OutgoingRequest::first();
    expect($record->method)->toBe('GET')
        ->and($record->url)->toContain('api.example.com/down')
        ->and($record->response_status)->toBeNull()
        ->and($record->exception_class)->toBe(ConnectionException::class)
        ->and($record->exception_message)->not->toBeEmpty();
});

it('records an outgoing request that times out (no response)', function () {
    // A real read/connect timeout surfaces as a Guzzle ConnectException (cURL 28),
    // which Laravel marshals into ConnectionException on every supported version.
    Http::fake(function () {
        throw new ConnectException(
            'cURL error 28: Operation timed out',
            new Psr7Request('GET', 'https://api.example.com/slow'),
        );
    });

    try {
        Http::timeout(1)->get('https://api.example.com/slow');
    } catch (ConnectionException) {
        // expected
    }

    expect(OutgoingRequest::count())->toBe(1);

    $record = OutgoingRequest::first();
    expect($record->url)->toContain('api.example.com/slow')
        ->and($record->response_status)->toBeNull()
        ->and($record->exception_class)->toBe(ConnectionException::class);
});

it('links a failed outgoing request to the incoming request via trace_id', function () {
    Http::fake(function () {
        throw new ConnectException(
            'cURL error 7: Failed to connect',
            new Psr7Request('GET', 'https://api.example.com/down'),
        );
    });

    Route::get('/calls-out', function () {
        try {
            Http::get('https://api.example.com/down');
        } catch (ConnectionException) {
            // swallow so the incoming request still completes
        }

        return 'done';
    });

    $response = $this->get('/calls-out')->assertOk();

    $traceId = $response->headers->get('X-Trace-Id');
    expect($traceId)->not->toBeEmpty();
    expect(OutgoingRequest::count())->toBe(1);

    $outgoing = OutgoingRequest::first();
    // The incoming record stores the trace id as its primary `id`;
    // the outgoing record links back to it via `trace_id`.
    $incoming = TracingRequest::where('url', 'like', '%/calls-out')->first();

    expect($outgoing->trace_id)->toBe($traceId)
        ->and($incoming)->not->toBeNull()
        ->and($incoming->id)->toBe($traceId)
        ->and($outgoing->exception_class)->toBe(ConnectionException::class);
});

it('records a 5xx even when the caller enables Guzzle http_errors', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    try {
        Http::withOptions(['http_errors' => true])->get('https://api.example.com/boom');
    } catch (\Throwable) {
        // Guzzle/Laravel throws on 5xx when http_errors is on — the response is still recorded.
    }

    expect(OutgoingRequest::count())->toBe(1);

    // Recorded as a normal response (via the middleware fallback), not as an exception.
    $record = OutgoingRequest::first();
    expect($record->response_status)->toBe(500)
        ->and($record->url)->toContain('api.example.com/boom')
        ->and($record->exception_class)->toBeNull();
});

it('records a connection failure once when http_errors is enabled', function () {
    Http::fake(function () {
        throw new ConnectException('refused', new Psr7Request('GET', 'https://api.example.com/down'));
    });

    try {
        Http::withOptions(['http_errors' => true])->get('https://api.example.com/down');
    } catch (ConnectionException) {
        // expected
    }

    // The middleware fallback only handles fulfilled responses; a rejection is left
    // to the ConnectionFailed event. Exactly one of them must record — never both.
    expect(OutgoingRequest::count())->toBe(1)
        ->and(OutgoingRequest::first()->response_status)->toBeNull()
        ->and(OutgoingRequest::first()->exception_class)->toBe(ConnectionException::class);
});

it('records a 4xx with http_errors enabled', function () {
    Http::fake(['*' => Http::response('nope', 404)]);

    try {
        Http::withOptions(['http_errors' => true])->get('https://api.example.com/missing');
    } catch (\Throwable) {
        // expected
    }

    expect(OutgoingRequest::count())->toBe(1)
        ->and(OutgoingRequest::first()->response_status)->toBe(404);
});

it('does not double-record a 2xx when http_errors is enabled', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    Http::withOptions(['http_errors' => true])->get('https://api.example.com/ok');

    // 2xx is fulfilled all the way out, so the ResponseReceived event records it.
    // The middleware fallback must NOT add a second row.
    expect(OutgoingRequest::count())->toBe(1)
        ->and(OutgoingRequest::first()->response_status)->toBe(200);
});

it('records every request in a sequence even when one of them fails', function () {
    Http::fake([
        'https://ok-1.test/*' => Http::response('one', 200),
        'https://down.test/*' => function () {
            throw new ConnectException('refused', new Psr7Request('GET', 'https://down.test/b'));
        },
        'https://ok-2.test/*' => Http::response('two', 200),
    ]);

    Http::get('https://ok-1.test/a');
    try {
        Http::get('https://down.test/b');
    } catch (ConnectionException) {
        // failure of one request must not suppress logging of the others
    }
    Http::get('https://ok-2.test/c');

    expect(OutgoingRequest::count())->toBe(3);

    $byUrl = OutgoingRequest::all()->keyBy(fn ($r) => $r->url);
    expect($byUrl['https://ok-1.test/a']->response_status)->toBe(200)
        ->and($byUrl['https://ok-1.test/a']->exception_class)->toBeNull()
        ->and($byUrl['https://down.test/b']->response_status)->toBeNull()
        ->and($byUrl['https://down.test/b']->exception_class)->toBe(ConnectionException::class)
        ->and($byUrl['https://ok-2.test/c']->response_status)->toBe(200)
        ->and($byUrl['https://ok-2.test/c']->exception_class)->toBeNull();
});

it('records both the prior and the failed request when the failure is left uncaught', function () {
    Http::fake([
        'https://ok.test/*' => Http::response('ok', 200),
        'https://down.test/*' => function () {
            throw new ConnectException('refused', new Psr7Request('GET', 'https://down.test/b'));
        },
    ]);

    Http::get('https://ok.test/a');

    // The terminal ConnectionFailed event fires before the exception propagates,
    // so even an unhandled failure is recorded — along with the request before it.
    expect(fn () => Http::get('https://down.test/b'))->toThrow(ConnectionException::class);

    expect(OutgoingRequest::count())->toBe(2)
        ->and(OutgoingRequest::whereNull('response_status')->whereNotNull('exception_class')->count())->toBe(1)
        ->and(OutgoingRequest::where('response_status', 200)->count())->toBe(1);
});
