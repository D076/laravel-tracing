<?php

namespace D076\Tracing\Context;

use Illuminate\Support\Str;

final class TraceId
{
    private ?string $current = null;

    public function get(): string
    {
        return $this->current ??= (string) Str::uuid7();
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
