<?php

use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class)->group('cross-db');

beforeEach(function () {
    Gate::define('viewTracing', fn ($user = null) => true);
});

it('finds an incoming record by a term inside the request_headers JSON', function () {
    TracingRequest::create([
        'method' => 'GET',
        'url' => '/alpha',
        'response_status' => 200,
        'request_headers' => ['x-correlation-id' => ['needle-zzz-42']],
    ]);
    TracingRequest::create([
        'method' => 'GET',
        'url' => '/beta',
        'response_status' => 200,
        'request_headers' => ['x-other' => ['unrelated']],
    ]);

    $response = $this->getJson('/tracing/api/requests?search=needle-zzz-42')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toBe('/alpha');
});

it('finds an incoming record by a url substring', function () {
    TracingRequest::create(['method' => 'GET', 'url' => '/users/profile', 'response_status' => 200]);
    TracingRequest::create(['method' => 'GET', 'url' => '/orders', 'response_status' => 200]);

    $response = $this->getJson('/tracing/api/requests?search=profile')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toBe('/users/profile');
});

it('filters incoming records by route_path case-insensitively', function () {
    TracingRequest::create(['method' => 'GET', 'url' => '/x', 'response_status' => 200, 'route_path' => 'api/Users/{id}']);
    TracingRequest::create(['method' => 'GET', 'url' => '/y', 'response_status' => 200, 'route_path' => 'api/orders']);

    $response = $this->getJson('/tracing/api/requests?route_path=users')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toBe('/x');
});

it('filters incoming records by has_exception over the jsonb column', function () {
    TracingRequest::create([
        'method' => 'GET',
        'url' => '/boom',
        'response_status' => 500,
        'exception' => ['class' => 'RuntimeException', 'message' => 'boom', 'file' => 'a.php', 'line' => 1],
    ]);
    TracingRequest::create(['method' => 'GET', 'url' => '/ok', 'response_status' => 200]);

    $response = $this->getJson('/tracing/api/requests?has_exception=1')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toBe('/boom');
});

it('finds an outgoing record by a url substring', function () {
    OutgoingRequest::create(['method' => 'GET', 'url' => 'https://api.stripe.com/charges']);
    OutgoingRequest::create(['method' => 'GET', 'url' => 'https://api.github.com/repos']);

    $response = $this->getJson('/tracing/api/outgoing?search=stripe')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toContain('stripe');
});
