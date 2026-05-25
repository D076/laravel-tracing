<?php

namespace D076\Tracing\Services;

use D076\Tracing\Context\TracingContext;
use D076\Tracing\Jobs\PersistTracingRecord;
use D076\Tracing\Models\TracingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TracingService
{
    public function persist(TracingContext $ctx, Response $response): void
    {
        try {
            $data = $this->buildPayload($ctx, $response);

            if (config('tracing.driver') === 'queue') {
                PersistTracingRecord::dispatch($data)
                    ->onQueue(config('tracing.queue'))
                    ->onConnection(config('tracing.queue_connection'));
            } else {
                $this->write($data);
            }
        } catch (\Throwable $e) {
            Log::error('Tracing: failed to persist request record', [
                'trace_id' => $ctx->traceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function write(array $data): void
    {
        $connection = config('tracing.connection');

        if ($connection) {
            TracingRequest::on($connection)->create($data);
        } else {
            TracingRequest::create($data);
        }
    }

    public function maskHeaders(array $headers, array $maskedNames): array
    {
        $masked = array_map('strtolower', $maskedNames);
        $result = [];

        foreach ($headers as $name => $values) {
            $result[$name] = in_array(strtolower((string) $name), $masked, true)
                ? ['[REDACTED]']
                : $values;
        }

        return $result;
    }

    private function buildPayload(TracingContext $ctx, Response $response): array
    {
        $responseBody = null;

        if (config('tracing.store_response_body', false) && !($response instanceof StreamedResponse)) {
            $content = $response->getContent();
            if ($content !== false) {
                $responseBody = $this->truncateString($content);
            }

            if (json_validate($responseBody)) {
                $responseBody = json_encode(
                    json_decode($responseBody, true),
                    JSON_UNESCAPED_UNICODE,
                );
            } elseif (config('tracing.store_response_body_only_json', true)) {
                $responseBody = null;
            }
        }

        return [
            'id' => $ctx->traceId,
            'method' => $ctx->method,
            'url' => $ctx->url,
            'route_name' => $ctx->routeName,
            'route_path' => $ctx->routePath,
            'request_headers' => $ctx->requestHeaders,
            'query_params' => $ctx->queryParams,
            'body_params' => $this->truncateJson(
                $this->maskBodyParams($ctx->bodyParams, config('tracing.masked_body_params', []))
            ),
            'response_status' => $response->getStatusCode(),
            'response_headers' => $this->maskHeaders(
                $response->headers->all(),
                config('tracing.masked_response_headers', [])
            ),
            'response_body' => $responseBody,
            'authenticatable_id' => $ctx->authenticatableId,
            'authenticatable_type' => $ctx->authenticatableType,
            'exception' => $ctx->exception !== null ? [
                'class' => $ctx->exception::class,
                'message' => $ctx->exception->getMessage(),
                'file' => $ctx->exception->getFile(),
                'line' => $ctx->exception->getLine(),
            ] : null,
            'duration_ms' => $ctx->durationMs,
            'ip_address' => $ctx->ipAddress,
            'user_agent' => $ctx->userAgent,
        ];
    }

    public function maskBodyParams(?array $data, array $maskedKeys): ?array
    {
        if ($data === null || $maskedKeys === []) {
            return $data;
        }

        foreach ($maskedKeys as $key) {
            if (Arr::has($data, $key)) {
                data_set($data, $key, '[REDACTED]');
            }
        }

        return $data;
    }

    private function truncateJson(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $maxSize = config('tracing.max_body_size', 10000);

        if (strlen($json) > $maxSize) {
            return ['_truncated' => true, '_original_size' => strlen($json)];
        }

        return $data;
    }

    private function truncateString(string $content): string
    {
        $maxSize = config('tracing.max_body_size', 10000);

        if (strlen($content) > $maxSize) {
            return substr($content, 0, $maxSize) . '...[truncated]';
        }

        return $content;
    }
}
