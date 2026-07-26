<?php

use D076\Tracing\Support\BodyEncoding;

/**
 * The contract of BodyEncoding, stated over CORPORA and RANDOM input rather than
 * hand-picked bytes.
 *
 * Three rounds of tests here passed for the wrong reason: each used an input that
 * happened to contain 0x98 — the one byte Windows-1251 rejects — so they proved
 * the detector rejected that byte, not that anything worked. Input drawn at
 * random cannot be tuned that way: if a guarantee does not hold, a sample finds it.
 *
 * The contract itself is deliberately small. Encoding is only ever determined
 * from what the sender DECLARED; nothing is inferred from the bytes:
 *
 *   valid UTF-8                       -> stored unchanged
 *   declared charset that converts    -> converted
 *   anything else                     -> marker (body) / left for U+FFFD (value)
 *
 * The compromise is explicit and tested below: legacy text whose sender did not
 * declare a charset is NOT recovered. That is the price of never fabricating
 * text, which for an audit trail is the worse failure — a body of U+FFFD is
 * visibly broken, while invented Cyrillic is indistinguishable from the truth.
 */

function cp1251Text(string $utf8): string
{
    return mb_convert_encoding($utf8, 'Windows-1251', 'UTF-8');
}

/** Realistic legacy text: names, addresses, invoice lines, punctuation, digits. */
function legacyTextCorpus(): array
{
    return [
        'Москва',
        'Санкт-Петербург',
        'Накладная №5',
        'Накладная №5 от 12.03.2026, сумма 1 500,00 руб.',
        'ООО «Ромашка», ИНН 7701234567',
        'ул. Тверская, д. 7, стр. 2, офис 415',
        'Иванов Иван Иванович',
        'Заказ принят в обработку',
        'Ошибка: недостаточно средств на счёте',
        'Пятерочка',
        'Ёлки-палки, ежедневно с 9:00 до 21:00',
        'Товар: Кофе молотый «Арабика» 250 г — 2 шт.',
        'Статус: отгружено; склад: №3',
        'Комментарий покупателя: перезвоните, пожалуйста, после 18',
        'Адрес доставки — Казань, пр. Победы 100, кв. 5',
    ];
}

/**
 * Realistic binary: compressed streams, image and document headers, raw digests,
 * and uniformly random bytes. None of it is text in any charset.
 */
function binaryCorpus(): array
{
    $cases = [
        'gzip' => gzencode(str_repeat('Привет, мир! Заказ №5. ', 40)),
        'zlib' => gzcompress(str_repeat('Накладная от 12.03.2026. ', 40)),
        'png' => "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR" . random_bytes(256),
        'jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . random_bytes(256),
        'pdf' => "%PDF-1.4\n%\xE2\xE3\xCF\xD3\nstream\n" . random_bytes(256) . "\nendstream",
        'sha256' => hash('sha256', 'payload', true),
        'hmac' => hash_hmac('sha256', 'payload', 'secret', true),
        'md5' => hash('md5', 'signature', true),
    ];

    // Uniformly random blobs across the sizes a signature, a key or a chunk of an
    // encrypted payload actually takes. High-byte-only blobs are included because
    // they carry neither NUL nor control bytes — the case every byte-sniffing
    // heuristic missed.
    foreach ([16, 24, 32, 64, 128, 512, 4096] as $length) {
        for ($i = 0; $i < 25; $i++) {
            $cases["random-{$length}-{$i}"] = random_bytes($length);

            $high = '';
            for ($b = 0; $b < $length; $b++) {
                $high .= chr(random_int(0x80, 0xFF));
            }
            $cases["high-{$length}-{$i}"] = $high;
        }
    }

    return array_filter($cases, fn ($b) => !mb_check_encoding($b, 'UTF-8'));
}

