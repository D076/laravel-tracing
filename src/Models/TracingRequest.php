<?php

namespace D076\Tracing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $method
 * @property string $url
 * @property string|null $route_name
 * @property string|null $route_path
 * @property array|null $request_headers
 * @property array|null $query_params
 * @property array|null $body_params
 * @property int $response_status
 * @property array|null $response_headers
 * @property string|null $response_body
 * @property string|null $authenticatable_id
 * @property string|null $authenticatable_type
 * @property array{class: string, message: string, file: string, line: int}|null $exception
 * @property int|null $duration_ms
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
final class TracingRequest extends Model
{
    use HasUuids, MassPrunable;

    const null UPDATED_AT = null;

    protected $table = 'tracing_requests';

    public function getConnectionName(): ?string
    {
        return config('tracing.connection') ?: parent::getConnectionName();
    }

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function prunable(): Builder
    {
        $days = (int) config('tracing.retention_days', 0);

        if ($days <= 0) {
            return self::query()->whereRaw('0 = 1');
        }

        return self::query()->where('created_at', '<=', now()->subDays($days));
    }

    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'query_params' => 'array',
            'body_params' => 'array',
            'response_headers' => 'array',
            'response_status' => 'integer',
            'exception' => 'array',
            'duration_ms' => 'integer',
        ];
    }
}
