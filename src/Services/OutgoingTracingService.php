<?php

namespace D076\Tracing\Services;

use D076\Tracing\Context\Tags;
use D076\Tracing\Jobs\PersistOutgoingRecord;
use D076\Tracing\Models\OutgoingRequest;
use D076\Tracing\Support\BodyEncoding;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class OutgoingTracingService
{
    public function __construct(private readonly Tags $tags)
    {
    }

    public function persist(
        string $traceId,
        RequestInterface $request,
        ?ResponseInterface $response,
        ?Throwable $exception,
        int $durationMs,
    ): void {
        try {
            $data = BodyEncoding::cleanForStorage(
                $this->buildPayload($traceId, $request, $response, $exception, $durationMs)
            );

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

    /**
     * Запрос к этому URL не трейсится (config tracing.outgoing.ignore_urls).
     */
    public function isIgnored(string $url): bool
    {
        foreach (config('tracing.outgoing.ignore_urls') as $pattern) {
            if (fnmatch($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function buildPayload(
        string $traceId,
        RequestInterface $request,
        ?ResponseInterface $response,
        ?Throwable $exception,
        int $durationMs,
    ): array {
        $masked = array_map('strtolower', config('tracing.outgoing.masked_request_headers'));
        $requestHeaders = [];

        foreach ($request->getHeaders() as $name => $values) {
            $requestHeaders[$name] = in_array(strtolower((string) $name), $masked, true)
                ? ['[REDACTED]']
                : $values;
        }

        return [
            'id' => (string) Str::uuid7(),
            'trace_id' => $traceId,
            'tags' => $this->tags->tags() ?: null,
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'request_headers' => $requestHeaders ?: null,
            'request_body' => config('tracing.outgoing.store_request_body')
                                    ? $this->maskBody(
                                        $this->readBody($request),
                                        config('tracing.outgoing.masked_body_params'),
                                        $request->getHeaderLine('Content-Type') ?: null,
                                    )
                                    : null,
            'response_status' => $response?->getStatusCode(),
            'response_headers' => $response?->getHeaders(),
            'response_body' => (config('tracing.outgoing.store_response_body') && $response !== null)
                                    ? $this->maskBody(
                                        $this->readBody($response),
                                        config('tracing.outgoing.masked_response_body_params'),
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

        // Normalize legacy charsets to UTF-8 before masking/truncation — otherwise
        // json_decode/parse_str fail and a strict backend rejects the raw bytes.
        $body = BodyEncoding::toUtf8($body, $contentType);

        if ($contentType !== null && str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            return $this->maskFormBody($body, $maskedKeys);
        }

        return $this->maskJsonBody($body, $maskedKeys);
    }

    /** @param list<string> $maskedKeys */
    private function maskFormBody(string $body, array $maskedKeys): string
    {
        if ($maskedKeys !== []) {
            parse_str($body, $parsed);

            foreach ($maskedKeys as $key) {
                if (Arr::has($parsed, $key)) {
                    data_set($parsed, $key, '[REDACTED]');
                }
            }

            $body = http_build_query($parsed);
        }

        return $this->truncate($body);
    }

    /** @param list<string> $maskedKeys */
    private function maskJsonBody(string $body, array $maskedKeys): string
    {
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

        return $this->truncate($body);
    }

    private function truncate(string $body): string
    {
        return BodyEncoding::truncateBytes($body, (int) config('tracing.outgoing.max_body_size'));
    }

    /**
     * The whole raw body, deliberately untruncated: at this point it is neither
     * normalized to UTF-8 nor masked, and cutting before those steps would break
     * the masking-before-truncation invariant. maskBody() truncates afterwards.
     */
    private function readBody(RequestInterface|ResponseInterface $message): ?string
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

            return $content === '' ? null : $content;
        } catch (Throwable) {
            return null;
        }
    }
}
