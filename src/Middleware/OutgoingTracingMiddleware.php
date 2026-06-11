<?php

namespace D076\Tracing\Middleware;

use D076\Tracing\Context\TraceId;
use D076\Tracing\Services\OutgoingTracingService;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle handler-stack middleware, регистрируется через Http::globalMiddleware().
 *
 * Единственная задача — инъекция заголовка X-Trace-Id в исходящий запрос
 * (мутация запроса возможна только на уровне middleware, не через события).
 *
 * Сама ЗАПИСЬ исходящих запросов вынесена в события HTTP-клиента
 * (RequestSending / ResponseReceived / ConnectionFailed) — см.
 * {@see \D076\Tracing\Listeners\RecordOutgoingRequest}. События срабатывают
 * на ЛЮБОЙ ответ (вкл. 4xx/5xx) и на обрыв соединения/таймаут, тогда как
 * promise-обёртка middleware пропускала синхронные исключения.
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
            if (
                config('tracing.outgoing.propagate_trace_id', false)
                && !$this->service->isIgnored((string) $request->getUri())
            ) {
                $request = $request->withHeader('X-Trace-Id', $this->traceId->get());
            }

            return $handler($request, $options);
        };
    }
}
