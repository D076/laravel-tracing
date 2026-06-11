<?php

use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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

it('records a transfer error that carries no response (e.g. read timeout)', function () {
    // A responseless RequestException exercises the OTHER Laravel marshalling
    // branch (marshalRequestExceptionWithoutResponse), distinct from a ConnectException.
    Http::fake(function () {
        throw new RequestException(
            'cURL error 28: Operation timed out',
            new Psr7Request('GET', 'https://api.example.com/slow'),
        );
    });

    try {
        Http::get('https://api.example.com/slow');
    } catch (ConnectionException) {
        // Laravel marshals a responseless RequestException into a ConnectionException too.
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

    $outgoing = OutgoingRequest::first();
    // The incoming record stores the trace id as its primary `id`;
    // the outgoing record links back to it via `trace_id`.
    $incoming = TracingRequest::where('url', 'like', '%/calls-out')->first();

    expect($outgoing->trace_id)->toBe($traceId)
        ->and($incoming)->not->toBeNull()
        ->and($incoming->id)->toBe($traceId)
        ->and($outgoing->exception_class)->toBe(ConnectionException::class);
});
