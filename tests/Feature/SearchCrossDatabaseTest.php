<?php

use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('filters outgoing records by an exact tag over the jsonb column', function () {
    // Exercises whereJsonContains: pgsql `tags @> ?` (GIN), mysql JSON_CONTAINS, sqlite json_each.
    OutgoingRequest::create(['method' => 'GET', 'url' => 'https://a.test', 'tags' => ['team:billing']]);
    OutgoingRequest::create(['method' => 'GET', 'url' => 'https://b.test', 'tags' => ['team:search']]);

    $response = $this->getJson('/tracing/api/outgoing?tag=team:billing')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toBe('https://a.test');
});

it('deep-searches incoming payloads across the jsonb-to-text cast', function () {
    // Exercises the driver-specific cast branch: pgsql `col::text` vs CAST(col AS CHAR).
    TracingRequest::create([
        'method' => 'POST',
        'url' => '/api/orders',
        'response_status' => 201,
        'body_params' => ['customer' => ['phone' => '+79023396677']],
    ]);
    TracingRequest::create(['method' => 'POST', 'url' => '/api/other', 'response_status' => 201, 'body_params' => ['customer' => ['phone' => '+70000000000']]]);

    $response = $this->getJson('/tracing/api/requests?payload=' . urlencode('+79023396677'))->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toBe('/api/orders');
});

it('deep-searches non-ASCII payloads case-insensitively', function () {
    // Regression: PHP strtolower() is byte-wise and leaves Cyrillic untouched, while
    // SQL lower() on Postgres does lowercase it — so an uppercase term used to match
    // nothing at all. Skipped on SQLite, whose lower() is ASCII-only by design.
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite lower() is ASCII-only, so non-ASCII search is case-sensitive there.');
    }

    OutgoingRequest::create(['method' => 'POST', 'url' => 'https://a.test', 'request_body' => '{"city":"Москва"}']);
    OutgoingRequest::create(['method' => 'POST', 'url' => 'https://b.test', 'request_body' => '{"city":"Казань"}']);

    foreach (['Москва', 'москва', 'МОСКВА'] as $term) {
        $response = $this->getJson('/tracing/api/outgoing?payload=' . urlencode($term))->assertOk();

        expect($response->json('meta.total'))->toBe(1, "term: {$term}")
            ->and($response->json('data.0.url'))->toBe('https://a.test');
    }
});

it('finds non-ASCII values inside jsonb payloads', function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite stores JSON with \uXXXX escapes and lower() is ASCII-only.');
    }

    TracingRequest::create([
        'method' => 'POST',
        'url' => '/api/orders',
        'response_status' => 201,
        'body_params' => ['city' => 'Москва'],
    ]);
    TracingRequest::create(['method' => 'POST', 'url' => '/api/other', 'response_status' => 201, 'body_params' => ['city' => 'Казань']]);

    $response = $this->getJson('/tracing/api/requests?payload=' . urlencode('Москва'))->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toBe('/api/orders');
});

it('deep-searches outgoing bodies', function () {
    OutgoingRequest::create(['method' => 'POST', 'url' => 'https://a.test', 'request_body' => '{"phone":"+79023396677"}']);
    OutgoingRequest::create(['method' => 'POST', 'url' => 'https://b.test', 'request_body' => '{"phone":"+70000000000"}']);

    $response = $this->getJson('/tracing/api/outgoing?payload=' . urlencode('+79023396677'))->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toBe('https://a.test');
});

it('finds an incoming record by a tag substring in the standard search', function () {
    TracingRequest::create(['method' => 'GET', 'url' => '/alpha', 'response_status' => 200, 'tags' => ['env:staging']]);
    TracingRequest::create(['method' => 'GET', 'url' => '/beta', 'response_status' => 200, 'tags' => ['env:prod']]);

    $response = $this->getJson('/tracing/api/requests?search=staging')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toBe('/alpha');
});
