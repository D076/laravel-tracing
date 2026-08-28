<?php

namespace D076\Tracing\Support;

/**
 * Derives the readable shape of an outgoing request from what was stored raw.
 *
 * Outgoing records keep the URL and the bodies exactly as they went on the wire,
 * because that is what an audit trail is for. Query parameters and form fields
 * are a view over those strings, computed when a record is read: nothing is
 * duplicated in the schema, no migration is needed, and records written by
 * earlier versions of the package become readable too.
 *
 * Parsing goes through parse_str on purpose. Incoming records get their
 * query_params/body_params from PHP's own parsing of the very same syntax, so
 * anything hand-rolled would render the two halves of one trace differently —
 * bracket nesting and repeated keys in particular. The known cost is inherited
 * as well: parse_str rewrites dots and spaces in top-level keys to underscores.
 */
final class RequestParams
{
    /**
     * Parameters carried by the URL's query string, or null when it carries none.
     *
     * @return array<string, mixed>|null
     */
    public static function queryFromUrl(string $url): ?array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return is_string($query) ? self::parse($query) : null;
    }

    /**
     * Fields of an application/x-www-form-urlencoded body, or null when it holds none.
     *
     * @return array<string, mixed>|null
     */
    public static function formBody(string $body): ?array
    {
        return self::parse(self::withoutTruncationMarker($body));
    }

    /**
     * The body was cut to its byte budget, so its last field may be incomplete.
     */
    public static function isTruncated(string $body): bool
    {
        return str_ends_with($body, BodyEncoding::TRUNCATION_MARKER);
    }

    public static function isFormUrlEncoded(?string $contentType): bool
    {
        return $contentType !== null
            && str_contains(strtolower($contentType), 'application/x-www-form-urlencoded');
    }

    /**
     * Content-Type out of a stored PSR header map, whose names keep the casing
     * the sender used.
     *
     * Typed loosely on purpose: the map is read back from a JSON column, so what
     * it holds is whatever was written — a list of values normally, a bare string
     * in a hand-written or older record.
     *
     * @param  array<string, mixed>|null  $headers
     */
    public static function contentTypeFrom(?array $headers): ?string
    {
        foreach ($headers ?? [] as $name => $values) {
            if (strtolower((string) $name) !== 'content-type') {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? null) : $values;

            return is_string($value) ? $value : null;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function parse(string $input): ?array
    {
        parse_str($input, $parsed);

        return $parsed !== [] ? $parsed : null;
    }

    private static function withoutTruncationMarker(string $body): string
    {
        return self::isTruncated($body)
            ? substr($body, 0, -strlen(BodyEncoding::TRUNCATION_MARKER))
            : $body;
    }
}
