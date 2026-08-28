<?php

use D076\Tracing\Support\Theme;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('viewTracing', fn ($user = null) => true);
});

describe('tracing.ui.theme', function () {
    it('defaults to following the operating system', function () {
        expect(config('tracing.ui.theme'))->toBe('system');
    });

    it('knows the themes the stylesheet ships', function () {
        expect(Theme::ids())->toContain('system', 'light', 'dark');
    });
});

describe('the SPA shell', function () {
    it('renders the theme from config on the html element', function (string $configured) {
        config(['tracing.ui.theme' => $configured]);

        $this->get('/tracing')
            ->assertOk()
            ->assertSee('data-theme="'.$configured.'"', false);
    })->with(['system', 'light', 'dark']);

    it('falls back to system when the configured theme is unknown', function (mixed $configured) {
        config(['tracing.ui.theme' => $configured]);

        $this->get('/tracing')
            ->assertOk()
            ->assertSee('data-theme="system"', false);
    })->with([
        'typo' => ['drak'],
        'names no theme the stylesheet defines' => ['midnight'],
        'empty' => [''],
        'null' => [null],
        'array' => [['dark']],
    ]);

    it('never puts an unknown configured value into the markup', function () {
        config(['tracing.ui.theme' => 'sepia"><script>alert(1)</script>']);

        $response = $this->get('/tracing')->assertOk();

        expect($response->getContent())
            ->not->toContain('sepia')
            ->not->toContain('alert(1)');
    });

    it('applies the stored preference before the first paint', function () {
        $content = (string) $this->get('/tracing')->assertOk()->getContent();

        $head = substr($content, 0, (int) strpos($content, '</head>'));

        // The inline script has to sit in <head>, ahead of any rendered markup:
        // set data-theme after the first paint and every load flashes the light
        // theme over the one the user picked.
        expect($head)
            ->toContain(Theme::storageKey())
            ->toContain('data-theme');
    });

    it('lets the shell script recognise exactly the registered themes', function () {
        $content = (string) $this->get('/tracing')->assertOk()->getContent();

        foreach (Theme::ids() as $id) {
            expect($content)->toContain('"'.$id.'"');
        }
    });
});
