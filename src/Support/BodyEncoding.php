<?php

namespace D076\Tracing\Support;

use ValueError;

/**
 * Normalizes a raw HTTP body to valid UTF-8 before it is persisted.
 *
 * A strict backend — Postgres with a UTF8 database — aborts the whole INSERT on
 * any byte sequence that is not valid UTF-8. A response in a legacy charset
 * (Windows-1251 and friends) would therefore be lost together with its error
 * log noise. This converts such a body to UTF-8; when the charset cannot be
 * determined it substitutes a short marker so the record is still stored rather
 * than dropped.
 *
 * Must run BEFORE masking and truncation: json_decode/parse_str only understand
 * UTF-8, and truncation assumes a valid-UTF-8 input (see mb_strcut call sites).
 */
final class BodyEncoding
{
    /**
     * Charsets tried, in order, when the body is not UTF-8 and the server did
     * not declare a usable charset. Deliberately small and strict: a genuine
     * binary blob should fall through to the marker, not be silently mangled
     * into Latin-1 (which accepts every byte and would defeat the marker).
     */
    private const DETECT_ORDER = ['UTF-8', 'Windows-1251'];

    public static function toUtf8(string $content, ?string $contentType = null): string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        // 1. Trust the charset the server declared in Content-Type.
        $declared = self::charsetFromContentType($contentType);

        if ($declared !== null && strtoupper($declared) !== 'UTF-8') {
            $converted = self::convert($content, $declared);

            if ($converted !== null) {
                return $converted;
            }
        }

        // 2. Best-effort detection for undeclared legacy text.
        $detected = mb_detect_encoding($content, self::DETECT_ORDER, true);

        if ($detected !== false && $detected !== 'UTF-8') {
            $converted = self::convert($content, $detected);

            if ($converted !== null) {
                return $converted;
            }
        }

        // 3. Undecodable / binary — store a marker, never raw bytes.
        return '[non-UTF-8 body, ' . strlen($content) . ' bytes]';
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

    private static function convert(string $content, string $fromCharset): ?string
    {
        try {
            $converted = mb_convert_encoding($content, 'UTF-8', $fromCharset);
        } catch (ValueError) {
            return null; // unknown / unsupported charset label
        }

        return ($converted !== false && mb_check_encoding($converted, 'UTF-8'))
            ? $converted
            : null;
    }

    private static function charsetFromContentType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        return preg_match('/charset\s*=\s*["\']?([\w-]+)/i', $contentType, $m) === 1
            ? $m[1]
            : null;
    }
}
