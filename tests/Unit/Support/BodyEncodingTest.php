<?php

use D076\Tracing\Support\BodyEncoding;

function cp1251(string $utf8): string
{
    return mb_convert_encoding($utf8, 'Windows-1251', 'UTF-8');
}

describe('BodyEncoding::toUtf8', function () {
    it('returns a valid UTF-8 body byte-for-byte unchanged', function () {
        $body = json_encode(['city' => 'Москва'], JSON_UNESCAPED_UNICODE);

        expect(BodyEncoding::toUtf8($body, 'application/json'))->toBe($body);
    });

    it('transcodes a body using the charset declared in Content-Type', function () {
        $result = BodyEncoding::toUtf8(cp1251('Москва'), 'text/html; charset=windows-1251');

        expect($result)->toBe('Москва')
            ->and(mb_check_encoding($result, 'UTF-8'))->toBeTrue();
    });

    it('accepts a quoted charset parameter', function () {
        $result = BodyEncoding::toUtf8(cp1251('Пятерочка'), 'application/json; charset="cp1251"');

        expect($result)->toBe('Пятерочка');
    });

    it('detects an undeclared legacy charset', function () {
        $result = BodyEncoding::toUtf8(cp1251('Пятерочка'), null);

        expect($result)->toBe('Пятерочка')
            ->and(mb_check_encoding($result, 'UTF-8'))->toBeTrue();
    });

    it('falls back to detection when the declared charset label is unknown', function () {
        $result = BodyEncoding::toUtf8(cp1251('Москва'), 'text/plain; charset=bogus-charset-42');

        expect($result)->toBe('Москва');
    });

    it('replaces an undecodable binary body with a byte-count marker', function () {
        // 0x98 is a continuation byte with no lead (invalid UTF-8) and is
        // undefined in Windows-1251, so strict detection rejects it too.
        $binary = "\x98\x98\x98\x98\x98";

        $result = BodyEncoding::toUtf8($binary, 'application/octet-stream');

        expect($result)->toBe('[non-UTF-8 body, 5 bytes]')
            ->and(mb_check_encoding($result, 'UTF-8'))->toBeTrue();
    });

    it('cleanForStorage substitutes invalid UTF-8 in nested values and keys', function () {
        $payload = [
            'city' => cp1251('Москва'),                 // bad bytes in a value
            'nested' => ['note' => "ok\xff\xfe"],       // bad bytes deeper
            cp1251('поле') => 'plain',                  // bad bytes in a key
        ];

        $clean = BodyEncoding::cleanForStorage($payload);

        expect(mb_check_encoding(json_encode($clean), 'UTF-8'))->toBeTrue()
            ->and($clean)->toHaveKey('city')
            ->and($clean['nested']['note'])->toStartWith('ok');
    });

    it('cleanForStorage leaves a clean payload structurally intact', function () {
        $payload = ['a' => 'x', 'b' => 1, 'c' => null, 'd' => ['e' => ['мир']]];

        expect(BodyEncoding::cleanForStorage($payload))->toBe($payload);
    });

    it('always returns valid UTF-8 regardless of input', function () {
        foreach ([cp1251('тест'), "\xff\xfe\x98", 'plain ascii', 'Москва'] as $input) {
            expect(mb_check_encoding(BodyEncoding::toUtf8($input), 'UTF-8'))->toBeTrue();
        }
    });
});
