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
