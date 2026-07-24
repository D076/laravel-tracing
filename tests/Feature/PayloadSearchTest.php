<?php

use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::define('viewTracing', fn ($user = null) => true);
});

// The issue's own example: a phone number buried in a payload. `+` must be
// percent-encoded, otherwise the query string decodes it to a space.
const NEEDLE = '+79023396677';

function payloadQuery(string $path, string $term): string
{
    return $path . '?payload=' . urlencode($term);
}

describe('deep search finds values inside outgoing payloads', function () {
    it('finds a record by a value in the request body', function () {
        OutgoingRequest::create([
            'method' => 'POST',
            'url' => 'https://api.sms.test/send',
            'request_body' => '{"to":"' . NEEDLE . '","text":"hi"}',
        ]);
        OutgoingRequest::create(['method' => 'POST', 'url' => 'https://api.sms.test/send', 'request_body' => '{"to":"+70000000000"}']);

        $response = $this->getJson(payloadQuery('/tracing/api/outgoing', NEEDLE))->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('https://api.sms.test/send');
    });

    it('finds a record by a value in the response body', function () {
        OutgoingRequest::create([
            'method' => 'GET',
            'url' => 'https://api.crm.test/customer',
            'response_body' => '{"phone":"' . NEEDLE . '"}',
        ]);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://api.crm.test/other', 'response_body' => '{"phone":"+70000000000"}']);

        $response = $this->getJson(payloadQuery('/tracing/api/outgoing', NEEDLE))->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('https://api.crm.test/customer');
    });

    it('finds a record by the exception message', function () {
        OutgoingRequest::create([
            'method' => 'GET',
            'url' => 'https://api.crm.test/boom',
            'exception_message' => 'Timeout while resolving ' . NEEDLE,
        ]);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://api.crm.test/ok']);

        $response = $this->getJson(payloadQuery('/tracing/api/outgoing', NEEDLE))->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });
});

