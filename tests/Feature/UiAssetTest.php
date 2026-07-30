<?php

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

    it('marks assets as immutably cacheable', function () {
        $response = $this->get('/tracing/assets/app.css')->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('immutable');
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
