<?php

use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createTracingRequest(int $daysAgo): TracingRequest
{
    return TracingRequest::create([
        'method' => 'GET',
        'url' => '/example',
        'response_status' => 200,
        'created_at' => now()->subDays($daysAgo),
    ]);
}

describe('TracingRequest::prunable', function () {
    it('selects nothing when retention_days is 0, even with existing records', function () {
        config()->set('tracing.retention_days', 0);
        createTracingRequest(100);
        createTracingRequest(1);

        expect((new TracingRequest())->prunable()->count())->toBe(0);
    });

    it('selects nothing when retention_days is negative', function () {
        config()->set('tracing.retention_days', -5);
        createTracingRequest(100);

        expect((new TracingRequest())->prunable()->count())->toBe(0);
    });

    it('selects only records older than retention_days', function () {
        config()->set('tracing.retention_days', 30);
        $old = createTracingRequest(40);
        $recent = createTracingRequest(5);

        $ids = (new TracingRequest())->prunable()->pluck('id');

        expect($ids->all())->toBe([$old->id])
            ->and($ids->all())->not->toContain($recent->id);
    });

    it('deletes nothing via pruneAll when retention_days is 0', function () {
        config()->set('tracing.retention_days', 0);
        createTracingRequest(100);
        createTracingRequest(1);

        $deleted = (new TracingRequest())->pruneAll();

        expect($deleted)->toBe(0)
            ->and(TracingRequest::count())->toBe(2);
    });
});
