<?php

use D076\Tracing\Services\OutgoingTracingService;

function invokeMaskFormBody(OutgoingTracingService $service, ?string $body, array $maskedKeys): ?string
{
    return (new ReflectionMethod($service, 'maskFormBody'))->invoke($service, $body, $maskedKeys);
}

describe('OutgoingTracingService::maskFormBody', function () {
    beforeEach(function () {
        $this->service = new OutgoingTracingService();
        config()->set('tracing.outgoing.max_body_size', 10000);
    });

    it('masks a flat sensitive key', function () {
        $result = invokeMaskFormBody($this->service, 'password=secret&user=john', ['password']);

        expect($result)->toContain('user=john')
            ->and($result)->not->toContain('secret')
            ->and(urldecode($result))->toContain('password=[REDACTED]');
    });

    it('masks several keys at once and preserves the rest', function () {
        $result = invokeMaskFormBody(
            $this->service,
            'password=secret&user=john&token=abc',
            ['password', 'token'],
        );

        $decoded = urldecode($result);

        expect($decoded)->toContain('password=[REDACTED]')
            ->and($decoded)->toContain('token=[REDACTED]')
            ->and($decoded)->toContain('user=john')
            ->and($result)->not->toContain('secret')
            ->and($result)->not->toContain('abc');
    });

    it('masks nested fields via bracket syntax with dot-notation keys', function () {
        // Standard PHP form encoding for nested data: user[password]=secret
        $body = 'user%5Bpassword%5D=secret&user%5Bname%5D=john';

        $result = invokeMaskFormBody($this->service, $body, ['user.password']);

        $decoded = urldecode($result);

        expect($decoded)->toContain('user[password]=[REDACTED]')
            ->and($decoded)->toContain('user[name]=john')
            ->and($result)->not->toContain('secret');
    });

    it('masks before truncating', function () {
        config()->set('tracing.outgoing.max_body_size', 60);

        $body = 'password=supersecretvalue&data=' . str_repeat('x', 200);

        $result = invokeMaskFormBody($this->service, $body, ['password']);

        expect($result)->toContain('%5BREDACTED%5D')
            ->and($result)->not->toContain('supersecret')
            ->and($result)->toEndWith('...[truncated]');
    });

    it('still truncates when the masked-keys list is empty', function () {
        config()->set('tracing.outgoing.max_body_size', 20);

        $body = 'a=' . str_repeat('x', 100);

        $result = invokeMaskFormBody($this->service, $body, []);

        expect($result)->toBe(substr($body, 0, 20) . '...[truncated]');
    });

    it('leaves a body unchanged when no masked keys match', function () {
        $body = 'user=john&role=admin';

        $result = invokeMaskFormBody($this->service, $body, ['password', 'token']);

        // Round-trip through parse_str + http_build_query should preserve the data,
        // even if the exact byte representation differs (encoding of safe chars).
        parse_str($result, $parsed);

        expect($parsed)->toBe(['user' => 'john', 'role' => 'admin']);
    });

    it('returns null when the body is null', function () {
        expect(invokeMaskFormBody($this->service, null, ['password']))->toBeNull();
    });
});
