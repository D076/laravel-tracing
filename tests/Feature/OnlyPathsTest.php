<?php

use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.ignore_paths', []);
    config()->set('tracing.only_paths', []);
});

it('records only the allowlisted paths end-to-end', function () {
    config()->set('tracing.only_paths', ['api/*']);

    Route::get('/api/orders', fn () => response('ok'));
    Route::get('/home', fn () => response('ok'));

    $this->get('/home')->assertOk();
    $this->get('/api/orders')->assertOk();

    expect(TracingRequest::count())->toBe(1)
        ->and(TracingRequest::first()->url)->toContain('/api/orders');
});

it('subtracts ignore_paths from the allowlist', function () {
    config()->set('tracing.only_paths', ['api/*']);
    config()->set('tracing.ignore_paths', ['api/health']);

    Route::get('/api/health', fn () => response('ok'));
    Route::get('/api/orders', fn () => response('ok'));

    $this->get('/api/health')->assertOk();
    $this->get('/api/orders')->assertOk();

    expect(TracingRequest::count())->toBe(1)
        ->and(TracingRequest::first()->url)->toContain('/api/orders');
});

it('records everything when the allowlist is empty', function () {
    Route::get('/home', fn () => response('ok'));

    $this->get('/home')->assertOk();

    expect(TracingRequest::count())->toBe(1);
});

it('still returns the X-Trace-Id header outside the allowlist', function () {
    config()->set('tracing.only_paths', ['api/*']);

    Route::get('/home', fn () => response('ok'));

    $this->get('/home')->assertOk()->assertHeader('X-Trace-Id');

    expect(TracingRequest::count())->toBe(0);
});
