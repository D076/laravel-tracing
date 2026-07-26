<?php

namespace D076\Tracing\Services;

use D076\Tracing\Context\Tags;
use D076\Tracing\Context\TracingContext;
use D076\Tracing\Jobs\PersistTracingRecord;
use D076\Tracing\Models\TracingRequest;
use D076\Tracing\Support\BodyEncoding;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TracingService
{
    public function __construct(private readonly Tags $tags)
    {
    }

    public function persist(TracingContext $ctx, Response $response): void
    {
        try {
            $data = BodyEncoding::cleanForStorage($this->buildPayload($ctx, $response));

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

    /** @param array<string, mixed> $data */
    public function write(array $data): void
    {
        TracingRequest::create($data);
    }

    /**
     * @param array<string, list<string>> $headers
     * @param list<string> $maskedNames
     * @return array<string, list<string>>
     */
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

    /** @return array<string, mixed> */
    private function buildPayload(TracingContext $ctx, Response $response): array
    {
        $responseBody = null;

        if (config('tracing.store_response_body') && !($response instanceof StreamedResponse)) {
            $content = $response->getContent();
            if ($content !== false && $content !== '') {
                $responseBody = $this->maskResponseBody($content, $response->headers->get('Content-Type'));
            }
        }

        return [
            'id' => $ctx->traceId,
            'tags' => $this->tags->tags() ?: null,
            'method' => $ctx->method,
            'url' => $ctx->url,
            'route_name' => $ctx->routeName,
            'route_path' => $ctx->routePath,
            'request_headers' => $ctx->requestHeaders,
            'query_params' => $ctx->queryParams,
            'body_params' => $this->truncateJson(
                $this->maskBodyParams($ctx->bodyParams, config('tracing.masked_body_params'))
            ),
            'response_status' => $response->getStatusCode(),
            'response_headers' => $this->maskHeaders(
                $response->headers->all(),
                config('tracing.masked_response_headers')
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

    /**
     * @param array<string, mixed>|null $data
     * @param list<string> $maskedKeys
     * @return array<string, mixed>|null
     */
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

    /**
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>|null
     */
    private function truncateJson(?array $data): ?array
    {
        $maxSize = (int) config('tracing.max_body_size');

        // A non-positive budget disables truncation — and with it the encode
        // below, which exists only to measure. Without this guard a missing
        // config key (0) replaced every payload with the truncation summary.
        if ($data === null || $maxSize <= 0) {
            return $data;
        }

        // JSON_INVALID_UTF8_SUBSTITUTE: a legacy-encoded body param must not throw
        // here and drop the whole record — cleanForStorage() substitutes it later.
        // JSON_UNESCAPED_UNICODE: without it every non-ASCII character is measured
        // as its 6-byte \uXXXX escape, so a Cyrillic payload was discarded at a
        // third of the budget a Latin one gets — and pgsql/mysql normalize the
        // escapes away on write anyway, so the escaped form is not what is stored.
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

        return strlen($json) > $maxSize
            ? ['_truncated' => true, '_original_size' => strlen($json)]
            : $data;
    }

    private function maskResponseBody(string $content, ?string $contentType = null): ?string
    {
        // Normalize legacy charsets to UTF-8 before JSON parsing/truncation.
        $content = BodyEncoding::toUtf8($content, $contentType);

        // json_decode is the same parser json_validate runs, so asking it for the
        // value and reading the error tells us both things in one pass over what
        // may be megabytes.
        $decoded = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded)) {
                $decoded = $this->maskBodyParams(
                    $decoded,
                    config('tracing.masked_response_body_params'),
                );
            }

            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            $content = $encoded !== false ? $encoded : $content;
        } elseif (config('tracing.store_response_body_only_json')) {
            return null;
        }

        return BodyEncoding::truncateBytes($content, (int) config('tracing.max_body_size'));
    }
}
