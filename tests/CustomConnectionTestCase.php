<?php

namespace D076\Tracing\Tests;

class CustomConnectionTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.tracing_secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('tracing.connection', 'tracing_secondary');
    }
}
