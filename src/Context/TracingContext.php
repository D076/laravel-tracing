<?php

namespace D076\Tracing\Context;

use Throwable;

/**
 * Singleton, хранящий состояние одного HTTP-запроса на время его жизненного цикла.
 * Заполняется последовательно: handle() → respondUsing hook → terminate().
 */
final class TracingContext
{
    public ?string    $traceId = null;

    public ?string    $method = null;

    public ?string    $url = null;

    public ?string    $routeName = null;

    public ?string    $routePath = null;

    public ?array     $requestHeaders = null;

    public ?array     $queryParams = null;

    public ?array     $bodyParams = null;

    public ?string    $ipAddress = null;

    public ?string    $userAgent = null;

    public ?Throwable $exception = null;  // заполняется через respondUsing hook

    public ?string    $authenticatableId = null;

    public ?string    $authenticatableType = null;

    public ?int       $durationMs = null;

    public bool       $shouldRecord = true;

    public function reset(): void
    {
        $this->traceId = null;
        $this->method = null;
        $this->url = null;
        $this->routeName = null;
        $this->routePath = null;
        $this->requestHeaders = null;
        $this->queryParams = null;
        $this->bodyParams = null;
        $this->ipAddress = null;
        $this->userAgent = null;
        $this->exception = null;
        $this->authenticatableId = null;
        $this->authenticatableType = null;
        $this->durationMs = null;
        $this->shouldRecord = true;
    }
}
