<?php

/*
 * Recorded parameters are compared with toEqual, not toBe: the JSON columns are
 * read back with their keys in whatever order the backend stores them — pgsql
 * `jsonb` and MySQL `json` both normalise it, SQLite keeps the written order —
 * and only the pairs are part of the contract, never their order.
 */

use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * The defaults shipped in config/tracing.php, exercised through the recording
 * pipeline rather than read back out of the config array — a list that contains
 * the right key but is consulted by a path that never runs would pass the
 * latter and still write the secret to disk.
 */
beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.ignore_paths', []);
    config()->set('tracing.store_response_body', true);
    config()->set('tracing.outgoing.enabled', true);
    config()->set('tracing.outgoing.driver', 'database');
    config()->set('tracing.outgoing.ignore_urls', []);
});

$secrets = [
    'password' => ['password', 'hunter2-pw'],
    'password_confirmation' => ['password_confirmation', 'hunter2-pwc'],
    'current_password' => ['current_password', 'hunter2-cpw'],
    'secret' => ['secret', 'hunter2-sec'],
    'token' => ['token', 'hunter2-tok'],
    'access_token' => ['access_token', 'hunter2-at'],
    'refresh_token' => ['refresh_token', 'hunter2-rt'],
    'api_token' => ['api_token', 'hunter2-apt'],
    'api_key' => ['api_key', 'hunter2-apk'],
    'client_secret' => ['client_secret', 'hunter2-cs'],
    'private_key' => ['private_key', 'hunter2-pk'],
];

$responseSecrets = array_diff_key($secrets, array_flip(['password_confirmation', 'current_password']));

describe('inbound request body', function () use ($secrets) {
    it('redacts a secret carried under its usual name', function (string $key, string $value) {
        Route::post('/oauth/token', fn () => response('ok'));

        $this->postJson('/oauth/token', ['client_id' => 'acme', $key => $value])->assertOk();

        expect(TracingRequest::firstOrFail()->body_params)
            ->toEqual(['client_id' => 'acme', $key => '[REDACTED]']);
    })->with($secrets);

    it('leaves an unlisted field alone', function () {
        Route::post('/oauth/token', fn () => response('ok'));

        $this->postJson('/oauth/token', ['email' => 'a@b.c'])->assertOk();

        expect(TracingRequest::firstOrFail()->body_params)->toEqual(['email' => 'a@b.c']);
    });
});

describe('inbound response body', function () use ($responseSecrets) {
    it('redacts a secret handed back to the caller', function (string $key, string $value) {
        Route::post('/oauth/token', fn () => response()->json([$key => $value, 'user' => 'john']));

        $this->postJson('/oauth/token', [])->assertOk();

        $body = TracingRequest::firstOrFail()->response_body;

        expect($body)->toContain('[REDACTED]')
            ->and($body)->not->toContain($value)
            ->and($body)->toContain('john');
    })->with($responseSecrets);
});

describe('outgoing request body', function () use ($responseSecrets) {
    it('redacts a secret sent to a third-party api', function (string $key, string $value) {
        Http::fake(['*' => Http::response('ok', 200)]);

        Http::post('https://api.example.com/oauth/token', ['client_id' => 'acme', $key => $value]);

        $body = OutgoingRequest::firstOrFail()->request_body;

        expect($body)->toContain('[REDACTED]')
            ->and($body)->not->toContain($value)
            ->and($body)->toContain('acme');
    })->with($responseSecrets);
});

describe('outgoing response body', function () use ($responseSecrets) {
    it('redacts a secret returned by a third-party api', function (string $key, string $value) {
        Http::fake(['*' => Http::response([$key => $value, 'user' => 'john'], 200)]);

        Http::get('https://api.example.com/oauth/token');

        $body = OutgoingRequest::firstOrFail()->response_body;

        expect($body)->toContain('[REDACTED]')
            ->and($body)->not->toContain($value)
            ->and($body)->toContain('john');
    })->with($responseSecrets);
});