describe('BodyEncoding contract: bodies', function () {
    it('stores valid UTF-8 byte for byte, whatever the media type', function () {
        foreach (legacyTextCorpus() as $text) {
            foreach ([null, 'text/plain', 'application/json', 'image/jpeg', 'application/octet-stream'] as $type) {
                expect(BodyEncoding::toUtf8($text, $type))->toBe($text);
            }
        }
    });

    it('converts every legacy text whose charset the sender declared', function () {
        foreach (legacyTextCorpus() as $text) {
            expect(BodyEncoding::toUtf8(cp1251Text($text), 'text/plain; charset=windows-1251'))->toBe($text)
                ->and(BodyEncoding::toUtf8(cp1251Text($text), 'application/json; charset="cp1251"'))->toBe($text)
                // A declared charset is believed regardless of the media type:
                // it is a statement by the sender, not a guess about the bytes.
                ->and(BodyEncoding::toUtf8(cp1251Text($text), 'application/octet-stream; charset=windows-1251'))->toBe($text);
        }
    });

    it('converts other declared charsets, not just the Cyrillic ones', function () {
        foreach (['café crème', 'Grüße aus München', 'año español'] as $text) {
            $latin1 = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');

            expect(BodyEncoding::toUtf8($latin1, 'text/plain; charset=iso-8859-1'))->toBe($text);
        }

        $iso5 = mb_convert_encoding('Приход товара', 'ISO-8859-5', 'UTF-8');

        expect(BodyEncoding::toUtf8($iso5, 'text/plain; charset=iso-8859-5'))->toBe('Приход товара');
    });

    it('never turns binary into text when nothing was declared', function () {
        // Every media type here is charset-free, which is how a real server sends
        // a blob. What a declared charset does is asserted separately below.
        $types = [null, 'text/plain', 'application/json', 'image/jpeg', 'application/octet-stream',
            'multipart/form-data; boundary=xyz'];
        $fabricated = [];

        foreach (binaryCorpus() as $label => $binary) {
            foreach ($types as $type) {
                $result = BodyEncoding::toUtf8($binary, $type);

                if (!str_starts_with($result, '[non-UTF-8 body, ')) {
                    $fabricated[] = "{$label} as " . ($type ?? 'no content-type') . ' -> ' . mb_substr($result, 0, 24);
                }
            }
        }

        expect($fabricated)->toBe([]);
    });

    it('markers undeclared legacy text instead of guessing at it', function () {
        // The accepted compromise, asserted rather than left implicit: without a
        // declaration we do not know cp1251 from a blob, and we do not pretend to.
        foreach (legacyTextCorpus() as $text) {
            expect(BodyEncoding::toUtf8(cp1251Text($text), null))->toStartWith('[non-UTF-8 body, ')
                ->and(BodyEncoding::toUtf8(cp1251Text($text), 'text/plain'))->toStartWith('[non-UTF-8 body, ');
        }
    });

    it('believes a declared charset even if the payload turns out to be a blob', function () {
        // The documented edge of "never infer": a single-byte charset converts any
        // byte sequence, so a sender that mislabels binary as text gets garbage
        // text stored. Accepted — a charset parameter is a statement about the
        // payload, and servers put one on text types, not on images. The
        // alternative is a media-type blocklist, i.e. guessing again.
        $blob = hash('sha256', 'payload', true);

        expect(BodyEncoding::toUtf8($blob, 'text/plain; charset=windows-1251'))
            ->not->toStartWith('[non-UTF-8 body, ');
    });

    it('markers a body whose declared charset does not apply', function () {
        expect(BodyEncoding::toUtf8(cp1251Text('Москва'), 'text/plain; charset=bogus-charset-42'))
            ->toStartWith('[non-UTF-8 body, ');
    });

    it('always returns valid UTF-8', function () {
        foreach ([...binaryCorpus(), ...array_map('cp1251Text', legacyTextCorpus()), ...legacyTextCorpus()] as $input) {
            foreach ([null, 'text/plain', 'text/plain; charset=windows-1251'] as $type) {
                expect(mb_check_encoding(BodyEncoding::toUtf8($input, $type), 'UTF-8'))->toBeTrue();
            }
        }
    });
});

describe('BodyEncoding contract: parameter values', function () {
    it('converts every legacy value whose charset the client declared', function () {
        foreach (legacyTextCorpus() as $text) {
            expect(BodyEncoding::toUtf8Deep(['v' => cp1251Text($text)], 'windows-1251')['v'])->toBe($text);
        }
    });

    it('never invents text for a binary value', function () {
        $fabricated = [];

        foreach (binaryCorpus() as $label => $binary) {
            foreach ([null, 'windows-1251'] as $charset) {
                $result = BodyEncoding::toUtf8Deep(['v' => $binary], $charset)['v'];

                // With a declared charset a blob may well "convert"; what must not
                // happen is a silent conversion when nothing was declared.
                if ($charset === null && $result !== $binary) {
                    $fabricated[] = "{$label} -> " . mb_substr($result, 0, 24);
                }
            }
        }

        expect($fabricated)->toBe([]);
    });

    it('leaves an undeclared legacy value for the U+FFFD backstop', function () {
        foreach (legacyTextCorpus() as $text) {
            $cp1251 = cp1251Text($text);
            $result = BodyEncoding::toUtf8Deep(['v' => $cp1251]);

            expect($result['v'])->toBe($cp1251)
                ->and(mb_check_encoding(json_encode(BodyEncoding::cleanForStorage($result)), 'UTF-8'))->toBeTrue();
        }
    });

    it('does not render Latin-1 text as Cyrillic when no charset is declared', function () {
        foreach (['café crème', 'Grüße aus München', 'año español', 'Ærøskøbing'] as $text) {
            $latin1 = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');

            expect(BodyEncoding::toUtf8Deep(['v' => $latin1])['v'])->toBe($latin1, "Latin-1 {$text}");
        }
    });

    it('substitutes invalid UTF-8 in nested values and keys for storage', function () {
        $payload = [
            'city' => cp1251Text('Москва'),
            'nested' => ['note' => "ok\xff\xfe"],
            cp1251Text('поле') => 'plain',
        ];

        $clean = BodyEncoding::cleanForStorage($payload);

        expect(mb_check_encoding(json_encode($clean), 'UTF-8'))->toBeTrue()
            ->and($clean)->toHaveKey('city')
            ->and($clean['nested']['note'])->toStartWith('ok');
    });

    it('leaves a clean payload structurally intact', function () {
        $payload = ['a' => 'x', 'b' => 1, 'c' => null, 'd' => ['e' => ['мир']]];

        expect(BodyEncoding::cleanForStorage($payload))->toBe($payload);
    });

    it('normalizes keys and nested values, not just top-level strings', function () {
        $data = [
            cp1251Text('поле') => cp1251Text('значение'),
            'nested' => ['note' => cp1251Text('Примечание'), 'n' => 42, 'null' => null],
        ];

        $result = BodyEncoding::toUtf8Deep($data, 'windows-1251');

        expect($result)->toHaveKey('поле')
            ->and($result['поле'])->toBe('значение')
            ->and($result['nested']['note'])->toBe('Примечание')
            ->and($result['nested']['n'])->toBe(42)
            ->and($result['nested']['null'])->toBeNull();
    });
});
