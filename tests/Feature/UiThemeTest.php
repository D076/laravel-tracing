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
        expect(Theme::ids())->toContain('system', 'light', 'dark', 'bimbo-pink');
    });
});

describe('the SPA shell', function () {
    it('renders the theme from config on the html element', function (string $configured) {
        config(['tracing.ui.theme' => $configured]);

        $this->get('/tracing')
            ->assertOk()
            ->assertSee('data-theme="'.$configured.'"', false);
    })->with(Theme::ids());

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

        foreach (Theme::availableIds() as $id) {
            expect($content)->toContain('"'.$id.'"');
        }
    });
});

describe('tracing.ui.enabled_themes', function () {
    it('hands the shell script only the available ids', function () {
        config(['tracing.ui.enabled_themes' => ['light', 'dark']]);

        $content = (string) $this->get('/tracing')->assertOk()->getContent();

        // A visitor who picked pink before it was switched off has the id in
        // localStorage; the inline script must no longer recognise it, or the
        // stored value would apply the theme the config just withdrew.
        expect($content)
            ->toContain('"light"')
            ->toContain('"dark"')
            ->not->toContain('"bimbo-pink"')
            ->not->toContain('"system"');
    });

    it('hands the SPA the same list, so the switcher offers no withdrawn theme', function () {
        config(['tracing.ui.enabled_themes' => ['light', 'dark']]);

        $content = (string) $this->get('/tracing')->assertOk()->getContent();

        expect($content)->toContain('window.__tracing')
            ->and($content)->toMatch('/themes:\s*\["light","dark"\]/');
    });

    it('renders a default the config still offers', function () {
        config(['tracing.ui.enabled_themes' => ['dark', 'bimbo-pink'], 'tracing.ui.theme' => 'light']);

        $this->get('/tracing')
            ->assertOk()
            ->assertSee('data-theme="dark"', false);
    });

    it('prefers the registry fallback when the configured default is withdrawn', function () {
        config(['tracing.ui.enabled_themes' => ['system', 'light', 'dark'], 'tracing.ui.theme' => 'bimbo-pink']);

        $this->get('/tracing')
            ->assertOk()
            ->assertSee('data-theme="system"', false);
    });

    it('keeps a palette on the page even when the config withdraws every theme', function () {
        config(['tracing.ui.enabled_themes' => ['sepia'], 'tracing.ui.theme' => 'sepia']);

        $this->get('/tracing')
            ->assertOk()
            ->assertSee('data-theme="light"', false);
    });
});

describe('the theme switcher', function () {
    // There is no JS test runner in this package, so the guard is locked at the
    // source level: a switcher offering one theme is a control that cannot be
    // used, and it must not render at all.
    it('renders nothing when a single theme is available', function () {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/components/ThemeSwitcher.vue');

        expect($source)->toMatch('/v-if="[^"]*THEMES\.length > 1[^"]*"/');
    });

    it('iterates the available themes rather than the whole registry', function () {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/themes.js');

        expect($source)->toContain('window.__tracing');
    });
});
