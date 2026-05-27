<?php

namespace D076\Tracing\Services;

use D076\Tracing\Jobs\PersistOutgoingRecord;
use D076\Tracing\Models\OutgoingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class OutgoingTracingService
{
    public function persist(
        string $traceId,
        RequestInterface $request,
        ?ResponseInterface $response,
        ?Throwable $exception,
        int $durationMs,
    ): void {
        try {
            $data = $this->buildPayload($traceId, $request, $response, $exception, $durationMs);

            if (config('tracing.outgoing.driver') === 'queue') {
                PersistOutgoingRecord::dispatch($data)
                    ->onQueue(config('tracing.outgoing.queue'))
                    ->onConnection(config('tracing.outgoing.queue_connection'));
            } else {
                $this->write($data);
            }
        } catch (Throwable $e) {
            Log::error('Tracing: failed to persist outgoing request', [
                'trace_id' => $traceId,
                'url' => (string) $request->getUri(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    public function write(array $data): void
    {
        OutgoingRequest::create($data);
    }

    /** @return array<string, mixed> */
    private function buildPayload(
        string $traceId,
        RequestInterface $request,
        ?ResponseInterface $response,
        ?Throwable $exception,
        int $durationMs,
    ): array {
        $masked = array_map('strtolower', config('tracing.outgoing.masked_request_headers', []));

        $requestHeaders = array_map(
            fn($name, $values) => in_array(strtolower($name), $masked, true) ? ['[REDACTED]'] : $values,
            array_keys($request->getHeaders()),
            array_values($request->getHeaders()),
        );
        $requestHeaders = array_combine(array_keys($request->getHeaders()), $requestHeaders);

        return [
            'id' => (string) Str::uuid7(),
            'trace_id' => $traceId,
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'request_headers' => $requestHeaders ?: null,
            'request_body' => config('tracing.outgoing.store_request_body', true)
                                    ? $this->maskBody(
                                        $this->readBody($request, truncate: false),
                                        config('tracing.outgoing.masked_body_params', []),
                                        $request->getHeaderLine('Content-Type') ?: null,
                                    )
                                    : null,
            'response_status' => $response?->getStatusCode(),
            'response_headers' => $response ? array_map(fn($v) => $v, $response->getHeaders()) : null,
            'response_body' => (config('tracing.outgoing.store_response_body', true) && $response !== null)
                                    ? $this->maskBody(
                                        $this->readBody($response, truncate: false),
                                        config('tracing.outgoing.masked_response_body_params', []),
                                        $response->getHeaderLine('Content-Type') ?: null,
                                    )
                                    : null,
            'exception_class' => $exception !== null ? $exception::class : null,
            'exception_message' => $exception?->getMessage(),
            'duration_ms' => $durationMs,
        ];
    }

    /** @param list<string> $maskedKeys */
    private function maskBody(?string $body, array $maskedKeys, ?string $contentType): ?string
    {
        if ($body === null) {
            return null;
        }

        if ($contentType !== null && str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            return $this->maskFormBody($body, $maskedKeys);
        }

        return $this->maskJsonBody($body, $maskedKeys);
    }

    /** @param list<string> $maskedKeys */
    private function maskFormBody(?string $body, array $maskedKeys): ?string
    {
        if ($body === null) {
            return null;
        }

        if ($maskedKeys !== []) {
            parse_str($body, $parsed);

            foreach ($maskedKeys as $key) {
                if (Arr::has($parsed, $key)) {
                    data_set($parsed, $key, '[REDACTED]');
                }
            }

            $body = http_build_query($parsed);
        }

        $max = (int) config('tracing.outgoing.max_body_size', 10000);

        return strlen($body) > $max
            ? substr($body, 0, $max) . '...[truncated]'
            : $body;
    }

    /** @param list<string> $maskedKeys */
    private function maskJsonBody(?string $body, array $maskedKeys): ?string
    {
        if ($body === null) {
            return null;
        }

        if ($maskedKeys !== []) {
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                foreach ($maskedKeys as $key) {
                    if (Arr::has($decoded, $key)) {
                        data_set($decoded, $key, '[REDACTED]');
                    }
                }

                $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $body = $encoded !== false ? $encoded : $body;
            }
        }

        $max = (int) config('tracing.outgoing.max_body_size', 10000);

        return strlen($body) > $max
            ? substr($body, 0, $max) . '...[truncated]'
            : $body;
    }

    private function readBody(RequestInterface|ResponseInterface $message, bool $truncate = true): ?string
    {
        try {
            $body = $message->getBody();

            if (!$body->isReadable()) {
                return null;
            }

            if ($body->isSeekable()) {
                $body->rewind();
                $content = $body->getContents();
                $body->rewind();
            } else {
                // Non-seekable stream: read without rewinding (body will be consumed)
                // This is a rare case (streaming responses); skip to avoid breaking the caller.
                return null;
            }

            if ($content === '') {
                return null;
            }

            if (!$truncate) {
                return $content;
            }

            $max = (int) config('tracing.outgoing.max_body_size', 10000);

            return strlen($content) > $max
                ? substr($content, 0, $max) . '...[truncated]'
                : $content;
        } catch (Throwable) {
            return null;
        }
    }
}
