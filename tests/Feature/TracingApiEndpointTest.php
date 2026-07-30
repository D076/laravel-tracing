<?php

use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::define('viewTracing', fn ($user = null) => true);
});

function incoming(array $attributes = []): TracingRequest
{
    return TracingRequest::create(array_merge([
        'method' => 'GET',
        'url' => '/thing',
        'response_status' => 200,
    ], $attributes));
}

function outgoing(array $attributes = []): OutgoingRequest
{
    return OutgoingRequest::create(array_merge([
        'method' => 'GET',
        'url' => 'https://api.test/thing',
    ], $attributes));
}

describe('status_group filter', function () {
    beforeEach(function () {
        foreach ([200, 204, 301, 404, 422, 500] as $status) {
            incoming(['response_status' => $status, 'url' => "/s{$status}"]);
            outgoing(['response_status' => $status, 'url' => "https://api.test/s{$status}"]);
        }
    });

    it('selects a single status class', function (string $group, array $expected) {
        $response = $this->getJson("/tracing/api/requests?status_group={$group}")->assertOk();

        expect(array_column($response->json('data'), 'response_status'))
            ->toEqualCanonicalizing($expected);
    })->with([
        '2xx' => ['2xx', [200, 204]],
        '3xx' => ['3xx', [301]],
        '4xx' => ['4xx', [404, 422]],
        '5xx' => ['5xx', [500]],
    ]);

    it('ORs several classes given as a comma-separated list', function () {
        $response = $this->getJson('/tracing/api/requests?status_group=4xx,5xx')->assertOk();

        expect(array_column($response->json('data'), 'response_status'))
            ->toEqualCanonicalizing([404, 422, 500]);
    });

    it('ORs several classes given as an array', function () {
        $response = $this->getJson('/tracing/api/requests?status_group[]=2xx&status_group[]=5xx')->assertOk();

        expect(array_column($response->json('data'), 'response_status'))
            ->toEqualCanonicalizing([200, 204, 500]);
    });

    /**
     * The rule: a group nobody recognizes selects NOTHING. Never an unfiltered
     * result set — "found everything" reads as success and is the exact failure
     * stringQuery() and dateQuery() are documented to prevent.
     */
    it('does not fall back to an unfiltered result set for an unknown class', function (string $query) {
        $response = $this->getJson("/tracing/api/requests?{$query}")->assertOk();

        expect($response->json('meta.total'))->toBe(0);
    })->with([
        'unknown class' => ['status_group=9xx'],
        'several unknown classes' => ['status_group=9xx,xyz'],
        'unknown classes as an array' => ['status_group[]=9xx&status_group[]=xyz'],
        'garbage' => ['status_group=' . 'drop table'],
        // '0' is falsy in PHP: a truthiness check on the raw parameter skips the
        // filter entirely and hands back every record.
        'the string zero' => ['status_group=0'],
    ]);

    it('applies no filter at all when the parameter is present but empty', function (string $query) {
        // An empty value means "not asked for", consistent with stringQuery().
        $response = $this->getJson("/tracing/api/requests?{$query}")->assertOk();

        expect($response->json('meta.total'))->toBe(6);
    })->with([
        'empty string' => ['status_group='],
        'empty array entry' => ['status_group[]='],
        'only commas' => ['status_group=,,'],
    ]);

    it('still applies the recognized classes when an unknown one is mixed in', function () {
        $response = $this->getJson('/tracing/api/requests?status_group=5xx,9xx')->assertOk();

        expect(array_column($response->json('data'), 'response_status'))->toBe([500]);
    });

    it('tolerates whitespace around a comma-separated list', function () {
        $response = $this->getJson('/tracing/api/requests?status_group=' . urlencode('4xx, 5xx'))->assertOk();

        expect(array_column($response->json('data'), 'response_status'))
            ->toEqualCanonicalizing([404, 422, 500]);
    });

    it('ignores a nested array entry instead of erroring on it', function () {
        // (string) on an array would raise a warning and filter by "Array".
        $this->getJson('/tracing/api/requests?status_group[][]=2xx')->assertOk();
    });

    it('applies the same filter to outgoing records', function () {
        $response = $this->getJson('/tracing/api/outgoing?status_group=5xx')->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.response_status'))->toBe(500);
    });

    // The block is duplicated across index() and outgoingIndex(), so every rule
    // above is restated here — a fix applied to only one action must fail.
    it('holds the same rules on the outgoing endpoint', function (string $query, int $total) {
        $response = $this->getJson("/tracing/api/outgoing?{$query}")->assertOk();

        expect($response->json('meta.total'))->toBe($total);
    })->with([
        'unknown class selects nothing' => ['status_group=9xx', 0],
        'the string zero selects nothing' => ['status_group=0', 0],
        'empty value filters nothing' => ['status_group=', 6],
        'mixed known and unknown' => ['status_group=5xx,9xx', 1],
    ]);
});

