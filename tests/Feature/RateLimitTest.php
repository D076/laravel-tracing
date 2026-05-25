<?php

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

class RateLimitFakeAdmin extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $guarded = [];
}

class RateLimitFakeCustomer extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('tracing.rate_limit.enabled', true);
    Gate::define('viewTracing', fn ($user = null) => true);
});

it('returns 429 after exceeding the api rate limit', function () {
    config()->set('tracing.rate_limit.max_attempts', 2);

    $this->getJson('/tracing/api/requests')->assertOk();
    $this->getJson('/tracing/api/requests')->assertOk();
    $this->getJson('/tracing/api/requests')->assertStatus(429);
});

it('does not throttle the SPA shell or assets', function () {
    config()->set('tracing.rate_limit.max_attempts', 1);

    $this->get('/tracing')->assertOk();
    $this->get('/tracing')->assertOk();
    $this->get('/tracing/some/deep/link')->assertOk();
});

it('does not throttle when rate limiting is disabled', function () {
    config()->set('tracing.rate_limit.enabled', false);
    config()->set('tracing.rate_limit.max_attempts', 1);

    $this->getJson('/tracing/api/requests')->assertOk();
    $this->getJson('/tracing/api/requests')->assertOk();
    $this->getJson('/tracing/api/requests')->assertOk();
});

it('keys the limit per polymorphic user type, not just id', function () {
    config()->set('tracing.rate_limit.max_attempts', 1);

    $admin = new RateLimitFakeAdmin(['id' => 1]);
    $customer = new RateLimitFakeCustomer(['id' => 1]); // тот же id, другой морф-класс

    $this->actingAs($admin)->getJson('/tracing/api/requests')->assertOk();
    $this->actingAs($admin)->getJson('/tracing/api/requests')->assertStatus(429);

    // Тот же id, но другая модель — отдельный бакет, не должен быть зашейплен.
    $this->actingAs($customer)->getJson('/tracing/api/requests')->assertOk();
});
