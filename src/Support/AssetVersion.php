<?php

namespace D076\Tracing\Support;

/**
 * Cache-busting version for the prebuilt SPA bundles in resources/dist.
 *
 * The bundles are built under fixed names (`app.js`, `app.css`) because the
 * controller serves them by name, so the URL itself carries no identity and a
 * long-lived cache header on it would survive package upgrades. The version
 * here restores that identity: it is a short hash of the file's own bytes,
 * which is what makes `immutable` on the versioned URL true rather than a wish.
 *
 * Deliberately not derived from the package version — the package is often
 * consumed as a path repository over a symlink during development, where the
 * version never moves while the bundle changes on every build — nor from
 * `filemtime`, which composer install, archive extraction and deploy copies all
 * move without a byte changing.
 */
final class AssetVersion
{
    private const ALGO = 'xxh128';

    private const LENGTH = 10;

    /** @var array<string, string|null> */
    private static array $versions = [];

    /**
     * Version of a bundled asset addressed by its name inside resources/dist,
     * memoized for the life of the process. Returns null when the name does not
     * resolve to a readable file inside that directory.
     */
    public static function for(string $file): ?string
    {
        if (array_key_exists($file, self::$versions)) {
            return self::$versions[$file];
        }

        $distDir = realpath(__DIR__ . '/../../resources/dist');
        $path = $distDir === false ? false : realpath($distDir . '/' . $file);

        $inside = $distDir !== false
            && $path !== false
            && str_starts_with($path, $distDir . DIRECTORY_SEPARATOR);

        return self::$versions[$file] = $inside ? self::of((string) $path) : null;
    }

    /**
     * Version of an arbitrary file path, computed fresh on every call. Returns
     * null when the file cannot be hashed (missing, unreadable, a directory).
     */
    public static function of(string $path): ?string
    {
        $hash = @hash_file(self::ALGO, $path);

        return $hash === false ? null : substr($hash, 0, self::LENGTH);
    }
}
