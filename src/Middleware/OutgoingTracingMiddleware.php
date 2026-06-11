<?php

namespace D076\Tracing\Middleware;

use D076\Tracing\Context\TraceId;
use D076\Tracing\Services\OutgoingTracingService;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle handler-stack middleware, регистрируется через Http::globalMiddleware().
 *
 * Две задачи:
 *  1. Инъекция заголовка X-Trace-Id (мутация запроса возможна только в middleware).
 *  2. Узкий fallback записи для случая, который НЕ покрывают события клиента.
 *
 * Основная запись вынесена в события HTTP-клиента (ResponseReceived /
 * ConnectionFailed) — см. {@see \D076\Tracing\Listeners\RecordOutgoingRequest}.
 * Единственное исключение: когда вызывающий код включает guzzle-опцию
 * `http_errors => true`, ответ 4xx/5xx превращается в отклонённый промис, который
 * Laravel маршалит БЕЗ диспатча события. Этот middleware стоит ВНУТРИ guzzle-
 * middleware `http_errors`, поэтому всё ещё видит выполненный ответ и пишет его
 * сам. Остальное (включая 2xx при `http_errors => true` и обрывы соединения)
 * по-прежнему идёт через события — двойной записи не возникает.
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
            $ignored = $this->service->isIgnored((string) $request->getUri());

            if (config('tracing.outgoing.propagate_trace_id', false) && !$ignored) {
                $request = $request->withHeader('X-Trace-Id', $this->traceId->get());
            }

            // Быстрый путь: запись делают события. Перехватываем ответ здесь только
            // когда включён http_errors — иначе 4xx/5xx уйдёт в reject мимо событий.
            if ($ignored || empty($options['http_errors'])) {
                return $handler($request, $options);
            }

            $traceId = $this->traceId->get();
            $start = microtime(true);

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($traceId, $request, $start): ResponseInterface {
                    // 2xx/3xx по-прежнему долетают до события ResponseReceived — их не трогаем.
                    // Reject'нутся (и потеряют событие) только 4xx/5xx — их и пишем.
                    if ($response->getStatusCode() >= 400) {
                        $this->service->persist(
                            $traceId,
                            $request,
                            $response,
                            null,
                            (int) round((microtime(true) - $start) * 1000),
                        );
                    }

                    return $response;
                },
            );
        };
    }
}
