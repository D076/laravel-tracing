<?php

use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.ignore_paths', ['ignored', 'ignored/*']);
});

it('records an incoming request end-to-end', function () {
    Route::get('/probe', fn () => response('ok', 200));

    $this->get('/probe')->assertOk();

    expect(TracingRequest::count())->toBe(1);

    $record = TracingRequest::first();
    expect($record->method)->toBe('GET')
        ->and($record->url)->toContain('/probe')
        ->and($record->response_status)->toBe(200);
});

it('masks sensitive body params when recording a POST', function () {
    config()->set('tracing.masked_body_params', ['password']);
    Route::post('/login', fn () => response('ok'));

    $this->post('/login', ['email' => 'a@b.c', 'password' => 'secret'])->assertOk();

    $record = TracingRequest::first();
    expect($record->method)->toBe('POST')
        ->and($record->body_params)->toBe(['email' => 'a@b.c', 'password' => '[REDACTED]']);
});

it('masks sensitive fields in the stored response body', function () {
    config()->set('tracing.store_response_body', true);
    config()->set('tracing.masked_response_body_params', ['access_token']);
    Route::post('/auth', fn () => response()->json(['access_token' => 'super-secret-xyz', 'user' => 'john']));

    $this->postJson('/auth', [])->assertOk();

    $record = TracingRequest::first();
    expect($record->response_body)->toContain('[REDACTED]')
        ->and($record->response_body)->not->toContain('super-secret-xyz')
        ->and($record->response_body)->toContain('john');
});

it('does not record an ignored path', function () {
    Route::get('/ignored', fn () => response('ok'));

    $this->get('/ignored')->assertOk();

    expect(TracingRequest::count())->toBe(0);
});

it('sets the X-Trace-Id response header', function () {
    Route::get('/probe', fn () => response('ok'));

    $this->get('/probe')->assertHeader('X-Trace-Id');
});
