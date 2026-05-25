<?php

use D076\Tracing\Models\OutgoingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('tracing.outgoing.enabled', true);
    config()->set('tracing.outgoing.driver', 'database');
    config()->set('tracing.outgoing.ignore_urls', []);
});

it('records an outgoing request end-to-end', function () {
    Http::fake(['*' => Http::response('pong', 200)]);

    Http::get('https://api.example.com/ping');

    expect(OutgoingRequest::count())->toBe(1);

    $record = OutgoingRequest::first();
    expect($record->method)->toBe('GET')
        ->and($record->url)->toContain('api.example.com/ping')
        ->and($record->response_status)->toBe(200);
});

it('masks sensitive body params in the outgoing request body', function () {
    config()->set('tracing.outgoing.masked_body_params', ['password']);
    Http::fake(['*' => Http::response('ok', 200)]);

    Http::post('https://api.example.com/login', ['email' => 'a@b.c', 'password' => 'secret']);

    $record = OutgoingRequest::first();
    expect($record->request_body)->toContain('[REDACTED]')
        ->and($record->request_body)->not->toContain('secret');
});

it('masks sensitive fields in the outgoing response body', function () {
    config()->set('tracing.outgoing.masked_response_body_params', ['access_token']);
    Http::fake(['*' => Http::response(['access_token' => 'super-secret-xyz', 'user' => 'john'], 200)]);

    Http::get('https://api.example.com/token');

    $record = OutgoingRequest::first();
    expect($record->response_body)->toContain('[REDACTED]')
        ->and($record->response_body)->not->toContain('super-secret-xyz')
        ->and($record->response_body)->toContain('john');
});

it('does not record an ignored url', function () {
    config()->set('tracing.outgoing.ignore_urls', ['*://metrics.internal/*']);
    Http::fake(['*' => Http::response('ok', 200)]);

    Http::get('https://metrics.internal/push');

    expect(OutgoingRequest::count())->toBe(0);
});

it('propagates X-Trace-Id to the outgoing request when enabled', function () {
    config()->set('tracing.outgoing.propagate_trace_id', true);
    Http::fake(['*' => Http::response('ok', 200)]);

    Http::get('https://api.example.com/ping');

    expect(OutgoingRequest::first()->request_headers)->toHaveKey('X-Trace-Id');
});

it('does not propagate X-Trace-Id when disabled', function () {
    config()->set('tracing.outgoing.propagate_trace_id', false);
    Http::fake(['*' => Http::response('ok', 200)]);

    Http::get('https://api.example.com/ping');

    expect(OutgoingRequest::first()->request_headers ?? [])->not->toHaveKey('X-Trace-Id');
});