describe('deep search finds values inside incoming payloads', function () {
    it('finds a record by a value in the request body params', function () {
        TracingRequest::create([
            'method' => 'POST',
            'url' => '/api/orders',
            'response_status' => 201,
            'body_params' => ['customer' => ['phone' => NEEDLE]],
        ]);
        TracingRequest::create(['method' => 'POST', 'url' => '/api/orders', 'response_status' => 201, 'body_params' => ['customer' => ['phone' => '+70000000000']]]);

        $response = $this->getJson(payloadQuery('/tracing/api/requests', NEEDLE))->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('finds a record by a value in the query params', function () {
        TracingRequest::create([
            'method' => 'GET',
            'url' => '/api/search',
            'response_status' => 200,
            'query_params' => ['phone' => NEEDLE],
        ]);
        TracingRequest::create(['method' => 'GET', 'url' => '/api/search', 'response_status' => 200, 'query_params' => ['phone' => '+70000000000']]);

        $response = $this->getJson(payloadQuery('/tracing/api/requests', NEEDLE))->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('finds a record by a value in the response body', function () {
        TracingRequest::create([
            'method' => 'GET',
            'url' => '/api/profile',
            'response_status' => 200,
            'response_body' => '{"phone":"' . NEEDLE . '"}',
        ]);
        TracingRequest::create(['method' => 'GET', 'url' => '/api/profile', 'response_status' => 200, 'response_body' => '{"phone":"+70000000000"}']);

        $response = $this->getJson(payloadQuery('/tracing/api/requests', NEEDLE))->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('finds a record by the stored exception', function () {
        TracingRequest::create([
            'method' => 'GET',
            'url' => '/api/boom',
            'response_status' => 500,
            'exception' => ['class' => 'RuntimeException', 'message' => 'bad number ' . NEEDLE, 'file' => 'a.php', 'line' => 1],
        ]);
        TracingRequest::create(['method' => 'GET', 'url' => '/api/ok', 'response_status' => 200]);

        $response = $this->getJson(payloadQuery('/tracing/api/requests', NEEDLE))->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('/api/boom');
    });
});

describe('the cheap search stays cheap', function () {
    it('does not match outgoing payloads via the standard search input', function () {
        OutgoingRequest::create([
            'method' => 'POST',
            'url' => 'https://api.sms.test/send',
            'request_body' => '{"to":"' . NEEDLE . '"}',
            'response_body' => '{"echo":"' . NEEDLE . '"}',
        ]);

        $response = $this->getJson('/tracing/api/outgoing?search=' . urlencode(NEEDLE))->assertOk();

        expect($response->json('meta.total'))->toBe(0);
    });

    it('does not match incoming payloads via the standard search input', function () {
        TracingRequest::create([
            'method' => 'POST',
            'url' => '/api/orders',
            'response_status' => 201,
            'body_params' => ['phone' => NEEDLE],
            'query_params' => ['phone' => NEEDLE],
            'response_body' => '{"phone":"' . NEEDLE . '"}',
        ]);

        $response = $this->getJson('/tracing/api/requests?search=' . urlencode(NEEDLE))->assertOk();

        expect($response->json('meta.total'))->toBe(0);
    });
});

describe('deep search composes with other filters', function () {
    it('ANDs with the method filter', function () {
        OutgoingRequest::create(['method' => 'POST', 'url' => 'https://a.test', 'request_body' => NEEDLE]);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://b.test', 'request_body' => NEEDLE]);

        $response = $this->getJson(payloadQuery('/tracing/api/outgoing', NEEDLE) . '&method=POST')->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('https://a.test');
    });
});

describe('malformed and edge-case parameters', function () {
    it('treats "0" as a real search term instead of ignoring the filter', function () {
        // '0' is falsy in PHP: a truthiness check would skip the filter entirely and
        // return EVERY record — which reads as "found everything", not "found nothing".
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://a.test', 'request_body' => 'value 0 here']);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://b.test', 'request_body' => 'nothing']);

        $response = $this->getJson('/tracing/api/outgoing?payload=0')->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('https://a.test');
    });

    it('does not blow up when array parameters are passed', function () {
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://a.test']);
        TracingRequest::create(['method' => 'GET', 'url' => '/a', 'response_status' => 200]);

        $this->getJson('/tracing/api/outgoing?payload[]=x&search[]=y&method[]=GET')->assertOk();
        $this->getJson('/tracing/api/requests?payload[]=x&search[]=y&route_path[]=z')->assertOk();
        $this->getJson('/tracing/api/outgoing?tag[][]=nested')->assertOk();
    });

    it('ANDs the standard search with the deep search', function () {
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://alpha.test', 'request_body' => NEEDLE]);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://beta.test', 'request_body' => NEEDLE]);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://alpha.test', 'request_body' => 'unrelated']);

        $response = $this->getJson(payloadQuery('/tracing/api/outgoing', NEEDLE) . '&search=alpha')->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('https://alpha.test');
    });
});

describe('standard search header coverage (issue #3 gap)', function () {
    it('finds an outgoing record by a value in its request headers', function () {
        OutgoingRequest::create([
            'method' => 'GET',
            'url' => 'https://a.test',
            'request_headers' => ['x-correlation-id' => ['needle-abc-1']],
        ]);
        OutgoingRequest::create(['method' => 'GET', 'url' => 'https://b.test', 'request_headers' => ['x-other' => ['unrelated']]]);

        $response = $this->getJson('/tracing/api/outgoing?search=needle-abc-1')->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.url'))->toBe('https://a.test');
    });

    it('finds records by a value in their response headers', function () {
        OutgoingRequest::create([
            'method' => 'GET',
            'url' => 'https://a.test',
            'response_headers' => ['x-request-id' => ['needle-resp-9']],
        ]);
        TracingRequest::create([
            'method' => 'GET',
            'url' => '/alpha',
            'response_status' => 200,
            'response_headers' => ['x-request-id' => ['needle-resp-9']],
        ]);

        expect($this->getJson('/tracing/api/outgoing?search=needle-resp-9')->assertOk()->json('meta.total'))->toBe(1)
            ->and($this->getJson('/tracing/api/requests?search=needle-resp-9')->assertOk()->json('meta.total'))->toBe(1);
    });
});
