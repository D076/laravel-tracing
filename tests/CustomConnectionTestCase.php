<?php

namespace D076\Tracing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The secondary connection under test is deliberately a FILE-based sqlite
 * database, not ':memory:' — do not "simplify" this back.
 *
 * RefreshDatabase branches on the *default* connection, not the secondary
 * one. On pgsql/mysql it runs migrate:fresh once per process (guarded by
 * the static RefreshDatabaseState::$migrated) and wraps each test in a
 * transaction on the default connection only. The package's migrations
 * target config('tracing.connection') — i.e. the secondary — regardless of
 * which connection is the app default. So when the app default is
 * pgsql/mysql, the secondary's tables are created on the first test only;
 * the application container is rebuilt between tests, and a fresh PDO to
 * ':memory:' would be a brand-new EMPTY database that never gets migrated
 * again. A file-based database survives those rebuilds within the same
 * process, because every rebuild reopens the same file instead of
 * conjuring a new empty one.
 *
 * Two more edge cases fall out of sharing RefreshDatabaseState::$migrated
 * with every other test file in the run, both handled below:
 *
 * 1. On a sqlite ':memory:' app default, the guard gets reset after every
 *    test (a fresh in-memory PDO can't retain schema across the app
 *    rebuild, so Laravel re-runs migrate:fresh every time). Left alone,
 *    that second migrate:fresh would CREATE TABLE again against a file
 *    that still has the previous test's tables and blow up with "table
 *    already exists". getEnvironmentSetUp() guards against this: whenever
 *    the flag reads false — a migrate:fresh is imminent regardless of why —
 *    the file is discarded first so the incoming CREATE TABLE lands on a
 *    clean slate.
 *
 * 2. The opposite also happens: when this test class runs *after* other,
 *    unrelated RefreshDatabase-using test files in the same process, the
 *    flag can already read true before our very first test — so Laravel's
 *    automatic migrate:fresh never fires at all, and our brand new file
 *    never gets a schema. setUp() below covers that by checking the
 *    secondary directly and migrating it itself, on demand, scoped with
 *    `--database=tracing_secondary`.
 *
 * What we deliberately do NOT do: force the shared guard false ourselves
 * to *trigger* Laravel's own migrate:fresh on demand. Its drop phase acts
 * on the shared *default* connection (not on config('tracing.connection')),
 * so forcing it from here would drop tables other, unrelated test files
 * created on that default connection and never restore them — a real
 * regression hit while building this fix. The `--database=tracing_secondary`
 * scoped call below is the safe equivalent: its drop and migrate phases,
 * and the migrations bookkeeping table they use, are both confined to the
 * secondary connection and touch nothing shared.
 *
 * Because the secondary sits outside RefreshDatabase's transaction handling
 * either way, tests are additionally isolated by clearing its tables
 * directly in setUp() (guarded by hasTable(), since on the very first test
 * the tables may not exist yet). connectionsToTransact() is not a fix for
 * this: on the sqlite ':memory:' default branch RefreshDatabase opens no
 * transaction on any connection, so that hook would work on pgsql/mysql and
 * silently do nothing on sqlite. An unconditional clear behaves identically
 * on all three drivers, which is the point.
 */
class CustomConnectionTestCase extends TestCase
{
    private static function secondaryDatabasePath(): string
    {
        return sys_get_temp_dir() . '/tracing_secondary_' . getmypid() . '.sqlite';
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $path = self::secondaryDatabasePath();

        // See point 1 in the class docblock: whenever RefreshDatabase is
        // about to (re-)run migrate:fresh this test, the file must be empty
        // first or CREATE TABLE collides with whatever it already has left
        // over from an earlier test.
        if (!RefreshDatabaseState::$migrated && file_exists($path)) {
            unlink($path);
        }

        if (!file_exists($path)) {
            touch($path);
        }

        $app['config']->set('database.connections.tracing_secondary', [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
        ]);

        $app['config']->set('tracing.connection', 'tracing_secondary');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // See point 2 in the class docblock: if nobody has migrated the
        // secondary yet (the shared guard was already satisfied by another
        // test file before we got our turn), do it ourselves — scoped to
        // this connection only, so it cannot disturb the default one.
        if (!Schema::connection('tracing_secondary')->hasTable('tracing_requests')) {
            Artisan::call('migrate:fresh', ['--database' => 'tracing_secondary']);
        }

        foreach (['tracing_requests', 'tracing_outgoing_requests'] as $table) {
            if (Schema::connection('tracing_secondary')->hasTable($table)) {
                // delete(), not truncate(): sqlite's TRUNCATE-equivalent
                // unconditionally also clears sqlite_sequence, which only
                // exists for tables with an AUTOINCREMENT column. These
                // tables key on a UUID, so there is no sqlite_sequence row
                // for them, and truncate() would fail trying to delete one.
                DB::connection('tracing_secondary')->table($table)->delete();
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        $path = self::secondaryDatabasePath();

        if (file_exists($path)) {
            unlink($path);
        }

        parent::tearDownAfterClass();
    }
}
