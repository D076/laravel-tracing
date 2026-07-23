<?php

use D076\Tracing\Models\OutgoingRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('cross-db');

/**
 * These tests only prove something on a strict-UTF-8 backend (Postgres).
 * On sqlite/mysql the driver stores arbitrary bytes, so the crash never happens.
 */
function persistBody(string $responseBody): void
{
    OutgoingRequest::create([
        'trace_id' => (string) Str::uuid7(),
        'method' => 'GET',
        'url' => 'http://example.test/points',
        'response_status' => 200,
        'response_body' => $responseBody,
    ]);
}

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('UTF-8 rejection is only observable on Postgres.');
    }
});

it('rejects a body whose multibyte character was split by byte-substr (the original bug)', function () {
    $utf8 = str_repeat('Москва', 50);            // 2 bytes per Cyrillic letter
    $broken = substr($utf8, 0, 15) . '...[truncated]';   // odd offset splits a char

    expect(mb_check_encoding($broken, 'UTF-8'))->toBeFalse();

    persistBody($broken);
})->throws(QueryException::class, 'invalid byte sequence for encoding "UTF8"');

it('persists the same body when truncated with mb_strcut (our fix)', function () {
    $utf8 = str_repeat('Москва', 50);
    $fixed = mb_strcut($utf8, 0, 15, 'UTF-8') . '...[truncated]';

    expect(mb_check_encoding($fixed, 'UTF-8'))->toBeTrue();

    persistBody($fixed);

    expect(OutgoingRequest::query()->count())->toBe(1);
});

it('rejects a valid Windows-1251 body stored as-is', function () {
    $cp1251 = mb_convert_encoding('Москва', 'Windows-1251', 'UTF-8');

    expect(mb_check_encoding($cp1251, 'UTF-8'))->toBeFalse();

    persistBody($cp1251);
})->throws(QueryException::class, 'invalid byte sequence for encoding "UTF8"');

it('persists a Windows-1251 response after the service normalizes it to UTF-8', function () {
    $service = new D076\Tracing\Services\OutgoingTracingService();
    $cp1251 = mb_convert_encoding(str_repeat('Москва ', 10), 'Windows-1251', 'UTF-8');

    // Full service chokepoint: transcode -> mask -> truncate.
    $normalized = (new ReflectionMethod($service, 'maskBody'))
        ->invoke($service, $cp1251, [], 'application/json; charset=windows-1251');

    expect(mb_check_encoding($normalized, 'UTF-8'))->toBeTrue();

    persistBody($normalized);

    expect(OutgoingRequest::query()->value('response_body'))->toContain('Москва');
});

it('persists a binary response as a marker instead of crashing', function () {
    $service = new D076\Tracing\Services\OutgoingTracingService();
    $binary = random_bytes(64) . "\x98\x98\x98";

    $normalized = (new ReflectionMethod($service, 'maskBody'))
        ->invoke($service, $binary, [], 'application/octet-stream');

    persistBody($normalized);

    expect(OutgoingRequest::query()->value('response_body'))->toStartWith('[non-UTF-8 body,');
});
