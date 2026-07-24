<?php

namespace D076\Tracing\Tests;

/**
 * Boots the package with tracing.enabled=false set BEFORE the service provider's
 * boot() runs. This is the only way to exercise the master switch: flipping the
 * flag in a test body (after boot) can't un-register already-prepended middleware
 * or already-bound client-event listeners.
 */
class DisabledTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('tracing.enabled', false);
    }
}
