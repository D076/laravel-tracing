<?php

namespace D076\Tracing\Context;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

final class TraceId
{
    private const CONTEXT_KEY = 'tracing.trace_id';

    private ?string $current = null;

    public function get(): string
    {
        // Context — источник истины: Laravel сам сериализует его при dispatch
        // и восстанавливает в воркере, так что джобы/события/чейны автоматически
        // наследуют trace_id родителя. Singleton-кеш использовать нельзя — он бы
        // удерживал значение между джобами в одном воркере.
        if (Context::has(self::CONTEXT_KEY)) {
            return $this->current = (string) Context::get(self::CONTEXT_KEY);
        }

        if ($this->current === null) {
            $this->current = (string) Str::uuid7();
            Context::add(self::CONTEXT_KEY, $this->current);
        }

        return $this->current;
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
