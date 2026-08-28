<?php

namespace D076\Tracing\Http\Controllers;

use D076\Tracing\Support\AssetVersion;
use D076\Tracing\Support\Theme;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class TracingUiController extends Controller
{
    public function index(): Response
    {
        return response()->view('tracing::index', [
            'theme' => Theme::resolve(config('tracing.ui.theme')),
            'themes' => Theme::ids(),
            'themeStorageKey' => Theme::storageKey(),
            'scriptUrl' => self::assetUrl('app.js'),
            'styleUrl' => self::assetUrl('app.css'),
        ]);
    }

    public function asset(string $file): BinaryFileResponse
    {
        $distDir = realpath(__DIR__ . '/../../../resources/dist');
        $distPath = realpath($distDir . '/' . $file);

        abort_unless(
            $distDir !== false && $distPath !== false && str_starts_with($distPath, $distDir),
            404,
        );

        $mimeType = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'js' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            default => 'application/octet-stream',
        };

        $response = response()->file($distPath, [
            'Content-Type' => $mimeType,
            // `immutable` is only honest on a URL that names its contents. The
            // bare URL keeps its fixed name across upgrades, so caching it for a
            // year would leave browsers on a stale bundle that even a reload
            // cannot dislodge.
            'Cache-Control' => self::requestedVersionMatches($file)
                ? 'public, max-age=31536000, immutable'
                : 'public, max-age=0, no-cache, must-revalidate',
        ]);

        // Validators keep the unversioned URL cheap: a revalidation answers 304
        // instead of resending the bundle.
        $response->setAutoEtag();
        $response->setAutoLastModified();

        return $response;
    }

    private static function assetUrl(string $file): string
    {
        $version = AssetVersion::for($file);

        return route('tracing.asset', $version === null
            ? ['file' => $file]
            : ['file' => $file, 'v' => $version]);
    }

    /**
     * Whether the request asked for the exact bytes this process is serving. An
     * outdated `?v=` — a link held by a client from before an upgrade — is
     * answered with the current bundle but without the year-long header, so the
     * mismatch cannot be frozen into a cache under the wrong version.
     */
    private static function requestedVersionMatches(string $file): bool
    {
        $version = AssetVersion::for($file);
        $requested = request()->query('v');

        return $version !== null && is_string($requested) && hash_equals($version, $requested);
    }
}
