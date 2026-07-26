<?php

use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::define('viewTracing', fn ($user = null) => true);
});

describe('date filters tolerate malformed input', function () {
    beforeEach(function () {
        TracingRequest::create(['method' => 'GET', 'url' => '/a', 'response_status' => 200]);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://a.test']);
    });

    it('rejects an unparseable date with 422 instead of answering 500', function (string $endpoint, string $param) {
        // The raw string used to reach the driver: pgsql answered "invalid input
        // syntax for type timestamp" and the endpoint returned HTTP 500.
        $this->getJson("{$endpoint}?{$param}=not-a-date")
            ->assertStatus(422)
            ->assertJsonValidationErrors([$param]);
    })->with([
        ['/tracing/api/requests', 'date_from'],
        ['/tracing/api/requests', 'date_to'],
        ['/tracing/api/outgoing', 'date_from'],
        ['/tracing/api/outgoing', 'date_to'],
    ]);

    it('rejects input Carbon::parse would silently accept as something else', function (string $value) {
        // 'x' is a military timezone (shifts the window by hours), 'now' and
        // '+1 day' are relative, and an overlong year quietly clamps — each of
        // which answered 200 with a wrong or empty result set.
        $this->getJson('/tracing/api/requests?date_from=' . urlencode($value))->assertStatus(422);
    })->with(['x', 'now', 'tomorrow', '+1 day', '999999999999-01-01', '0000-00-00', '@99999999999999']);

    it('rejects a date that looks valid but does not exist', function () {
        $this->getJson('/tracing/api/requests?date_from=2026-02-31')->assertStatus(422);
    });

    it('rejects an array date instead of silently dropping the filter', function () {
        // Otherwise the endpoint answers 200 over an unfiltered result set — the
        // exact failure the 422 on a malformed date exists to prevent.
        $this->getJson('/tracing/api/requests?date_from[]=2026-07-26')->assertStatus(422);
    });

    it('accepts the formats the UI actually sends', function (string $value) {
        $this->getJson('/tracing/api/requests?date_from=' . urlencode($value))->assertOk();
    })->with(['2026-01-01', '2026-01-01 10:00', '2026-01-01 10:00:00']);

    it('accepts date_to carrying an explicit time', function () {
        // ' 23:59:59' used to be appended blindly, producing '... 10:00:00 23:59:59'.
        $response = $this->getJson('/tracing/api/requests?date_to=' . urlencode(now()->addHour()->format('Y-m-d H:i:s')))
            ->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('honours an explicit time on date_to instead of widening it to the whole day', function () {
        $response = $this->getJson('/tracing/api/requests?date_to=' . urlencode(now()->subHour()->format('Y-m-d H:i')))
            ->assertOk();

        expect($response->json('meta.total'))->toBe(0);
    });

    it('still treats a bare date_to as inclusive of the whole day', function () {
        $response = $this->getJson('/tracing/api/requests?date_to=' . now()->format('Y-m-d'))->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('still bounds the range when the dates are valid', function () {
        $response = $this->getJson('/tracing/api/requests?date_from=' . now()->addDay()->format('Y-m-d'))->assertOk();

        expect($response->json('meta.total'))->toBe(0);
    });
});

describe('search terms are matched literally', function () {
    beforeEach(function () {
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://a.test/alpha']);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://b.test/beta']);
    });

    it('does not treat % as a wildcard matching every row', function (string $param) {
        $response = $this->getJson("/tracing/api/outgoing?{$param}=" . urlencode('%'))->assertOk();

        expect($response->json('meta.total'))->toBe(0);
    })->with(['search', 'payload']);

    it('does not treat _ as a single-character wildcard', function () {
        // 'a_test' must not match 'a.test'.
        $response = $this->getJson('/tracing/api/outgoing?search=' . urlencode('a_test'))->assertOk();

        expect($response->json('meta.total'))->toBe(0);
    });

    it('still finds a term that legitimately contains a wildcard character', function () {
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://c.test/discount?off=20%25']);

        $response = $this->getJson('/tracing/api/outgoing?search=' . urlencode('20%25'))->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toContain('discount');
    });

    it('escapes the escape character itself', function () {
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://d.test/wow!!']);

        $response = $this->getJson('/tracing/api/outgoing?search=' . urlencode('wow!!'))->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('does not treat % as a wildcard in the route_path filter', function () {
        TracingRequest::create(['method' => 'GET', 'url' => '/x', 'response_status' => 200, 'route_path' => 'api/users']);

        $response = $this->getJson('/tracing/api/requests?route_path=' . urlencode('%'))->assertOk();

        expect($response->json('meta.total'))->toBe(0);
    });
});
