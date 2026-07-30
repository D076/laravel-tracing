<?php

use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('writes incoming records to the custom DB connection', function () {
    Route::get('/probe', fn () => response()->json(['ok' => true]));

    $this->getJson('/probe')->assertOk();

    // Model reads from custom connection via getConnectionName()
    expect(TracingRequest::count())->toBe(1);

    // Migrations ran on custom connection — default sqlite has no tracing tables
    expect(Schema::connection('sqlite')->hasTable('tracing_requests'))->toBeFalse();
});

it('writes outgoing records to the custom DB connection', function () {
    Http::fake(['https://example.com' => Http::response(['ok' => true], 200)]);

    Route::get('/probe', function () {
        Http::get('https://example.com');
        return response()->json(['ok' => true]);
    });

    $this->getJson('/probe')->assertOk();

    expect(OutgoingRequest::count())->toBe(1);
    expect(Schema::connection('sqlite')->hasTable('tracing_outgoing_requests'))->toBeFalse();
});

it('UI API reads records from the custom DB connection', function () {
    Gate::define('viewTracing', fn ($user = null) => true);

    TracingRequest::create([
        'method' => 'GET',
        'url' => '/some-request',
        'response_status' => 200,
    ]);

    $this->getJson('/tracing/api/requests')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('builds the JSON-to-text cast from the tracing connection, not the app default', function () {
    // The reason this suite is worth keeping on every driver: when the app runs
    // on one backend and tracing on another, applySearch() must read the driver
    // off the TRACING connection. Reading it off the app default would emit
    // `col::text` at a backend that only understands CAST(col AS CHAR), or the
    // reverse — a 500 rather than a wrong answer.
    Gate::define('viewTracing', fn ($user = null) => true);

    TracingRequest::create([
        'method' => 'POST',
        'url' => '/api/orders',
        'response_status' => 201,
        'body_params' => ['customer' => ['phone' => '+79023396677']],
    ]);
    TracingRequest::create([
        'method' => 'POST',
        'url' => '/api/other',
        'response_status' => 201,
        'body_params' => ['customer' => ['phone' => '+70000000000']],
    ]);

    $response = $this->getJson('/tracing/api/requests?payload=' . urlencode('+79023396677'))->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.url'))->toBe('/api/orders');
});

it('isolates records between tests on the custom connection', function () {
    // The secondary connection is outside RefreshDatabase's transaction, so if
    // its cleanup ever regresses this test starts seeing the rows above.
    expect(TracingRequest::count())->toBe(0)
        ->and(OutgoingRequest::count())->toBe(0);
});
