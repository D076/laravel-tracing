<?php

namespace D076\Tracing\Support;

use ValueError;

/**
 * Guarantees that everything written to a trace record is valid UTF-8.
 *
 * A strict backend — Postgres with a UTF8 database — aborts the whole INSERT on
 * any byte sequence that is not valid UTF-8, so a single stray byte used to cost
 * the entire record. That, and not charset recovery, is the problem this solves.
 *
 * The rule is deliberately small, and it never infers an encoding from the bytes:
 *
 *   valid UTF-8                    -> unchanged
 *   charset declared, and converts -> converted
 *   anything else                  -> marker (body) / left as-is (parameter value)
 *
 * Charset DETECTION was tried and removed. It cannot separate text from binary:
 * Windows-1251 leaves exactly one byte of 256 undefined, so a strict detector
 * accepts a JPEG, a gzip stream or a raw HMAC and transcodes it into fluent-
 * looking Cyrillic that never existed. Successive attempts to fence that off by
 * media type, NUL bytes and control-character ratios each leaked somewhere else
 * — high-byte-only blobs carry no control bytes at all. In an audit trail,
 * fabricated text is worse than visibly missing text, so the guessing is gone.
 *
 * The price: legacy text from a sender that declares no charset is not
 * recovered. It becomes a marker, or U+FFFD inside a parameter. In practice a
 * sender that knows it is not UTF-8 says so in Content-Type.
 *
 * Must run BEFORE masking and truncation: json_decode/parse_str only understand
 * UTF-8, and truncation assumes valid UTF-8 input (see the mb_strcut call sites).
 */
final class BodyEncoding
{
    public static function toUtf8(string $content, ?string $contentType = null): string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        return self::convertDeclared($content, self::charsetFromContentType($contentType))
            ?? '[non-UTF-8 body, ' . strlen($content) . ' bytes]';
    }

    /**
     * Normalize every string in a decoded parameter set (form fields, query
     * parameters, headers) to UTF-8, keys included.
     *
     * Counterpart of {@see toUtf8()} for data that reaches us already parsed into
     * an array, where there is no single body to convert: a legacy client posting
     * `application/x-www-form-urlencoded; charset=windows-1251` puts its bytes
     * inside individual values.
     *
     * Note: two distinct byte keys can convert to the same string, in which case
     * the later one wins and the earlier is dropped. Contrived enough that
     * de-duplicating would cost more clarity than it buys, but it is silent.
     *
     * @param  array<mixed>|null  $data
     * @param  string|null  $charset  Charset declared by the client, if any.
     * @return array<mixed>|null
     */
    public static function toUtf8Deep(?array $data, ?string $charset = null): ?array
    {
        if ($data === null) {
            return null;
        }

        $result = [];

        foreach ($data as $key => $value) {
            $key = is_string($key) ? self::toUtf8Value($key, $charset) : $key;

            $result[$key] = match (true) {
                is_array($value) => self::toUtf8Deep($value, $charset),
                is_string($value) => self::toUtf8Value($value, $charset),
                default => $value,
            };
        }

        return $result;
    }

    /**
     * A single already-parsed value (parameter, header, user agent).
     *
     * Undecodable values are returned untouched rather than markered: a parameter
     * is not a body, and {@see cleanForStorage()} already guarantees the write
     * succeeds by substituting U+FFFD.
     */
    public static function toUtf8Value(string $value, ?string $charset = null): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return self::convertDeclared($value, $charset) ?? $value;
    }

    /** The charset parameter of a Content-Type header, if it carries one. */
    public static function charsetFromContentType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        return preg_match('/charset\s*=\s*["\']?([\w-]+)/i', $contentType, $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * Substitute invalid UTF-8 in every string of a storage payload so that
     * neither Eloquent's JSON casts nor a strict backend abort the INSERT.
     * Used for array/JSON columns (headers, query/body params) whose values may
     * carry legacy bytes — losing the whole audit record over one bad byte is
     * worse than storing a U+FFFD in its place.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function cleanForStorage(array $data): array
    {
        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            return $data;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        return $decoded;
    }

    /** Converted content, or null when no usable charset was declared. */
    private static function convertDeclared(string $content, ?string $charset): ?string
    {
        // A charset of UTF-8 on content that failed mb_check_encoding is simply
        // wrong, and converting UTF-8 to itself would not repair it.
        if ($charset === null || strtoupper($charset) === 'UTF-8') {
            return null;
        }

        try {
            $converted = mb_convert_encoding($content, 'UTF-8', $charset);
        } catch (ValueError) {
            return null; // unknown / unsupported charset label
        }

        return ($converted !== false && mb_check_encoding($converted, 'UTF-8'))
            ? $converted
            : null;
    }
}
