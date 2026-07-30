<?php

use D076\Tracing\Models\TracingRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * The UI exposes every request body, header and token the application ever saw,
 * so "who may read it" is the package's most load-bearing rule. Every other
 * suite defines a permissive gate in beforeEach; this one never does, and
 * asserts the closed side.
 */
describe('viewTracing gate', function () {
    it('denies a guest outside the local environment by default', function (string $path) {
        // The shipped default is `$app->isLocal()`, and testbench runs as 'testing'.
        $this->get($path)->assertForbidden();
    })->with([
        'spa shell' => ['/tracing'],
        'spa deep link' => ['/tracing/requests/1'],
        'requests api' => ['/tracing/api/requests'],
        'outgoing api' => ['/tracing/api/outgoing'],
    ]);

    it('allows access in the local environment by default', function () {
        $this->app['env'] = 'local';

        $this->get('/tracing')->assertOk();
        $this->getJson('/tracing/api/requests')->assertOk();
    });

    it('denies access when the application gate says no', function () {
        Gate::define('viewTracing', fn ($user = null) => false);

        $this->getJson('/tracing/api/requests')->assertForbidden();
    });

    it('does not leak record data in the body of a denied response', function () {
        TracingRequest::create([
            'method' => 'POST',
            'url' => '/checkout',
            'response_status' => 200,
            'body_params' => ['card' => '4111111111111111'],
        ]);

        $response = $this->getJson('/tracing/api/requests')->assertForbidden();

        expect($response->getContent())->not->toContain('4111111111111111')
            ->and($response->getContent())->not->toContain('/checkout');
    });

    it('serves static assets without authorization', function () {
        // Deliberate: the SPA bundle carries no data, and gating it would break
        // the login-less asset load of the shell itself.
        $this->get('/tracing/assets/app.css')->assertOk();
    });
});
