<?php

use D076\Tracing\Models\TracingRequest;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

class MetadataFakeUser extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.ignore_paths', []);
});

/**
 * Everything below is filled in during terminate(), after the route has been
 * resolved and the user bound — none of it is available when handle() runs.
 */
describe('record metadata', function () {
    it('stores the resolved route name and uri pattern, not the concrete path', function () {
        Route::get('/orders/{order}', fn () => response('ok'))->name('orders.show');

        $this->get('/orders/42')->assertOk();

        $record = TracingRequest::firstOrFail();

        expect($record->route_name)->toBe('orders.show')
            ->and($record->route_path)->toBe('orders/{order}')
            ->and($record->url)->toContain('/orders/42');
    });

    it('leaves route fields null when no route matched', function () {
        $this->get('/nothing-here')->assertNotFound();

        $record = TracingRequest::firstOrFail();

        expect($record->route_name)->toBeNull()
            ->and($record->route_path)->toBeNull();
    });

    it('attributes the record to the authenticated user polymorphically', function () {
        $user = new MetadataFakeUser(['id' => 7]);
        Route::get('/me', fn () => response('ok'));

        $this->actingAs($user)->get('/me')->assertOk();

        $record = TracingRequest::firstOrFail();

        // Cast: the column is an unsigned bigint, so the driver may hand back
        // either an int or a numeric string depending on the backend.
        expect((string) $record->authenticatable_id)->toBe('7')
            ->and($record->authenticatable_type)->toBe(MetadataFakeUser::class);
    });

    it('leaves the record unattributed for a guest', function () {
        Route::get('/public', fn () => response('ok'));

        $this->get('/public')->assertOk();

        $record = TracingRequest::firstOrFail();

        expect($record->authenticatable_id)->toBeNull()
            ->and($record->authenticatable_type)->toBeNull();
    });

    it('stores the client ip and user agent', function () {
        Route::get('/probe', fn () => response('ok'));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get('/probe', ['User-Agent' => 'AcmeBot/2.1'])
            ->assertOk();

        $record = TracingRequest::firstOrFail();

        expect($record->ip_address)->toBe('203.0.113.7')
            ->and($record->user_agent)->toBe('AcmeBot/2.1');
    });

    it('stores the full url including the query string', function () {
        Route::get('/search', fn () => response('ok'));

        $this->get('/search?q=hats&page=2')->assertOk();

        $record = TracingRequest::firstOrFail();

        expect($record->url)->toContain('q=hats')
            ->and($record->query_params)->toBe(['q' => 'hats', 'page' => '2']);
    });

    it('records a non-negative duration for every request', function () {
        Route::get('/probe', fn () => response('ok'));

        $this->get('/probe')->assertOk();

        expect(TracingRequest::firstOrFail()->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0);
    });

    it('masks configured request headers', function () {
        config()->set('tracing.masked_request_headers', ['authorization', 'x-api-key']);
        Route::get('/probe', fn () => response('ok'));

        $this->get('/probe', [
            'Authorization' => 'Bearer super-secret-token',
            'X-Api-Key' => 'key-abc-123',
            'X-Request-Id' => 'keep-me',
        ])->assertOk();

        $headers = TracingRequest::firstOrFail()->request_headers;

        expect($headers['authorization'])->toBe(['[REDACTED]'])
            ->and($headers['x-api-key'])->toBe(['[REDACTED]'])
            ->and($headers['x-request-id'])->toBe(['keep-me']);
    });

    it('masks configured response headers', function () {
        config()->set('tracing.masked_response_headers', ['set-cookie']);
        Route::get('/probe', fn () => response('ok')->withHeaders(['Set-Cookie' => 'session=abc123']));

        $this->get('/probe')->assertOk();

        $headers = TracingRequest::firstOrFail()->response_headers;

        expect($headers['set-cookie'])->toBe(['[REDACTED]'])
            ->and(json_encode($headers))->not->toContain('abc123');
    });

    it('captures a body for PUT and PATCH, like it does for POST', function (string $method) {
        Route::match(['put', 'patch'], '/resource', fn () => response('ok'));

        $this->json($method, '/resource', ['name' => 'John'])->assertOk();

        expect(TracingRequest::firstOrFail()->body_params)->toBe(['name' => 'John']);
    })->with(['PUT', 'PATCH']);
});
