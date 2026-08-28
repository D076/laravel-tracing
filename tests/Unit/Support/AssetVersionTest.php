<?php

use D076\Tracing\Support\AssetVersion;

/**
 * The version is what makes the year-long `immutable` cache header safe, so the
 * property that matters is not "there is a version" but "the version is a
 * function of the bytes served" — a version that can stay put while the file
 * changes is worse than no version at all.
 */
describe('AssetVersion::of()', function () {
    beforeEach(function () {
        $this->path = tempnam(sys_get_temp_dir(), 'tracing-asset-');
    });

    afterEach(function () {
        @unlink($this->path);
    });

    it('derives a short hexadecimal version from the contents', function () {
        file_put_contents($this->path, 'console.log(1)');

        expect(AssetVersion::of($this->path))->toMatch('/^[0-9a-f]{8,12}$/');
    });

    it('returns the same version for unchanged contents', function () {
        file_put_contents($this->path, 'console.log(1)');

        expect(AssetVersion::of($this->path))->toBe(AssetVersion::of($this->path));
    });

    it('returns a different version once the contents change', function () {
        file_put_contents($this->path, 'console.log(1)');
        $before = AssetVersion::of($this->path);

        file_put_contents($this->path, 'console.log(2)');

        expect(AssetVersion::of($this->path))->not->toBe($before);
    });

    it('ignores the modification time', function () {
        // A build that copies the bundle unchanged (composer install, a deploy
        // rsync) moves mtime without moving a byte; an mtime-derived version
        // would invalidate every client's cache for nothing.
        file_put_contents($this->path, 'console.log(1)');
        $before = AssetVersion::of($this->path);

        touch($this->path, time() + 3600);
        clearstatcache(true, $this->path);

        expect(AssetVersion::of($this->path))->toBe($before);
    });

    it('returns null for a file it cannot read', function () {
        expect(AssetVersion::of($this->path . '-does-not-exist'))->toBeNull();
    });
});

describe('AssetVersion::for()', function () {
    it('versions the bundled assets', function (string $file) {
        expect(AssetVersion::for($file))->toMatch('/^[0-9a-f]{8,12}$/');
    })->with(['app.js', 'app.css']);

    it('answers the same version on every call within a process', function () {
        expect(AssetVersion::for('app.js'))->toBe(AssetVersion::for('app.js'));
    });

    it('matches the hash of the file actually served', function () {
        $dist = realpath(__DIR__ . '/../../../resources/dist/app.js');

        expect(AssetVersion::for('app.js'))->toBe(AssetVersion::of($dist));
    });

    it('gives different bundles different versions', function () {
        expect(AssetVersion::for('app.js'))->not->toBe(AssetVersion::for('app.css'));
    });

    it('returns null for anything outside the dist directory', function (string $file) {
        expect(AssetVersion::for($file))->toBeNull();
    })->with([
        'missing' => ['does-not-exist.js'],
        'parent traversal' => ['../../composer.json'],
        'traversal below a real segment' => ['app.js/../../../composer.json'],
        'absolute path' => ['/etc/passwd'],
    ]);
});
