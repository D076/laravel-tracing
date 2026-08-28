<?php

use D076\Tracing\Support\BodyEncoding;
use D076\Tracing\Support\RequestParams;

/**
 * Outgoing records store the URL and the request body exactly as they went on the
 * wire — a string each. Query parameters and form-encoded fields are therefore
 * derived on read, never stored twice, and this is the contract of that derivation.
 *
 * parse_str is used deliberately over a hand-rolled split: incoming records get
 * their query_params/body_params from PHP's own parsing of the same syntax, so
 * anything else would show the two sides of one trace in different shapes.
 */

describe('queryFromUrl', function () {
    it('splits the query string into parameters', function () {
        expect(RequestParams::queryFromUrl('https://api.test/v1/orders?status=new&page=2'))
            ->toBe(['status' => 'new', 'page' => '2']);
    });

    it('returns null when the url carries no query at all', function (string $url) {
        expect(RequestParams::queryFromUrl($url))->toBeNull();
    })->with([
        'no question mark' => 'https://api.test/v1/orders',
        'empty query' => 'https://api.test/v1/orders?',
        'path only' => '/v1/orders',
    ]);

    it('expands bracket syntax the way incoming requests are recorded', function () {
        expect(RequestParams::queryFromUrl('https://api.test/o?filter[city]=msk&filter[from]=2026-01-01&ids[]=1&ids[]=2'))
            ->toBe([
                'filter' => ['city' => 'msk', 'from' => '2026-01-01'],
                'ids' => ['1', '2'],
            ]);
    });

    it('decodes percent-encoded values', function () {
        expect(RequestParams::queryFromUrl('https://api.test/o?city=%D0%9C%D0%BE%D1%81%D0%BA%D0%B2%D0%B0&q=a%20b'))
            ->toBe(['city' => 'Москва', 'q' => 'a b']);
    });

    it('ignores the fragment', function () {
        expect(RequestParams::queryFromUrl('https://api.test/o?page=2#section'))
            ->toBe(['page' => '2']);
    });

    it('keeps a valueless parameter as an empty string', function () {
        expect(RequestParams::queryFromUrl('https://api.test/o?debug&page=2'))
            ->toBe(['debug' => '', 'page' => '2']);
    });
});

describe('formBody', function () {
    it('parses an application/x-www-form-urlencoded body into fields', function () {
        expect(RequestParams::formBody('status=new&page=2'))
            ->toBe(['status' => 'new', 'page' => '2']);
    });

    it('expands bracket syntax', function () {
        expect(RequestParams::formBody('filter%5Bcity%5D=msk&ids%5B%5D=1&ids%5B%5D=2'))
            ->toBe([
                'filter' => ['city' => 'msk'],
                'ids' => ['1', '2'],
            ]);
    });

    it('returns null for a body that holds no fields', function (string $body) {
        expect(RequestParams::formBody($body))->toBeNull();
    })->with([
        'empty' => '',
        'separator only' => '&',
    ]);

    it('drops the truncation marker instead of parsing it as a value', function () {
        $body = 'status=new&comment=very long te' . BodyEncoding::TRUNCATION_MARKER;

        expect(RequestParams::formBody($body))
            ->toBe(['status' => 'new', 'comment' => 'very long te']);
    });
});

describe('isTruncated', function () {
    it('recognizes a body cut by BodyEncoding', function () {
        expect(RequestParams::isTruncated(BodyEncoding::truncateBytes(str_repeat('a', 100), 10)))->toBeTrue();
    });

    it('leaves an intact body alone', function () {
        expect(RequestParams::isTruncated('status=new'))->toBeFalse();
    });
});

describe('isFormUrlEncoded', function () {
    it('accepts the form content type with or without parameters', function (string $contentType) {
        expect(RequestParams::isFormUrlEncoded($contentType))->toBeTrue();
    })->with([
        'bare' => 'application/x-www-form-urlencoded',
        'with charset' => 'application/x-www-form-urlencoded; charset=UTF-8',
        'upper case' => 'APPLICATION/X-WWW-FORM-URLENCODED',
    ]);

    it('rejects anything else', function (?string $contentType) {
        expect(RequestParams::isFormUrlEncoded($contentType))->toBeFalse();
    })->with([
        'json' => 'application/json',
        'multipart' => 'multipart/form-data; boundary=--x',
        'text' => 'text/plain',
        'missing' => null,
    ]);
});

describe('contentTypeFrom', function () {
    it('reads the header whatever case the client sent it in', function (string $name) {
        expect(RequestParams::contentTypeFrom([$name => ['application/json']]))
            ->toBe('application/json');
    })->with([
        'canonical' => 'Content-Type',
        'lower' => 'content-type',
        'shouting' => 'CONTENT-TYPE',
    ]);

    it('reads a header stored as a bare string, as an older record may hold it', function () {
        expect(RequestParams::contentTypeFrom(['Content-Type' => 'application/json']))
            ->toBe('application/json');
    });

    it('returns null when there is no usable header', function (?array $headers) {
        expect(RequestParams::contentTypeFrom($headers))->toBeNull();
    })->with([
        'no headers' => null,
        'empty set' => [[]],
        'other headers only' => [['Accept' => ['application/json']]],
        'header without a value' => [['Content-Type' => []]],
        'value that is not a string' => [['Content-Type' => [['nested']]]],
    ]);
});
