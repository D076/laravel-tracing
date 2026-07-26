<?php

use D076\Tracing\Services\OutgoingTracingService;

function invokeMaskJsonBody(OutgoingTracingService $service, string $body, array $maskedKeys): string
{
    return (new ReflectionMethod($service, 'maskJsonBody'))->invoke($service, $body, $maskedKeys);
}

describe('OutgoingTracingService::maskJsonBody', function () {
    beforeEach(function () {
        $this->service = new OutgoingTracingService(new \D076\Tracing\Context\Tags());
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

    it('does not split a multibyte UTF-8 character when truncating', function () {
        // Reproduces the pgsql "invalid byte sequence for encoding UTF8: 0xd0 ..." crash:
        // truncation must never leave a dangling lead byte of a multibyte character.
        // "Москва" — each Cyrillic letter is 2 bytes. Cutting at an odd offset splits one.
        config()->set('tracing.outgoing.max_body_size', 15);

        $body = json_encode(['city' => str_repeat('Москва', 50)], JSON_UNESCAPED_UNICODE);

        $result = invokeMaskJsonBody($this->service, $body, []);

        expect($result)->toEndWith('...[truncated]')
            ->and(mb_check_encoding($result, 'UTF-8'))->toBeTrue();
    });

    it('budgets max_body_size in bytes and never exceeds it', function () {
        config()->set('tracing.outgoing.max_body_size', 101);

        // 101 is deliberately odd: a 2-byte character cannot end exactly on it,
        // so the cut must fall back to 100 rather than overshoot or split.
        $kept = fn (string $s) => str_replace('...[truncated]', '', (string) invokeMaskJsonBody($this->service, $s, []));

        expect(strlen($kept(str_repeat('я', 200))))->toBe(100)
            ->and(strlen($kept(str_repeat('a', 200))))->toBe(101)
            ->and(mb_check_encoding($kept(str_repeat('я', 200)), 'UTF-8'))->toBeTrue();
    });

    // The null case is guarded one level up, in maskBody(), so that is where it
    // is asserted — maskJsonBody() itself is only ever reached with a string.
    it('returns null when the body is null, before reaching maskJsonBody', function () {
        $result = (new ReflectionMethod($this->service, 'maskBody'))
            ->invoke($this->service, null, ['password'], 'application/json');

        expect($result)->toBeNull();
    });
});
