<?php

use D076\Tracing\Context\TraceId;
use D076\Tracing\Models\OutgoingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

class TraceIdPropagationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        Http::get('https://api.example.com/from-job');
    }
}

beforeEach(function () {
    config()->set('tracing.outgoing.enabled', true);
    config()->set('tracing.outgoing.driver', 'database');
    config()->set('tracing.outgoing.ignore_urls', []);
    config()->set('queue.default', 'sync');
});

it('writes the trace id to Laravel Context on every HTTP request', function () {
    Route::get('/probe', fn () => Context::get('tracing.trace_id'));

    $response = $this->get('/probe')->assertOk();

    expect($response->getContent())->not->toBeEmpty()
        ->and($response->headers->get('X-Trace-Id'))->toBe($response->getContent());
});

it('reads trace id from Laravel Context when the singleton has none', function () {
    Context::add('tracing.trace_id', 'parent-trace-xyz');

    $traceId = app(TraceId::class);
    $traceId->reset();

    expect($traceId->get())->toBe('parent-trace-xyz');
});

it('prefers Context over a stale singleton value (worker reusing TraceId across jobs)', function () {
    $traceId = app(TraceId::class);

    // Симулируем состояние воркера после первой джобы: в singleton сидит старый id.
    Context::add('tracing.trace_id', 'job-1-id');
    $traceId->get();

    // Приходит следующая джоба, Laravel восстанавливает её Context — старый singleton не должен победить.
    Context::add('tracing.trace_id', 'job-2-id');

    expect($traceId->get())->toBe('job-2-id');
});

it('propagates trace id from the dispatching context into a queued job', function () {
    Http::fake(['*' => Http::response('ok')]);

    Context::add('tracing.trace_id', 'parent-trace-xyz');

    // Симулируем фриш-воркер: singleton ещё пуст, восстановление Context даст trace_id из payload.
    app(TraceId::class)->reset();

    TraceIdPropagationJob::dispatch();

    expect(OutgoingRequest::first()->trace_id)->toBe('parent-trace-xyz');
});
