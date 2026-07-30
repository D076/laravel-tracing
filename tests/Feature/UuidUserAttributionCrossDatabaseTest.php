<?php

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('cross-db');

/**
 * An application whose User model is UUID- or ULID-keyed is ordinary Laravel,
 * and the package documents authenticatable_id as `string|null`. The column,
 * however, comes from nullableMorphs() — an unsigned bigint. sqlite and mysql
 * coerce silently; a strict backend rejects the INSERT, persist() swallows the
 * QueryException, and the whole audit record is lost with only a log line.
 *
 * Only observable on Postgres, hence the cross-db group.
 *
 * The outcome is asserted through the log rather than a row count: the rejected
 * INSERT leaves the surrounding test transaction in an aborted state, so any
 * follow-up query would fail for a second, unrelated reason.
 */
class UuidKeyedUser extends Model implements Authenticatable
{
    use AuthenticatableTrait;
    use HasUuids;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Only a strict backend rejects a UUID in a bigint column.');
    }

    config()->set('tracing.enabled', true);
    config()->set('tracing.driver', 'database');
    config()->set('tracing.ignore_paths', []);
});

it('records a request made by a UUID-keyed user', function () {
    Log::spy();

    $user = new UuidKeyedUser(['id' => (string) Illuminate\Support\Str::uuid7()]);
    Route::get('/me', fn () => response('ok'));

    $this->actingAs($user)->get('/me')->assertOk();

    // The audit record must survive; who it belongs to is secondary to not
    // losing the request entirely.
    Log::shouldNotHaveReceived('error');
})->todo('KNOWN BUG: authenticatable_id is an unsigned bigint (nullableMorphs), so on Postgres '
    . 'every request by a UUID/ULID-keyed user is dropped — persist() swallows the QueryException '
    . 'and only a log line remains. Fix: store authenticatable_id as a string column.');