describe('method filter', function () {
    it('matches case-insensitively by upper-casing the term', function (string $term) {
        incoming(['method' => 'POST', 'url' => '/posted']);
        incoming(['method' => 'GET', 'url' => '/got']);

        $response = $this->getJson("/tracing/api/requests?method={$term}")->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('/posted');
    })->with(['post', 'POST', 'PoSt']);
});

describe('has_exception filter', function () {
    it('selects only records carrying an exception', function () {
        incoming(['url' => '/boom', 'response_status' => 500, 'exception' => ['class' => 'RuntimeException', 'message' => 'x', 'file' => 'a.php', 'line' => 1]]);
        incoming(['url' => '/fine']);

        $response = $this->getJson('/tracing/api/requests?has_exception=1')->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('/boom')
            ->and($response->json('data.0.has_exception'))->toBeTrue()
            ->and($response->json('data.0.exception_class'))->toBe('RuntimeException');
    });

    it('does not filter at all when the flag is absent or false', function () {
        incoming(['url' => '/boom', 'exception' => ['class' => 'RuntimeException', 'message' => 'x', 'file' => 'a.php', 'line' => 1]]);
        incoming(['url' => '/fine']);

        expect($this->getJson('/tracing/api/requests')->assertOk()->json('meta.total'))->toBe(2)
            ->and($this->getJson('/tracing/api/requests?has_exception=0')->assertOk()->json('meta.total'))->toBe(2);
    });
});

describe('sorting', function () {
    beforeEach(function () {
        incoming(['url' => '/slow', 'duration_ms' => 900, 'created_at' => now()->subDay()]);
        incoming(['url' => '/fast', 'duration_ms' => 10, 'created_at' => now()]);
    });

    it('defaults to newest first', function () {
        $response = $this->getJson('/tracing/api/requests')->assertOk();

        expect(array_column($response->json('data'), 'url'))->toBe(['/fast', '/slow']);
    });

    it('sorts by an allowlisted column in the requested direction', function (string $sort, string $direction, array $expected) {
        $response = $this->getJson("/tracing/api/requests?sort={$sort}&direction={$direction}")->assertOk();

        expect(array_column($response->json('data'), 'url'))->toBe($expected);
    })->with([
        'duration asc' => ['duration_ms', 'asc', ['/fast', '/slow']],
        'duration desc' => ['duration_ms', 'desc', ['/slow', '/fast']],
        'created_at asc' => ['created_at', 'asc', ['/slow', '/fast']],
    ]);

    it('ignores a column outside the allowlist instead of interpolating it', function (string $sort) {
        // orderBy() interpolates its column, so anything but an allowlisted
        // value must be discarded rather than reaching SQL.
        $response = $this->getJson('/tracing/api/requests?sort=' . urlencode($sort))->assertOk();

        expect(array_column($response->json('data'), 'url'))->toBe(['/fast', '/slow']);
    })->with([
        'unknown column' => ['url'],
        'injection attempt' => ['id; drop table tracing_requests'],
        'array' => ['created_at'],
    ]);

    it('treats any direction other than asc as desc', function () {
        $response = $this->getJson('/tracing/api/requests?direction=sideways')->assertOk();

        expect(array_column($response->json('data'), 'url'))->toBe(['/fast', '/slow']);
    });

    it('leaves the table intact after an injection attempt', function () {
        $this->getJson('/tracing/api/requests?sort=' . urlencode('id; drop table tracing_requests'))->assertOk();

        expect(TracingRequest::count())->toBe(2);
    });
});

