<?php

use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
});

it('stores a Windows-1251 incoming response body as valid UTF-8', function () {
    config()->set('tracing.store_response_body', true);
    config()->set('tracing.store_response_body_only_json', false);

    $cp1251 = mb_convert_encoding('Ответ склада', 'Windows-1251', 'UTF-8');
    Route::get('/legacy-html', fn () => response($cp1251, 200, [
        'Content-Type' => 'text/html; charset=windows-1251',
    ]));

    $this->get('/legacy-html')->assertOk();

    $record = TracingRequest::first();

    expect($record)->not->toBeNull()
        ->and(mb_check_encoding($record->response_body, 'UTF-8'))->toBeTrue()
        ->and($record->response_body)->toContain('Ответ склада');
});

it('still records an incoming request when a body param is not valid UTF-8', function () {
    $cp1251 = mb_convert_encoding('Москва', 'Windows-1251', 'UTF-8');
    Route::post('/legacy-form', fn () => response('ok'));

    $this->post('/legacy-form', ['city' => $cp1251])->assertOk();

    expect(TracingRequest::count())->toBe(1);

    $record = TracingRequest::first();

    // The record must survive; its JSON columns must be storable UTF-8.
    expect($record->body_params)->toHaveKey('city')
        ->and(mb_check_encoding(json_encode($record->body_params), 'UTF-8'))->toBeTrue();
});

it('transcodes Windows-1251 body params instead of storing U+FFFD', function () {
    // toUtf8() cannot help here: params arrive already parsed, so the legacy
    // bytes sit inside individual values. Before toUtf8Deep() the record was
    // kept but the text in it was replaced with U+FFFD by cleanForStorage().
    $cp1251 = mb_convert_encoding('Москва', 'Windows-1251', 'UTF-8');
    Route::post('/legacy-form', fn () => response('ok'));

    $this->call(
        'POST',
        '/legacy-form',
        ['city' => $cp1251, 'nested' => ['note' => $cp1251]],
        [],
        [],
        ['CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=windows-1251'],
    )->assertOk();

    $record = TracingRequest::first();

    expect($record->body_params['city'])->toBe('Москва')
        ->and($record->body_params['nested']['note'])->toBe('Москва')
        ->and($record->body_params['city'])->not->toContain("\u{FFFD}");
});

it('transcodes body params using the charset declared by the client', function () {
    // ISO-8859-5, to prove the declared charset is what drives the conversion
    // rather than some Cyrillic-specific special case.
    $iso = mb_convert_encoding('Приход товара', 'ISO-8859-5', 'UTF-8');
    Route::post('/legacy-form', fn () => response('ok'));

    $this->call(
        'POST',
        '/legacy-form',
        ['title' => $iso],
        [],
        [],
        ['CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=iso-8859-5'],
    )->assertOk();

    expect(TracingRequest::first()->body_params['title'])->toBe('Приход товара');
});

it('keeps the record when a client declares no charset, storing U+FFFD', function () {
    // The accepted compromise. Nothing is guessed, so undeclared legacy bytes
    // land as U+FFFD — visibly broken, and the record still survives. Query
    // parameters are the common case here: a GET carries no Content-Type at all.
    $cp1251 = mb_convert_encoding('Казань', 'Windows-1251', 'UTF-8');
    Route::get('/legacy-search', fn () => response('ok'));

    $this->get('/legacy-search?city=' . urlencode($cp1251), ['X-City' => $cp1251])->assertOk();

    $record = TracingRequest::first();

    expect($record)->not->toBeNull()
        ->and($record->query_params['city'])->toContain("\u{FFFD}")
        ->and($record->request_headers['x-city'][0])->toContain("\u{FFFD}")
        ->and(mb_check_encoding(json_encode($record->query_params), 'UTF-8'))->toBeTrue();
});

it('leaves a raw-byte parameter alone rather than inventing Cyrillic for it', function () {
    // A binary signature in a query parameter is a real legacy pattern. Detection
    // would "recognize" it as cp1251 and store convincing text that never existed.
    $signature = hex2bin('a1b2c3d4e5f60718293a4b5c6d7e8f90');
    Route::get('/legacy-signed', fn () => response('ok'));

    $this->get('/legacy-signed?sig=' . urlencode($signature))->assertOk();

    // Stored as U+FFFD by cleanForStorage: visibly broken, not plausibly wrong.
    expect(TracingRequest::first()->query_params['sig'])->toContain("\u{FFFD}");
});

it('stores a binary response body as a marker instead of pseudo-Cyrillic garbage', function () {
    config()->set('tracing.store_response_body', true);
    config()->set('tracing.store_response_body_only_json', false);

    $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\x10\x20\x30\x40";
    Route::get('/thumbnail', fn () => response($jpeg, 200, ['Content-Type' => 'image/jpeg']));

    $this->get('/thumbnail')->assertOk();

    expect(TracingRequest::first()->response_body)->toStartWith('[non-UTF-8 body, ');
});
