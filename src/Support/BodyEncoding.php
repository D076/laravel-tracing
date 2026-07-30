<?php

namespace D076\Tracing\Support;

use Symfony\Component\HttpFoundation\HeaderUtils;
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
 * A declared charset only counts if it is a single RFC-token-shaped label
 * (see {@see isConvertible()}). `mb_convert_encoding()` reads its charset
 * argument as a comma-separated *detection list* when it holds more than one
 * name, so a multi-value charset — a malformed declaration, or two
 * Content-Type headers joined by PSR-7's `getHeaderLine()` — would otherwise
 * smuggle the removed detection behaviour back in through that argument.
 *
 * Must run BEFORE masking and truncation: json_decode/parse_str only understand
 * UTF-8, and truncation assumes valid UTF-8 input (see {@see truncateBytes()}).
 */
final class BodyEncoding
{
    public static function toUtf8(string $content, ?string $contentType = null): string
    {
        return self::asUtf8($content, self::charsetFromContentType($contentType))
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
        // Without a usable charset there is nothing to convert to, so the walk
        // would rebuild the array element by element and hand back an equal one
        // — on every traced request, and losing copy-on-write with the Request.
        if ($data === null || !self::isConvertible($charset)) {
            return $data;
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
        return self::asUtf8($value, $charset) ?? $value;
    }

    /** The charset parameter of a Content-Type header, if it carries one. */
    public static function charsetFromContentType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        // HeaderUtils rather than a charset= regex: it implements the real
        // parameter grammar, so a charset= inside another parameter's quoted
        // value (a multipart boundary, say) is not mistaken for the body's.
        $charset = HeaderUtils::combine(HeaderUtils::split($contentType, ';='))['charset'] ?? null;

        return is_string($charset) && $charset !== '' ? $charset : null;
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
        // Invalid UTF-8 is exactly what makes a plain encode fail, so a payload
        // that encodes is already clean and is handed back untouched — only the
        // rare bad one pays for the substituting encode and the decode back.
        // JSON_UNESCAPED_UNICODE keeps that probe from inflating every non-ASCII
        // character into a 6-byte \uXXXX escape in a string we then throw away.
        if (json_encode($data, JSON_UNESCAPED_UNICODE) !== false) {
            return $data;
        }

        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return $data;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        return $decoded;
    }

    /**
     * Cuts to a BYTE budget — that is what the storage, the column limit and the
     * queue payload are actually denominated in. Uses mb_strcut rather than
     * substr so the budget is honoured without splitting a multi-byte character,
     * which a strict backend would reject. Must run AFTER masking.
     *
     * A non-positive budget means "do not truncate", not "keep nothing": a
     * missing config key arrives here as 0 and would otherwise reduce every
     * stored body to the truncation marker alone.
     */
    public static function truncateBytes(string $content, int $maxBytes): string
    {
        return $maxBytes > 0 && strlen($content) > $maxBytes
            ? mb_strcut($content, 0, $maxBytes, 'UTF-8') . '...[truncated]'
            : $content;
    }

    /** Content as valid UTF-8, or null when it cannot be produced. */
    private static function asUtf8(string $content, ?string $charset): ?string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        if (!self::isConvertible($charset)) {
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

    /**
     * A charset that could recover something. A charset of UTF-8 on content that
     * failed mb_check_encoding is simply wrong, and converting UTF-8 to itself
     * would not repair it.
     *
     * Also rejects anything that is not a single RFC-token-shaped charset name.
     * `mb_convert_encoding($str, 'UTF-8', $charset)` treats its third argument as
     * a comma-separated *detection list*, not a single label, when it contains
     * more than one name — so a value like `'UTF-8, Windows-1251'` (a malformed
     * declared charset) or `'windows-1251, text/plain'` (two Content-Type headers
     * joined by PSR-7's `getHeaderLine()`) would silently re-enable the charset
     * *detection* this class deliberately does not do, and turn binary payloads
     * into fabricated pseudo-text. A single token never matches that shape, so
     * this is the one point that must gate every caller — including the public
     * toUtf8Value()/toUtf8Deep() which accept a charset from arbitrary callers.
     */
    private static function isConvertible(?string $charset): bool
    {
        return $charset !== null
            && strtoupper($charset) !== 'UTF-8'
            && preg_match('/^[\w.:+-]+$/', $charset) === 1;
    }
}
