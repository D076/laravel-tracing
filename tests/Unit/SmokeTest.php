<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('boots the testbench app with the tracing provider', function () {
    expect(config('tracing'))->toBeArray()
        ->and(config('tracing.enabled'))->not->toBeNull();
});

it('runs package migrations against the in-memory database', function () {
    expect(Schema::hasTable('tracing_requests'))->toBeTrue()
        ->and(Schema::hasTable('tracing_outgoing_requests'))->toBeTrue();
});
