<?php

use D076\Tracing\Middleware\TraceIdMiddleware;
use D076\Tracing\Middleware\TracingMiddleware;
use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('does not register the tracing middleware in the kernel when disabled', function () {
    // Прямая проверка мастер-гейта: при enabled=false boot() делает ранний return
    // ДО prependMiddleware, поэтому middleware физически нет в глобальном стеке.
    // Это отличает мастер-выключатель от старой внутренней проверки shouldRecord
    // в TracingMiddleware (которая срабатывала бы, даже будь middleware зарегистрирован).
    $kernel = $this->app->make(Kernel::class);

    expect($kernel->hasMiddleware(TraceIdMiddleware::class))->toBeFalse()
        ->and($kernel->hasMiddleware(TracingMiddleware::class))->toBeFalse();
});

it('does not record incoming requests when disabled', function () {
    Route::get('/probe', fn () => response('ok', 200));

    $this->get('/probe')->assertOk();

    expect(TracingRequest::count())->toBe(0);
});

it('does not emit the X-Trace-Id header when disabled', function () {
    Route::get('/probe', fn () => response('ok'));

    $response = $this->get('/probe');

    expect($response->headers->has('X-Trace-Id'))->toBeFalse();
});

it('does not leak trace_id into Context (and thus logs) when disabled', function () {
    Route::get('/probe', fn () => response(Context::has('tracing.trace_id') ? 'leaked' : 'clean'));

    $this->get('/probe')->assertSee('clean');
});

it('does not record outgoing requests when disabled', function () {
    Http::fake(['*' => Http::response('pong', 200)]);

    Http::get('https://api.example.com/ping');

    expect(OutgoingRequest::count())->toBe(0);
});

it('does not register the UI routes when disabled', function (string $path) {
    // bootUi() sits below the master gate, so the routes are never defined —
    // a 404 from "no such route", not a 403 from the gate.
    $this->get($path)->assertNotFound();
})->with([
    'spa shell' => ['/tracing'],
    'requests api' => ['/tracing/api/requests'],
    'outgoing api' => ['/tracing/api/outgoing'],
    'assets' => ['/tracing/assets/app.css'],
]);

it('still registers the migrations when disabled', function () {
    // Deliberately outside the gate: disabling tracing must not silently remove
    // the tables from `php artisan migrate`.
    expect(Schema::hasTable('tracing_requests'))->toBeTrue()
        ->and(Schema::hasTable('tracing_outgoing_requests'))->toBeTrue();
});
