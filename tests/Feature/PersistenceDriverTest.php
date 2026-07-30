<?php

use D076\Tracing\Jobs\PersistOutgoingRecord;
use D076\Tracing\Jobs\PersistTracingRecord;
use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use D076\Tracing\Services\OutgoingTracingService;
use D076\Tracing\Services\TracingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.ignore_paths', []);
    config()->set('tracing.outgoing.enabled', true);
    config()->set('tracing.outgoing.ignore_urls', []);
});

describe('driver=queue defers the write', function () {
    it('queues the incoming record instead of writing it inline', function () {
        config()->set('tracing.driver', 'queue');
        Queue::fake();
        Route::get('/probe', fn () => response('ok'));

        $this->get('/probe')->assertOk();

        Queue::assertPushed(PersistTracingRecord::class);
        expect(TracingRequest::count())->toBe(0);
    });

    it('queues the outgoing record instead of writing it inline', function () {
        config()->set('tracing.outgoing.driver', 'queue');
        Queue::fake();
        Http::fake(['*' => Http::response('pong', 200)]);

        Http::get('https://api.example.com/ping');

        Queue::assertPushed(PersistOutgoingRecord::class);
        expect(OutgoingRequest::count())->toBe(0);
    });

    it('routes the job to the configured queue and connection', function () {
        config()->set('tracing.driver', 'queue');
        config()->set('tracing.queue', 'tracing-low');
        config()->set('queue.connections.tracing-redis', ['driver' => 'sync']);
        config()->set('tracing.queue_connection', 'tracing-redis');
        Queue::fake();
        Route::get('/probe', fn () => response('ok'));

        $this->get('/probe')->assertOk();

        Queue::assertPushedOn('tracing-low', PersistTracingRecord::class);
        Queue::assertPushed(
            PersistTracingRecord::class,
            fn (PersistTracingRecord $job) => $job->connection === 'tracing-redis',
        );
    });

    it('writes the same record the inline driver would when the job runs', function () {
        config()->set('tracing.driver', 'queue');
        config()->set('queue.default', 'sync');
        Route::get('/probe', fn () => response('ok'));

        // Sync driver: the job is dispatched and executed in-process, so this
        // covers the dispatch and the handle() side in one pass.
        $this->get('/probe')->assertOk();

        $record = TracingRequest::firstOrFail();

        expect($record->method)->toBe('GET')
            ->and($record->url)->toContain('/probe')
            ->and($record->response_status)->toBe(200);
    });

    it('persists the payload handed to the incoming job', function () {
        $data = ['id' => (string) Illuminate\Support\Str::uuid7(), 'method' => 'GET', 'url' => '/from-job', 'response_status' => 200];

        (new PersistTracingRecord($data))->handle(app(TracingService::class));

        expect(TracingRequest::firstOrFail()->url)->toBe('/from-job');
    });

    it('persists the payload handed to the outgoing job', function () {
        $data = ['id' => (string) Illuminate\Support\Str::uuid7(), 'method' => 'GET', 'url' => 'https://api.test/from-job'];

        (new PersistOutgoingRecord($data))->handle(app(OutgoingTracingService::class));

        expect(OutgoingRequest::firstOrFail()->url)->toBe('https://api.test/from-job');
    });

    it('treats any unknown driver value as the inline database write', function () {
        config()->set('tracing.driver', 'not-a-driver');
        Queue::fake();
        Route::get('/probe', fn () => response('ok'));

        $this->get('/probe')->assertOk();

        Queue::assertNothingPushed();
        expect(TracingRequest::count())->toBe(1);
    });
});

/**
 * The package sits in the global middleware stack of every request an
 * application serves. A tracing failure must degrade to a log line, never to a
 * 500 — that is the whole reason persist() catches Throwable.
 */
describe('a failing write never breaks the application request', function () {
    it('still answers the incoming request when the insert fails', function () {
        Log::spy();
        Route::get('/probe', fn () => response('ok'));
        Schema::drop('tracing_requests');

        $this->get('/probe')->assertOk()->assertSee('ok');

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'failed to persist request record'))
            ->once();
    });

    it('still returns the outgoing response when the insert fails', function () {
        Log::spy();
        Schema::drop('tracing_outgoing_requests');
        Http::fake(['*' => Http::response('pong', 200)]);

        $response = Http::get('https://api.example.com/ping');

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('pong');

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'failed to persist outgoing request'))
            ->once();
    });

    it('does not abort the incoming request when the outgoing write fails mid-handler', function () {
        Log::spy();
        Schema::drop('tracing_outgoing_requests');
        Http::fake(['*' => Http::response('pong', 200)]);

        Route::get('/calls-out', function () {
            Http::get('https://api.example.com/ping');

            return 'done';
        });

        // The handler runs to completion and the client is served normally; the
        // failure is confined to a log line. Asserted without a follow-up query,
        // because on a strict backend the rejected INSERT aborts the surrounding
        // test transaction and any later query would fail for its own reasons.
        $this->get('/calls-out')->assertOk()->assertSee('done');

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'failed to persist outgoing request'))
            ->once();
    });
});
