<?php

/*
 * Recorded parameters are compared with toEqual, not toBe: the JSON columns are
 * read back with their keys in whatever order the backend stores them — pgsql
 * `jsonb` and MySQL `json` both normalise it, SQLite keeps the written order —
 * and only the pairs are part of the contract, never their order.
 */

use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.ignore_paths', []);
});

/**
 * `body_params` and `query_params` are separate columns because a trace is a
 * record of what the client actually sent, and where it sent it. A query
 * parameter restated as a body field is a false statement about the request —
 * and it also spends the max_body_size budget twice.
 */
describe('body_params', function () {
    it('holds the form-encoded body only, not the query string', function () {
        Route::post('/orders', fn () => response('ok'));

        $this->post('/orders?source=newsletter&utm[campaign]=spring', [
            'field' => 'frombody',
        ])->assertOk();

        expect(TracingRequest::firstOrFail()->body_params)->toEqual(['field' => 'frombody']);
    });

    it('leaves the query string recorded in query_params', function () {
        Route::post('/orders', fn () => response('ok'));

        $this->post('/orders?source=newsletter&utm[campaign]=spring', [
            'field' => 'frombody',
        ])->assertOk();

        expect(TracingRequest::firstOrFail()->query_params)->toEqual([
            'source' => 'newsletter',
            'utm' => ['campaign' => 'spring'],
        ]);
    });

    it('holds the whole json body when one is sent alongside a query string', function () {
        // The branch that is easiest to break while fixing the one above:
        // $request->request is empty for a JSON body, so reading it alone would
        // stop recording JSON request bodies entirely.
        Route::post('/orders', fn () => response('ok'));

        $this->postJson('/orders?source=newsletter', [
            'order' => ['id' => 7],
            'lines' => [['sku' => 'a-1']],
        ])->assertOk();

        expect(TracingRequest::firstOrFail()->body_params)->toEqual([
            'order' => ['id' => 7],
            'lines' => [['sku' => 'a-1']],
        ]);
    });

    it('keeps nested form fields intact', function () {
        Route::post('/orders', fn () => response('ok'));

        $this->post('/orders?source=newsletter', [
            'customer' => ['email' => 'a@b.c'],
            'lines' => ['a-1', 'a-2'],
        ])->assertOk();

        expect(TracingRequest::firstOrFail()->body_params)->toEqual([
            'customer' => ['email' => 'a@b.c'],
            'lines' => ['a-1', 'a-2'],
        ]);
    });

    it('drops an uploaded file while keeping the fields beside it', function () {
        Route::post('/import', fn () => response('ok'));

        $this->post('/import?source=newsletter', [
            'label' => 'Q3',
            'sheet' => UploadedFile::fake()->create('rows.csv', 4),
        ])->assertOk();

        expect(TracingRequest::firstOrFail()->body_params)->toEqual(['label' => 'Q3']);
    });

    it('is null when the request carries no body at all', function () {
        Route::post('/ping', fn () => response('ok'));

        $this->post('/ping?source=newsletter')->assertOk();

        expect(TracingRequest::firstOrFail()->body_params)->toBeNull();
    });

    it('is null for a method that cannot carry one', function () {
        Route::get('/ping', fn () => response('ok'));

        $this->get('/ping?source=newsletter')->assertOk();

        expect(TracingRequest::firstOrFail()->body_params)->toBeNull();
    });

    it('records the body of a PUT and a PATCH the same way', function (string $method) {
        Route::match([$method], '/orders/1', fn () => response('ok'));

        $this->json($method, '/orders/1?source=newsletter', ['field' => 'frombody'])->assertOk();

        expect(TracingRequest::firstOrFail()->body_params)->toEqual(['field' => 'frombody']);
    })->with(['put', 'patch']);
});
