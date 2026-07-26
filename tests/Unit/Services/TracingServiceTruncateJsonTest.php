<?php

use D076\Tracing\Context\Tags;
use D076\Tracing\Services\TracingService;

function invokeTruncateJson(TracingService $service, ?array $data): ?array
{
    return (new ReflectionMethod($service, 'truncateJson'))->invoke($service, $data);
}

describe('TracingService::truncateJson', function () {
    beforeEach(function () {
        $this->service = new TracingService(new Tags());
    });

    it('measures a Cyrillic payload by its real bytes, not by its \\uXXXX escapes', function () {
        // Regression: the size was measured on json_encode() WITHOUT
        // JSON_UNESCAPED_UNICODE, so every Cyrillic letter counted as its 6-byte
        // \uXXXX escape and a payload was discarded at a third of the budget it
        // actually costs. pgsql/mysql normalize the escapes away on write, so the
        // escaped form was never what got stored.
        config()->set('tracing.max_body_size', 1000);

        // 400 Cyrillic characters = 800 bytes stored, but 2400 bytes escaped.
        $data = ['note' => str_repeat('я', 400)];

        expect(invokeTruncateJson($this->service, $data))->toBe($data);
    });

    it('replaces an oversized payload with a truncation summary in bytes', function () {
        config()->set('tracing.max_body_size', 50);

        $result = invokeTruncateJson($this->service, ['note' => str_repeat('я', 200)]);

        expect($result['_truncated'])->toBeTrue()
            ->and($result['_original_size'])->toBeGreaterThan(400)   // 2 bytes per character
            ->and($result['_original_size'])->toBeLessThan(500);     // not 6 bytes per escape
    });

    it('returns null for null', function () {
        expect(invokeTruncateJson($this->service, null))->toBeNull();
    });

    it('does not throw on a body param carrying invalid UTF-8', function () {
        config()->set('tracing.max_body_size', 10000);

        $result = invokeTruncateJson($this->service, ['blob' => "\xff\xfe"]);

        expect($result)->toHaveKey('blob');
    });
});
