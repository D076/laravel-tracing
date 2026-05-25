<?php

use D076\Tracing\Services\OutgoingTracingService;

function invokeMaskJsonBody(OutgoingTracingService $service, ?string $body, array $maskedKeys): ?string
{
    return (new ReflectionMethod($service, 'maskJsonBody'))->invoke($service, $body, $maskedKeys);
}

describe('OutgoingTracingService::maskJsonBody', function () {
    beforeEach(function () {
        $this->service = new OutgoingTracingService();
    });

    it('masks sensitive keys before truncating', function () {
        config()->set('tracing.outgoing.max_body_size', 40);

        $body = json_encode(['password' => 'supersecretvalue', 'data' => str_repeat('x', 100)]);

        $result = invokeMaskJsonBody($this->service, $body, ['password']);

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('supersecret')
            ->and($result)->toEndWith('...[truncated]');
    });

    it('still truncates when the masked-keys list is empty', function () {
        config()->set('tracing.outgoing.max_body_size', 20);

        $body = json_encode(['data' => str_repeat('x', 100)]);

        $result = invokeMaskJsonBody($this->service, $body, []);

        expect($result)->toEndWith('...[truncated]')
            ->and($result)->toBe(substr($body, 0, 20) . '...[truncated]');
    });

    it('returns a non-JSON body unchanged and does not mask it', function () {
        config()->set('tracing.outgoing.max_body_size', 10000);

        $body = 'password=secret&user=john';

        expect(invokeMaskJsonBody($this->service, $body, ['password']))->toBe($body);
    });

    it('truncates an invalid-JSON body without masking', function () {
        config()->set('tracing.outgoing.max_body_size', 10);

        $body = 'password=' . str_repeat('x', 100);

        $result = invokeMaskJsonBody($this->service, $body, ['password']);

        expect($result)->toStartWith('password=')
            ->and($result)->toEndWith('...[truncated]')
            ->and($result)->toBe(substr($body, 0, 10) . '...[truncated]');
    });

    it('returns null when the body is null', function () {
        expect(invokeMaskJsonBody($this->service, null, ['password']))->toBeNull();
    });
});
