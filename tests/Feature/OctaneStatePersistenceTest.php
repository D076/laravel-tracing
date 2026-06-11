<?php

use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Octane keeps one booted application — container, singletons and process
 * globals — alive across many requests inside a single worker. We don't need
 * Swoole/RoadRunner to exercise that contract: firing several requests within
 * one test method reuses the same Testbench application, so the package's
 * per-request singletons (TraceId, TracingContext) and any process-global
 * state are shared between the requests exactly as they would be under Octane.
 */
beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
});

it('issues a distinct trace_id for each request on a shared worker', function () {
    Route::get('/probe', fn () => response('ok'));

    // Two requests through the same booted app — the TraceId singleton survives
    // between them, so this fails if it is not reset at the start of each request.
    $idA = $this->get('/probe')->headers->get('X-Trace-Id');
    $idB = $this->get('/probe')->headers->get('X-Trace-Id');

    expect($idA)->not->toBeNull()
        ->and($idB)->not->toBeNull()
        ->and($idA)->not->toBe($idB);

    // For incoming requests the record's id IS the trace id (the X-Trace-Id value).
    $stored = TracingRequest::orderBy('created_at')->pluck('id');
    expect($stored)->toHaveCount(2)
        ->and($stored->contains($idA))->toBeTrue()
        ->and($stored->contains($idB))->toBeTrue();
});

it('measures duration per request, not from a process-global start', function () {
    Route::get('/probe', fn () => response('ok'));

    // Request A, then idle "worker" time, then request B. The old implementation
    // timed from the immutable LARAVEL_START constant, which is frozen for the
    // life of the worker, so request B would swallow the idle gap below. The
    // per-request timestamp must keep B's duration close to its own handler time.
    $this->get('/probe')->assertOk();
    usleep(400_000); // 400ms of inter-request idle time
    $this->get('/probe')->assertOk();

    $durations = TracingRequest::orderBy('id')->pluck('duration_ms');
    expect($durations)->toHaveCount(2)
        ->and($durations[1])->not->toBeNull()
        ->and($durations[1])->toBeLessThan(250); // nowhere near the 400ms gap
});

it('does not leak request body or query state into the next request', function () {
    config()->set('tracing.masked_body_params', []);
    Route::post('/with-state', fn () => response('ok'));
    Route::get('/clean', fn () => response('ok'));

    // Request A carries a body and query string; request B is a bare GET. The
    // TracingContext singleton is shared, so B's row must show no trace of A
    // unless reset() runs at the start of every request.
    $this->post('/with-state?token=secret', ['foo' => 'bar'])->assertOk();
    $this->get('/clean')->assertOk();

    $clean = TracingRequest::where('url', 'like', '%/clean%')->firstOrFail();
    expect($clean->method)->toBe('GET')
        ->and($clean->body_params)->toBeNull()
        ->and($clean->query_params)->toBeNull();
});
