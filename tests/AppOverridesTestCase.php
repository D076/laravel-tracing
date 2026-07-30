<?php

namespace D076\Tracing\Tests;

use D076\Tracing\Providers\TracingServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for a host application that defines its own `viewTracing` gate and
 * its own `tracing-api` rate limiter. Both hooks are "first definition wins",
 * decided at boot, so the only way to exercise them is from a provider that
 * boots BEFORE the package's — which is what the ordering below arranges.
 */
class AppOverridesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewTracing', fn ($user = null) => true);

        // Deliberately stricter than any tracing.rate_limit.* value a test can
        // set, so that "which limiter ran" is unambiguous from the response.
        RateLimiter::for('tracing-api', fn () => Limit::perMinute(1)->by('app-defined-bucket'));
    }
}

class AppOverridesTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AppOverridesServiceProvider::class,
            TracingServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('cache.default', 'array');
    }
}
