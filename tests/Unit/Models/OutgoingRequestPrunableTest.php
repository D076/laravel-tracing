<?php

use D076\Tracing\Models\OutgoingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createOutgoingRequest(int $daysAgo): OutgoingRequest
{
    return OutgoingRequest::create([
        'method' => 'GET',
        'url' => 'https://example.test/api',
        'created_at' => now()->subDays($daysAgo),
    ]);
}

describe('OutgoingRequest::prunable', function () {
    it('selects nothing when retention_days is 0, even with existing records', function () {
        config()->set('tracing.outgoing.retention_days', 0);
        createOutgoingRequest(100);
        createOutgoingRequest(1);

        expect((new OutgoingRequest())->prunable()->count())->toBe(0);
    });

    it('selects nothing when retention_days is negative', function () {
        config()->set('tracing.outgoing.retention_days', -5);
        createOutgoingRequest(100);

        expect((new OutgoingRequest())->prunable()->count())->toBe(0);
    });

    it('selects only records older than retention_days', function () {
        config()->set('tracing.outgoing.retention_days', 30);
        $old = createOutgoingRequest(40);
        $recent = createOutgoingRequest(5);

        $ids = (new OutgoingRequest())->prunable()->pluck('id');

        expect($ids->all())->toBe([$old->id])
            ->and($ids->all())->not->toContain($recent->id);
    });

    it('deletes nothing via pruneAll when retention_days is 0', function () {
        config()->set('tracing.outgoing.retention_days', 0);
        createOutgoingRequest(100);
        createOutgoingRequest(1);

        $deleted = (new OutgoingRequest())->pruneAll();

        expect($deleted)->toBe(0)
            ->and(OutgoingRequest::count())->toBe(2);
    });
});
