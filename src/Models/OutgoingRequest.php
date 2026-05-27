<?php

namespace D076\Tracing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $trace_id
 * @property string $method
 * @property string $url
 * @property array|null $request_headers
 * @property string|null $request_body
 * @property int|null $response_status
 * @property array|null $response_headers
 * @property string|null $response_body
 * @property string|null $exception_class
 * @property string|null $exception_message
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
final class OutgoingRequest extends Model
{
    use HasUuids, MassPrunable;

    const null UPDATED_AT = null;

    protected $table = 'tracing_outgoing_requests';

    public function getConnectionName(): ?string
    {
        return config('tracing.connection') ?: parent::getConnectionName();
    }

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public function prunable(): Builder
    {
        $days = (int) config('tracing.outgoing.retention_days', 0);

        if ($days <= 0) {
            return self::query()->whereRaw('0 = 1');
        }

        return self::query()->where('created_at', '<=', now()->subDays($days));
    }

    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'response_headers' => 'array',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
