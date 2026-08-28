<?php

use D076\Tracing\Support\AssetVersion;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('viewTracing', fn ($user = null) => true);
});

describe('GET /tracing/assets/{file}', function () {
    it('serves a bundled asset with its content type', function (string $file, string $contentType) {
        $response = $this->get("/tracing/assets/{$file}")->assertOk();

        expect($response->headers->get('Content-Type'))->toBe($contentType);
    })->with([
        'javascript' => ['app.js', 'application/javascript; charset=utf-8'],
        'stylesheet' => ['app.css', 'text/css; charset=utf-8'],
    ]);

    it('marks a content-versioned asset as immutably cacheable', function (string $file) {
        $version = AssetVersion::for($file);

        $response = $this->get("/tracing/assets/{$file}?v={$version}")->assertOk();

        expect($response->headers->get('Cache-Control'))
            ->toContain('immutable')
            ->toContain('max-age=31536000');
    })->with(['app.js', 'app.css']);

    it('refuses to mark an unversioned url as immutable', function () {
        // `immutable` on a URL that never changes is a year-long trap: the
        // browser will not re-ask even on a reload, so a package upgrade cannot
        // reach it.
        $response = $this->get('/tracing/assets/app.css')->assertOk();

        expect($response->headers->get('Cache-Control'))
            ->not->toContain('immutable')
            ->toContain('no-cache');
    });

    it('refuses to mark a stale version as immutable', function () {
        $response = $this->get('/tracing/assets/app.css?v=deadbeef')->assertOk();

        expect($response->headers->get('Cache-Control'))->not->toContain('immutable');
    });

    it('offers a validator so an unversioned request can be revalidated cheaply', function () {
        $response = $this->get('/tracing/assets/app.css')->assertOk();

        expect($response->headers->get('ETag'))->not->toBeEmpty();
    });

    it('refuses to serve a file outside the dist directory', function (string $file) {
        // The route pattern is '.+', so traversal segments reach the controller
        // and the realpath prefix check is the only thing standing in the way.
        $this->get("/tracing/assets/{$file}")->assertNotFound();
    })->with([
        'parent traversal' => ['../../../composer.json'],
        'deep traversal' => ['../../../../../../etc/passwd'],
        'traversal below a real segment' => ['app.js/../../../composer.json'],
        'absolute path' => ['/etc/passwd'],
    ]);

    it('does not disclose the contents of a file it refuses', function () {
        $response = $this->get('/tracing/assets/../../../composer.json');

        expect($response->getContent())->not->toContain('d076/laravel-tracing');
    });

    it('returns 404 for a missing asset rather than erroring', function () {
        $this->get('/tracing/assets/does-not-exist.js')->assertNotFound();
    });
});

describe('the SPA shell', function () {
    it('links its assets at a content-versioned url', function () {
        $response = $this->get('/tracing')->assertOk();

        expect($response->getContent())
            ->toContain('app.js?v=' . AssetVersion::for('app.js'))
            ->toContain('app.css?v=' . AssetVersion::for('app.css'));
    });

    it('moves the asset urls when a bundle changes', function () {
        // Nothing rebuilds mid-suite, so the guarantee is stated the only way it
        // can be: the url carries this file's own content hash, and that hash is
        // proven to track content in tests/Unit/Support/AssetVersionTest.php.
        $response = $this->get('/tracing')->assertOk();

        expect($response->getContent())
            ->toContain('v=' . AssetVersion::of(realpath(__DIR__ . '/../../resources/dist/app.js')));
    });
});