describe('pagination', function () {
    beforeEach(function () {
        foreach (range(1, 25) as $i) {
            incoming(['url' => "/item-{$i}"]);
        }
    });

    it('defaults to 50 per page', function () {
        $response = $this->getJson('/tracing/api/requests')->assertOk();

        expect($response->json('meta.per_page'))->toBe(50)
            ->and($response->json('meta.total'))->toBe(25)
            ->and($response->json('meta.last_page'))->toBe(1)
            ->and($response->json('data'))->toHaveCount(25);
    });

    it('honours a per_page inside the allowed range', function () {
        $response = $this->getJson('/tracing/api/requests?per_page=10')->assertOk();

        expect($response->json('meta.per_page'))->toBe(10)
            ->and($response->json('data'))->toHaveCount(10)
            ->and($response->json('meta.last_page'))->toBe(3);
    });

    it('clamps per_page to the supported bounds', function (string $requested, int $effective) {
        $response = $this->getJson("/tracing/api/requests?per_page={$requested}")->assertOk();

        expect($response->json('meta.per_page'))->toBe($effective);
    })->with([
        'below the floor' => ['1', 10],
        'zero' => ['0', 10],
        'negative' => ['-5', 10],
        'non-numeric' => ['many', 10],
        'above the ceiling' => ['5000', 200],
    ]);

    it('serves the requested page', function () {
        $first = $this->getJson('/tracing/api/requests?per_page=10&page=1')->assertOk();
        $second = $this->getJson('/tracing/api/requests?per_page=10&page=2')->assertOk();

        expect($second->json('meta.current_page'))->toBe(2)
            ->and($second->json('data'))->toHaveCount(10)
            ->and(array_column($second->json('data'), 'id'))
            ->not->toEqual(array_column($first->json('data'), 'id'));
    });
});

describe('GET /tracing/api/requests/{id}', function () {
    it('returns the full record, including the fields the list omits', function () {
        $record = incoming([
            'url' => '/orders',
            'method' => 'POST',
            'body_params' => ['name' => 'John'],
            'query_params' => ['ref' => 'email'],
            'request_headers' => ['x-req' => ['1']],
            'response_headers' => ['x-res' => ['2']],
            'response_body' => '{"ok":true}',
            'user_agent' => 'AcmeBot/1.0',
            'ip_address' => '203.0.113.7',
        ]);

        $this->getJson("/tracing/api/requests/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.body_params.name', 'John')
            ->assertJsonPath('data.query_params.ref', 'email')
            ->assertJsonPath('data.request_headers.x-req.0', '1')
            ->assertJsonPath('data.response_headers.x-res.0', '2')
            ->assertJsonPath('data.response_body', '{"ok":true}')
            ->assertJsonPath('data.user_agent', 'AcmeBot/1.0')
            ->assertJsonPath('data.ip_address', '203.0.113.7');
    });

    it('answers 404 for an id that does not exist', function () {
        $this->getJson('/tracing/api/requests/' . Str::uuid7())->assertNotFound();
    });
});

describe('GET /tracing/api/outgoing/{id}', function () {
    it('returns the full outgoing record', function () {
        $record = outgoing([
            'trace_id' => (string) Str::uuid7(),
            'request_body' => '{"in":1}',
            'response_body' => '{"out":2}',
            'exception_class' => 'RuntimeException',
            'exception_message' => 'boom',
            'duration_ms' => 42,
        ]);

        $this->getJson("/tracing/api/outgoing/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.trace_id', $record->trace_id)
            ->assertJsonPath('data.request_body', '{"in":1}')
            ->assertJsonPath('data.response_body', '{"out":2}')
            ->assertJsonPath('data.exception_message', 'boom')
            ->assertJsonPath('data.duration_ms', 42);
    });

    it('answers 404 for an id that does not exist', function () {
        $this->getJson('/tracing/api/outgoing/' . Str::uuid7())->assertNotFound();
    });
});

describe('outgoing trace_id filter', function () {
    it('selects only the calls made under one incoming request', function () {
        $traceId = (string) Str::uuid7();
        outgoing(['trace_id' => $traceId, 'url' => 'https://api.test/mine']);
        outgoing(['trace_id' => (string) Str::uuid7(), 'url' => 'https://api.test/other']);

        $response = $this->getJson("/tracing/api/outgoing?trace_id={$traceId}")->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('https://api.test/mine');
    });
});
