<?php

use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.ignore_paths', []);
    config()->set('tracing.store_response_body', true);
    config()->set('tracing.max_body_size', 10000);
});

describe('store_response_body', function () {
    it('stores the response body when enabled', function () {
        config()->set('tracing.store_response_body_only_json', false);
        Route::get('/probe', fn () => response('hello world'));

        $this->get('/probe')->assertOk();

        expect(TracingRequest::firstOrFail()->response_body)->toBe('hello world');
    });

    it('stores nothing when disabled', function () {
        config()->set('tracing.store_response_body', false);
        Route::get('/probe', fn () => response()->json(['a' => 1]));

        $this->get('/probe')->assertOk();

        expect(TracingRequest::firstOrFail()->response_body)->toBeNull();
    });

    it('stores nothing for an empty response body', function () {
        Route::get('/empty', fn () => response('', 204));

        $this->get('/empty')->assertNoContent();

        expect(TracingRequest::firstOrFail()->response_body)->toBeNull();
    });
});

describe('store_response_body_only_json', function () {
    it('keeps a JSON body when only_json is on', function () {
        config()->set('tracing.store_response_body_only_json', true);
        Route::get('/api/thing', fn () => response()->json(['name' => 'widget']));

        $this->getJson('/api/thing')->assertOk();

        expect(TracingRequest::firstOrFail()->response_body)->toContain('widget');
    });

    it('drops a non-JSON body when only_json is on', function (string $body, string $contentType) {
        // The default. Rendered HTML pages are the bulk of a monolith's traffic
        // and storing them would dwarf everything else in the table.
        config()->set('tracing.store_response_body_only_json', true);
        Route::get('/page', fn () => response($body, 200, ['Content-Type' => $contentType]));

        $this->get('/page')->assertOk();

        expect(TracingRequest::firstOrFail()->response_body)->toBeNull();
    })->with([
        'html' => ['<html><body>hi</body></html>', 'text/html'],
        'plain text' => ['just text', 'text/plain'],
        'malformed json' => ['{"broken": ', 'application/json'],
    ]);

    it('keeps a non-JSON body when only_json is off', function () {
        config()->set('tracing.store_response_body_only_json', false);
        Route::get('/page', fn () => response('<html>hi</html>', 200, ['Content-Type' => 'text/html']));

        $this->get('/page')->assertOk();

        expect(TracingRequest::firstOrFail()->response_body)->toBe('<html>hi</html>');
    });

    it('treats a bare JSON scalar as JSON', function () {
        // json_decode accepts scalars, so "only json" must not mean "only objects".
        config()->set('tracing.store_response_body_only_json', true);
        Route::get('/count', fn () => response('42', 200, ['Content-Type' => 'application/json']));

        $this->get('/count')->assertOk();

        expect(TracingRequest::firstOrFail()->response_body)->toBe('42');
    });
});

describe('streamed responses', function () {
    it('does not buffer a streamed response body', function () {
        config()->set('tracing.store_response_body_only_json', false);
        Route::get('/stream', fn () => response()->stream(function () {
            echo 'chunk-one';
        }, 200, ['Content-Type' => 'text/plain']));

        $this->get('/stream')->assertOk();

        // Reading getContent() on a StreamedResponse would consume the stream
        // and hand the client nothing.
        $record = TracingRequest::firstOrFail();

        expect($record->response_body)->toBeNull()
            ->and($record->response_status)->toBe(200);
    });
});

describe('response body truncation', function () {
    it('truncates an oversized body to the byte budget and marks it', function () {
        config()->set('tracing.store_response_body_only_json', false);
        config()->set('tracing.max_body_size', 100);
        Route::get('/big', fn () => response(str_repeat('x', 5000)));

        $this->get('/big')->assertOk();

        $body = TracingRequest::firstOrFail()->response_body;

        expect($body)->toEndWith('...[truncated]')
            ->and(strlen(str_replace('...[truncated]', '', $body)))->toBe(100);
    });

    it('keeps a body that fits whole', function () {
        config()->set('tracing.store_response_body_only_json', false);
        config()->set('tracing.max_body_size', 100);
        Route::get('/small', fn () => response('short'));

        $this->get('/small')->assertOk();

        expect(TracingRequest::firstOrFail()->response_body)->toBe('short');
    });

    it('masks response body fields before truncating them away', function () {
        // Invariant: a secret must not survive because truncation happened to
        // cut before the masker reached it, nor leak because it did not.
        config()->set('tracing.max_body_size', 60);
        config()->set('tracing.masked_response_body_params', ['access_token']);
        Route::get('/auth', fn () => response()->json([
            'access_token' => 'super-secret-xyz',
            'padding' => str_repeat('p', 500),
        ]));

        $this->getJson('/auth')->assertOk();

        $body = TracingRequest::firstOrFail()->response_body;

        expect($body)->not->toContain('super-secret-xyz')
            ->and($body)->toContain('[REDACTED]')
            ->and($body)->toEndWith('...[truncated]');
    });
});
