<?php

use D076\Tracing\Facades\Tracing;
use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

class TagPropagationJob implements ShouldQueue
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
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.ignore_paths', []);
    config()->set('tracing.outgoing.enabled', true);
    config()->set('tracing.outgoing.driver', 'database');
    config()->set('tracing.outgoing.ignore_urls', []);
    config()->set('queue.default', 'sync');
});

it('records tags on an incoming request', function () {
    Route::get('/probe', function () {
        Tracing::tag('team:billing', 'user:42');

        return 'ok';
    });

    $this->get('/probe')->assertOk();

    expect(TracingRequest::first()->tags)->toBe(['team:billing', 'user:42']);
});

it('records tags on an outgoing request', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    Tracing::tag('svc:payments');
    Http::get('https://api.example.com/charge');

    expect(OutgoingRequest::first()->tags)->toBe(['svc:payments']);
});

it('flows tags from the incoming scope onto outgoing calls made within it', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    Route::get('/checkout', function () {
        Tracing::tag('flow:checkout');
        Http::get('https://api.example.com/pay');

        return 'ok';
    });

    $this->get('/checkout')->assertOk();

    expect(OutgoingRequest::first()->tags)->toContain('flow:checkout')
        ->and(TracingRequest::first()->tags)->toContain('flow:checkout');
});

it('propagates tags from the dispatching context into a queued job', function () {
    Http::fake(['*' => Http::response('ok')]);

    Tracing::tag('team:billing');
    TagPropagationJob::dispatch();

    expect(OutgoingRequest::first()->tags)->toContain('team:billing');
});

describe('tag search API', function () {
    beforeEach(function () {
        Gate::define('viewTracing', fn ($user = null) => true);
    });

    it('filters outgoing records by an exact tag', function () {
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://a.test', 'tags' => ['team:billing']]);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://b.test', 'tags' => ['team:search']]);

        $response = $this->getJson('/tracing/api/outgoing?tag=team:billing')->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('https://a.test')
            ->and($response->json('data.0.tags'))->toBe(['team:billing']);
    });

    it('finds outgoing records by a tag substring via the standard search input', function () {
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://a.test', 'tags' => ['team:billing']]);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://b.test', 'tags' => ['team:search']]);

        $response = $this->getJson('/tracing/api/outgoing?search=billing')->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('https://a.test');
    });
});
