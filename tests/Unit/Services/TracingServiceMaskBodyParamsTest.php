<?php

use D076\Tracing\Services\TracingService;

describe('TracingService::maskBodyParams', function () {
    beforeEach(function () {
        $this->service = new TracingService();
    });

    it('masks a flat key', function () {
        $result = $this->service->maskBodyParams(
            ['password' => 'secret', 'name' => 'John'],
            ['password'],
        );

        expect($result)->toBe(['password' => '[REDACTED]', 'name' => 'John']);
    });

    it('masks a nested key via dot-notation', function () {
        $result = $this->service->maskBodyParams(
            ['user' => ['password' => 'secret', 'email' => 'a@b.c']],
            ['user.password'],
        );

        expect($result)->toBe(['user' => ['password' => '[REDACTED]', 'email' => 'a@b.c']]);
    });

    it('masks a deep dot-path', function () {
        $result = $this->service->maskBodyParams(
            ['a' => ['b' => ['c' => 'secret', 'd' => 'keep']]],
            ['a.b.c'],
        );

        expect($result)->toBe(['a' => ['b' => ['c' => '[REDACTED]', 'd' => 'keep']]]);
    });

    it('leaves data unchanged when the key is absent', function () {
        $data = ['name' => 'John', 'nested' => ['x' => 1]];

        expect($this->service->maskBodyParams($data, ['password', 'nested.password']))->toBe($data);
    });

    it('leaves data unchanged when the masked-keys list is empty', function () {
        $data = ['password' => 'secret'];

        expect($this->service->maskBodyParams($data, []))->toBe($data);
    });

    it('returns null when data is null', function () {
        expect($this->service->maskBodyParams(null, ['password']))->toBeNull();
    });

    it('is case-sensitive', function () {
        $data = ['Password' => 'secret', 'TOKEN' => 'abc'];

        expect($this->service->maskBodyParams($data, ['password', 'token']))->toBe($data);
    });

    it('masks multiple keys in a single call', function () {
        $result = $this->service->maskBodyParams(
            ['password' => 'p', 'token' => 't', 'name' => 'John'],
            ['password', 'token'],
        );

        expect($result)->toBe([
            'password' => '[REDACTED]',
            'token' => '[REDACTED]',
            'name' => 'John',
        ]);
    });
});
