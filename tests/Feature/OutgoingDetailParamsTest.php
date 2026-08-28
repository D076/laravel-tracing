<?php

use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Support\BodyEncoding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::define('viewTracing', fn ($user = null) => true);
});

/**
 * The outgoing detail endpoint derives query parameters from the stored URL and
 * form fields from the stored body. Nothing new is written: both are already in
 * the record, and deriving them on read keeps old records readable too.
 */

function outgoingRecord(array $attributes = []): OutgoingRequest
{
    return OutgoingRequest::create(array_merge([
        'method' => 'GET',
        'url' => 'https://api.test/thing',
    ], $attributes));
}

function outgoingDetail(OutgoingRequest $record): array
{
    return test()->getJson("/tracing/api/outgoing/{$record->id}")->assertOk()->json('data');
}

describe('query parameters', function () {
    it('exposes the query string of the url as parameters', function () {
        $record = outgoingRecord(['url' => 'https://api.test/orders?status=new&filter[city]=msk&ids[]=1&ids[]=2']);

        expect(outgoingDetail($record)['query_params'])->toBe([
            'status' => 'new',
            'filter' => ['city' => 'msk'],
            'ids' => ['1', '2'],
        ]);
    });

    it('leaves the url itself untouched', function () {
        $url = 'https://api.test/orders?status=new';

        expect(outgoingDetail(outgoingRecord(['url' => $url]))['url'])->toBe($url);
    });

    it('reports no parameters for a url without a query', function () {
        expect(outgoingDetail(outgoingRecord(['url' => 'https://api.test/orders']))['query_params'])->toBeNull();
    });
});

describe('form-encoded request body', function () {
    it('parses the body into fields when the request declared the form content type', function () {
        $record = outgoingRecord([
            'method' => 'POST',
            'request_headers' => ['Content-Type' => ['application/x-www-form-urlencoded']],
            'request_body' => 'status=new&filter%5Bcity%5D=msk',
        ]);

        $data = outgoingDetail($record);

        expect($data['request_body_params'])->toBe(['status' => 'new', 'filter' => ['city' => 'msk']])
            ->and($data['request_body'])->toBe('status=new&filter%5Bcity%5D=msk')
            ->and($data['request_body_truncated'])->toBeFalse();
    });

    it('keeps a JSON body as the raw string it already renders well as', function () {
        $record = outgoingRecord([
            'method' => 'POST',
            'request_headers' => ['Content-Type' => ['application/json']],
            'request_body' => '{"status":"new"}',
        ]);

        $data = outgoingDetail($record);

        expect($data['request_body_params'])->toBeNull()
            ->and($data['request_body'])->toBe('{"status":"new"}');
    });

    it('does not try to parse a multipart body', function () {
        $record = outgoingRecord([
            'method' => 'POST',
            'request_headers' => ['Content-Type' => ['multipart/form-data; boundary=--x']],
            'request_body' => '----x' . PHP_EOL . 'Content-Disposition: form-data; name="a"' . PHP_EOL . PHP_EOL . '1',
        ]);

        expect(outgoingDetail($record)['request_body_params'])->toBeNull();
    });

    it('parses nothing when the content type is unknown', function () {
        $record = outgoingRecord(['method' => 'POST', 'request_body' => 'status=new']);

        expect(outgoingDetail($record)['request_body_params'])->toBeNull();
    });

    // Masking happens on write (OutgoingTracingService::maskFormBody). Parsing on
    // read must not become a way around it — the placeholder is what gets shown.
    it('shows the masked placeholder rather than the secret', function () {
        $record = outgoingRecord([
            'method' => 'POST',
            'request_headers' => ['Content-Type' => ['application/x-www-form-urlencoded']],
            'request_body' => 'login=bob&password=%5BREDACTED%5D',
        ]);

        expect(outgoingDetail($record)['request_body_params'])
            ->toBe(['login' => 'bob', 'password' => '[REDACTED]']);
    });

    it('flags a truncated body and parses what survived of it', function () {
        $record = outgoingRecord([
            'method' => 'POST',
            'request_headers' => ['Content-Type' => ['application/x-www-form-urlencoded']],
            'request_body' => 'status=new&comment=cut he' . BodyEncoding::TRUNCATION_MARKER,
        ]);

        $data = outgoingDetail($record);

        expect($data['request_body_truncated'])->toBeTrue()
            ->and($data['request_body_params'])->toBe(['status' => 'new', 'comment' => 'cut he']);
    });
});

describe('form-encoded response body', function () {
    it('parses a form-encoded response the same way', function () {
        $record = outgoingRecord([
            'response_status' => 200,
            'response_headers' => ['Content-Type' => ['application/x-www-form-urlencoded']],
            'response_body' => 'token=abc&expires_in=3600',
        ]);

        $data = outgoingDetail($record);

        expect($data['response_body_params'])->toBe(['token' => 'abc', 'expires_in' => '3600'])
            ->and($data['response_body_truncated'])->toBeFalse();
    });

    it('leaves a JSON response alone', function () {
        $record = outgoingRecord([
            'response_status' => 200,
            'response_headers' => ['Content-Type' => ['application/json']],
            'response_body' => '{"token":"abc"}',
        ]);

        expect(outgoingDetail($record)['response_body_params'])->toBeNull();
    });
});

it('reports no parsed bodies when nothing was stored', function () {
    $data = outgoingDetail(outgoingRecord());

    expect($data['request_body_params'])->toBeNull()
        ->and($data['response_body_params'])->toBeNull()
        ->and($data['request_body_truncated'])->toBeFalse()
        ->and($data['response_body_truncated'])->toBeFalse();
});

/**
 * The fixtures above spell the stored record by hand. This one drives the whole
 * path — a real Http::asForm() call recorded by the listener, then read back —
 * so the shape of what Guzzle actually stores (header casing above all) is what
 * the endpoint is proven against.
 */
it('renders a real form-encoded call end-to-end', function () {
    config()->set('tracing.outgoing.enabled', true);
    config()->set('tracing.outgoing.driver', 'database');
    config()->set('tracing.outgoing.ignore_urls', []);
    config()->set('tracing.outgoing.masked_body_params', ['password']);

    Http::fake(['*' => Http::response('ok', 200)]);

    Http::asForm()->post('https://api.test/login?debug=1&scope[]=read&scope[]=write', [
        'login' => 'bob',
        'password' => 'secret',
        'filter' => ['city' => 'msk'],
    ]);

    $data = outgoingDetail(OutgoingRequest::firstOrFail());

    expect($data['query_params'])->toBe(['debug' => '1', 'scope' => ['read', 'write']])
        ->and($data['request_body_params'])->toBe([
            'login' => 'bob',
            'password' => '[REDACTED]',
            'filter' => ['city' => 'msk'],
        ]);
});
