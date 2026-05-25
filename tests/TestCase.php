<?php

namespace D076\Tracing\Tests;

use D076\Tracing\Providers\TracingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TracingServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $driver = env('DB_DRIVER', 'sqlite');

        $app['config']->set('database.default', $driver);

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('PG_HOST', 'pgsql'),
            'port' => 5432,
            'database' => env('PG_DATABASE', 'tracing_test'),
            'username' => env('PG_USERNAME', 'tracing'),
            'password' => env('PG_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('MYSQL_HOST', 'mysql'),
            'port' => 3306,
            'database' => env('MYSQL_DATABASE', 'tracing_test'),
            'username' => env('MYSQL_USERNAME', 'tracing'),
            'password' => env('MYSQL_PASSWORD', 'secret'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
    }
}
