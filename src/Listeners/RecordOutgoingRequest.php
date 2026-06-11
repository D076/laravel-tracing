<?php

namespace D076\Tracing\Listeners;

use D076\Tracing\Context\TraceId;
use D076\Tracing\Services\OutgoingTracingService;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Psr\Http\Message\RequestInterface;

/**
 * Записывает исходящие HTTP-запросы через события клиента Laravel.
 *
 * Покрытие гарантировано фреймворком: ResponseReceived срабатывает на ЛЮБОЙ
 * ответ (включая 4xx/5xx), ConnectionFailed — на обрыв соединения/таймаут
 * (когда ответа нет вовсе). Это закрывает дыру старого Guzzle-middleware,
 * чей `->then(onRejected)` пропускал синхронно брошенные исключения.
 *
 * Длительность считается парой RequestSending → ResponseReceived/ConnectionFailed.
 * Корреляция — по идентичности PSR-объекта запроса: Laravel переиспользует один и
 * тот же экземпляр request между этими событиями, поэтому spl_object_id стабилен
 * и не путает параллельные запросы (Http::pool).
 *
 * Должен быть singleton — иначе $pending не переживёт между диспатчами событий.
 */
final class RecordOutgoingRequest
{
    /** @var array<int, array{start: float, trace_id: string}> */
    private array $pending = [];

    public function __construct(
        private readonly TraceId $traceId,
        private readonly OutgoingTracingService $service,
    ) {
    }

    public function handleRequestSending(RequestSending $event): void
    {
        $this->pending[spl_object_id($event->request->toPsrRequest())] = [
            'start' => microtime(true),
            'trace_id' => $this->traceId->get(),
        ];
    }

    public function handleResponseReceived(ResponseReceived $event): void
    {
        $request = $event->request->toPsrRequest();
        [$traceId, $durationMs] = $this->resolve($request);

        if ($this->service->isIgnored((string) $request->getUri())) {
            return;
        }

        $this->service->persist(
            $traceId,
            $request,
            $event->response->toPsrResponse(),
            null,
            $durationMs,
        );
    }

    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        $request = $event->request->toPsrRequest();
        [$traceId, $durationMs] = $this->resolve($request);

        if ($this->service->isIgnored((string) $request->getUri())) {
            return;
        }

        $this->service->persist(
            $traceId,
            $request,
            null,
            $event->exception,
            $durationMs,
        );
    }

    /**
     * Достаёт сохранённый старт запроса (и снимает его, чтобы не текла память),
     * возвращает trace_id и длительность в мс. Если RequestSending не дошёл
     * (нет записи) — fallback на текущий trace_id и нулевую длительность.
     *
     * @return array{0: string, 1: int}
     */
    private function resolve(RequestInterface $request): array
    {
        $id = spl_object_id($request);
        $entry = $this->pending[$id] ?? null;
        unset($this->pending[$id]);

        return [
            $entry['trace_id'] ?? $this->traceId->get(),
            $entry !== null ? (int) round((microtime(true) - $entry['start']) * 1000) : 0,
        ];
    }
}
