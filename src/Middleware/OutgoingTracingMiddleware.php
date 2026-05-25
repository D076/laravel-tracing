<?php

namespace D076\Tracing\Middleware;

use D076\Tracing\Context\TraceId;
use D076\Tracing\Services\OutgoingTracingService;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle handler-stack middleware, регистрируется через Http::globalMiddleware().
 * Оборачивает каждый исходящий запрос фасада Http: фиксирует URL, статус, время,
 * заголовки и тела, привязывает к входящему запросу через trace_id.
 */
final class OutgoingTracingMiddleware
{
    public function __construct(
        private readonly TraceId $traceId,
        private readonly OutgoingTracingService $service,
    ) {
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            if (!config('tracing.outgoing.enabled', true) || $this->isIgnored($request)) {
                return $handler($request, $options);
            }

            $traceId = $this->traceId->get();
            $start = microtime(true);

            if (config('tracing.outgoing.propagate_trace_id', false)) {
                $request = $request->withHeader('X-Trace-Id', $traceId);
            }

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($request, $traceId, $start): ResponseInterface {
                    $this->service->persist(
                        $traceId,
                        $request,
                        $response,
                        null,
                        (int) round((microtime(true) - $start) * 1000),
                    );

                    return $response;
                },
                function (mixed $reason) use ($request, $traceId, $start): mixed {
                    $response = ($reason instanceof RequestException && $reason->hasResponse())
                                    ? $reason->getResponse()
                                    : null;
                    $exception = $reason instanceof Throwable ? $reason : null;

                    $this->service->persist(
                        $traceId,
                        $request,
                        $response,
                        $exception,
                        (int) round((microtime(true) - $start) * 1000),
                    );

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function isIgnored(RequestInterface $request): bool
    {
        $url = (string) $request->getUri();

        foreach (config('tracing.outgoing.ignore_urls', []) as $pattern) {
            if (fnmatch($pattern, $url)) {
                return true;
            }
        }

        return false;
    }
}
