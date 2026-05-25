<?php

namespace D076\Tracing\Jobs;

use D076\Tracing\Services\OutgoingTracingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PersistOutgoingRecord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $data)
    {
    }

    public function handle(OutgoingTracingService $service): void
    {
        $service->write($this->data);
    }
}
