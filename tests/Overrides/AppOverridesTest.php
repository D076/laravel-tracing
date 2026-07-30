<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the gate the application defined instead of the local-only default', function () {
    // The package default is `$app->isLocal()`, and testbench runs as 'testing',
    // so a 200 here can only come from the application's own gate surviving boot.
    expect($this->app->isLocal())->toBeFalse();

    $this->getJson('/tracing/api/requests')->assertOk();
});

it('keeps the rate limiter the application defined', function () {
    // If the package had overwritten it, this config would disable throttling
    // entirely (Limit::none()) and the second call would pass.
    config()->set('tracing.rate_limit.enabled', false);
    config()->set('tracing.rate_limit.max_attempts', 120);

    $this->getJson('/tracing/api/requests')->assertOk();
    $this->getJson('/tracing/api/requests')->assertStatus(429);
});

it('applies the application limiter to the api only, not to the SPA shell', function () {
    $this->getJson('/tracing/api/requests')->assertOk();
    $this->getJson('/tracing/api/requests')->assertStatus(429);

    // The shell and its deep links are outside the throttle group by design.
    $this->get('/tracing')->assertOk();
    $this->get('/tracing/some/deep/link')->assertOk();
});
