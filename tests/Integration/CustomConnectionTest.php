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
