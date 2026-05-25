<?php

use D076\Tracing\Services\TracingService;

describe('TracingService::maskHeaders', function () {
    beforeEach(function () {
        $this->service = new TracingService();
    });

    it('masks a header regardless of the header key case', function () {
        $result = $this->service->maskHeaders(
            ['Authorization' => ['Bearer abc'], 'authorization' => ['Bearer xyz']],
            ['authorization'],
        );

        expect($result)->toBe([
            'Authorization' => ['[REDACTED]'],
            'authorization' => ['[REDACTED]'],
        ]);
    });

    it('masks regardless of the masked-name case', function () {
        $result = $this->service->maskHeaders(
            ['authorization' => ['Bearer abc']],
            ['AUTHORIZATION'],
        );

        expect($result)->toBe(['authorization' => ['[REDACTED]']]);
    });

    it('leaves non-masked headers untouched', function () {
        $headers = [
            'Content-Type' => ['application/json'],
            'Accept' => ['application/json', 'text/html'],
        ];

        expect($this->service->maskHeaders($headers, ['authorization']))->toBe($headers);
    });

    it('replaces all values of a masked header with a single redacted entry', function () {
        $result = $this->service->maskHeaders(
            ['set-cookie' => ['a=1', 'b=2']],
            ['set-cookie'],
        );

        expect($result)->toBe(['set-cookie' => ['[REDACTED]']]);
    });

    it('masks nothing when the masked-names list is empty', function () {
        $headers = ['authorization' => ['Bearer abc']];

        expect($this->service->maskHeaders($headers, []))->toBe($headers);
    });

    it('preserves header keys and order', function () {
        $result = $this->service->maskHeaders(
            ['X-First' => ['1'], 'Authorization' => ['secret'], 'X-Last' => ['2']],
            ['authorization'],
        );

        expect(array_keys($result))->toBe(['X-First', 'Authorization', 'X-Last'])
            ->and($result['Authorization'])->toBe(['[REDACTED]']);
    });
});
