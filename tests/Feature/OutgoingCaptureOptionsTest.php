<?php

use D076\Tracing\Models\OutgoingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('tracing.outgoing.enabled', true);
    config()->set('tracing.outgoing.driver', 'database');
    config()->set('tracing.outgoing.ignore_urls', []);
    config()->set('tracing.outgoing.store_request_body', true);
    config()->set('tracing.outgoing.store_response_body', true);
    config()->set('tracing.outgoing.max_body_size', 10000);
});

describe('outgoing body capture toggles', function () {
    it('stores neither body when both toggles are off', function () {
        config()->set('tracing.outgoing.store_request_body', false);
        config()->set('tracing.outgoing.store_response_body', false);
        Http::fake(['*' => Http::response(['out' => 'data'], 200)]);

        Http::post('https://api.test/thing', ['in' => 'data']);

        $record = OutgoingRequest::firstOrFail();

        expect($record->request_body)->toBeNull()
            ->and($record->response_body)->toBeNull()
            // The record itself is still written — only the payloads are omitted.
            ->and($record->response_status)->toBe(200)
            ->and($record->url)->toBe('https://api.test/thing');
    });

    it('stores only the request body when the response toggle is off', function () {
        config()->set('tracing.outgoing.store_response_body', false);
        Http::fake(['*' => Http::response(['out' => 'data'], 200)]);

        Http::post('https://api.test/thing', ['in' => 'data']);

        $record = OutgoingRequest::firstOrFail();

        expect($record->request_body)->toContain('"in":"data"')
            ->and($record->response_body)->toBeNull();
    });

    it('stores only the response body when the request toggle is off', function () {
        config()->set('tracing.outgoing.store_request_body', false);
        Http::fake(['*' => Http::response(['out' => 'data'], 200)]);

        Http::post('https://api.test/thing', ['in' => 'data']);

        $record = OutgoingRequest::firstOrFail();

        expect($record->request_body)->toBeNull()
            ->and($record->response_body)->toContain('"out":"data"');
    });

    it('leaves the request body null for a GET that carries none', function () {
        Http::fake(['*' => Http::response('pong', 200)]);

        Http::get('https://api.test/ping');

        expect(OutgoingRequest::firstOrFail()->request_body)->toBeNull();
    });
});

describe('outgoing header masking', function () {
    it('redacts the configured request headers end-to-end', function () {
        config()->set('tracing.outgoing.masked_request_headers', ['authorization', 'x-api-key']);
        Http::fake(['*' => Http::response('ok', 200)]);

        Http::withHeaders([
            'Authorization' => 'Bearer vendor-secret-token',
            'X-Api-Key' => 'vendor-key-123',
            'X-Request-Id' => 'keep-me',
        ])->get('https://api.test/thing');

        $headers = OutgoingRequest::firstOrFail()->request_headers;

        expect($headers['Authorization'])->toBe(['[REDACTED]'])
            ->and($headers['X-Api-Key'])->toBe(['[REDACTED]'])
            ->and($headers['X-Request-Id'])->toBe(['keep-me'])
            ->and(json_encode($headers))->not->toContain('vendor-secret-token');
    });

    it('matches masked header names case-insensitively', function () {
        config()->set('tracing.outgoing.masked_request_headers', ['AUTHORIZATION']);
        Http::fake(['*' => Http::response('ok', 200)]);

        Http::withHeaders(['authorization' => 'Bearer vendor-secret-token'])->get('https://api.test/thing');

        expect(json_encode(OutgoingRequest::firstOrFail()->request_headers))
            ->not->toContain('vendor-secret-token');
    });
});

describe('outgoing form-encoded bodies', function () {
    it('masks a form-encoded request body sent by the Http client', function () {
        config()->set('tracing.outgoing.masked_body_params', ['password']);
        Http::fake(['*' => Http::response('ok', 200)]);

        Http::asForm()->post('https://api.test/login', ['email' => 'a@b.c', 'password' => 'secret']);

        $body = OutgoingRequest::firstOrFail()->request_body;

        expect(urldecode($body))->toContain('password=[REDACTED]')
            ->and($body)->not->toContain('secret')
            ->and(urldecode($body))->toContain('email=a@b.c');
    });
});

describe('duration measurement', function () {
    it('records a duration for every outgoing call', function () {
        Http::fake(['*' => Http::response('ok', 200)]);

        Http::get('https://api.test/thing');

        expect(OutgoingRequest::firstOrFail()->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0);
    });

    it('keeps concurrent pooled requests apart instead of collapsing them', function () {
        // The listener pairs RequestSending with its terminal event by the PSR
        // request's object identity. A pool interleaves several in flight at
        // once, which is exactly what a shared or last-write-wins key would mix up.
        Http::fake([
            'https://a.test/*' => Http::response('a', 201),
            'https://b.test/*' => Http::response('b', 202),
            'https://c.test/*' => Http::response('c', 203),
        ]);

        Http::pool(fn ($pool) => [
            $pool->get('https://a.test/one'),
            $pool->get('https://b.test/two'),
            $pool->get('https://c.test/three'),
        ]);

        $byUrl = OutgoingRequest::all()->keyBy->url;

        expect(OutgoingRequest::count())->toBe(3)
            ->and($byUrl['https://a.test/one']->response_status)->toBe(201)
            ->and($byUrl['https://b.test/two']->response_status)->toBe(202)
            ->and($byUrl['https://c.test/three']->response_status)->toBe(203);
    });
});
