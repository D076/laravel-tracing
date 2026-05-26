<?php

use D076\Tracing\Services\OutgoingTracingService;

function invokeMaskBody(
    OutgoingTracingService $service,
    ?string $body,
    array $maskedKeys,
    ?string $contentType,
): ?string {
    return (new ReflectionMethod($service, 'maskBody'))
        ->invoke($service, $body, $maskedKeys, $contentType);
}

describe('OutgoingTracingService::maskBody (dispatcher)', function () {
    beforeEach(function () {
        $this->service = new OutgoingTracingService();
        config()->set('tracing.outgoing.max_body_size', 10000);
    });

    it('dispatches to JSON masking when Content-Type is application/json', function () {
        $body = json_encode(['password' => 'secret', 'user' => 'john']);

        $result = invokeMaskBody($this->service, $body, ['password'], 'application/json');

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('secret')
            ->and($result)->toContain('john');
    });

    it('dispatches to form masking when Content-Type is application/x-www-form-urlencoded', function () {
        $result = invokeMaskBody(
            $this->service,
            'password=secret&user=john',
            ['password'],
            'application/x-www-form-urlencoded',
        );

        // Form masking uses http_build_query → brackets are URL-encoded.
        expect($result)->toContain('%5BREDACTED%5D')
            ->and($result)->not->toContain('secret')
            ->and($result)->toContain('user=john');
    });

    it('recognizes form Content-Type with charset parameter', function () {
        $result = invokeMaskBody(
            $this->service,
            'password=secret',
            ['password'],
            'application/x-www-form-urlencoded; charset=utf-8',
        );

        expect($result)->toContain('%5BREDACTED%5D')
            ->and($result)->not->toContain('secret');
    });

    it('falls back to JSON masking when Content-Type is missing (backward compat)', function () {
        $body = json_encode(['password' => 'secret']);

        $result = invokeMaskBody($this->service, $body, ['password'], null);

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('secret');
    });

    it('does not mask form bodies without a form Content-Type', function () {
        // A form-shaped body but no Content-Type / non-form Content-Type
        // falls into the JSON branch, which leaves it as-is (not valid JSON).
        $body = 'password=secret&user=john';

        $result = invokeMaskBody($this->service, $body, ['password'], 'text/plain');

        // Body is left unchanged because we can't safely parse it without
        // knowing the format. Only truncation applies.
        expect($result)->toBe($body);
    });

    it('returns null when the body is null', function () {
        expect(invokeMaskBody($this->service, null, ['password'], 'application/json'))->toBeNull();
        expect(invokeMaskBody($this->service, null, ['password'], 'application/x-www-form-urlencoded'))->toBeNull();
        expect(invokeMaskBody($this->service, null, ['password'], null))->toBeNull();
    });
});
