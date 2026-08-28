<?php

/**
 * `tracing.ui.enabled_themes` narrows the themes the interface offers.
 *
 * The list is not a plain filter: `system` is not a palette but the rule "follow
 * prefers-color-scheme", so it is a choice *between* light and dark. Offering it
 * once dark is gone would give the visitor a switch that changes nothing, which
 * reads as a bug — hence the derived-theme rule these tests pin down.
 */

use D076\Tracing\Support\Theme;

describe('Theme::availableIds()', function () {
    it('offers every registered theme when the list is empty', function (mixed $configured) {
        config(['tracing.ui.enabled_themes' => $configured]);

        expect(Theme::availableIds())->toBe(Theme::ids());
    })->with([
        'absent' => [null],
        'empty array' => [[]],
        'empty string' => [''],
        'whitespace' => ['  '],
    ]);

    it('offers exactly the themes the list names', function () {
        config(['tracing.ui.enabled_themes' => ['light', 'dark']]);

        expect(Theme::availableIds())->toBe(['light', 'dark']);
    });

    it('accepts the comma-separated form an env variable produces', function () {
        config(['tracing.ui.enabled_themes' => 'light, dark']);

        expect(Theme::availableIds())->toBe(['light', 'dark']);
    });

    it('keeps registry order regardless of how the list is written', function () {
        config(['tracing.ui.enabled_themes' => ['bimbo-pink', 'light', 'system', 'dark']]);

        expect(Theme::availableIds())->toBe(Theme::ids());
    });

    it('ignores an id no theme in the registry uses', function () {
        config(['tracing.ui.enabled_themes' => ['light', 'sepia', 'dark']]);

        expect(Theme::availableIds())->toBe(['light', 'dark']);
    });

    it('ignores entries that are not strings', function () {
        config(['tracing.ui.enabled_themes' => ['light', ['dark'], 42, null]]);

        expect(Theme::availableIds())->toBe(['light']);
    });

    it('drops system when dark is not available, since it would switch nothing', function () {
        config(['tracing.ui.enabled_themes' => ['system', 'light']]);

        expect(Theme::availableIds())->toBe(['light']);
    });

    it('drops system when light is not available', function () {
        config(['tracing.ui.enabled_themes' => ['system', 'dark']]);

        expect(Theme::availableIds())->toBe(['dark']);
    });

    it('keeps system when both themes it derives from are available', function () {
        config(['tracing.ui.enabled_themes' => ['system', 'light', 'dark']]);

        expect(Theme::availableIds())->toBe(['system', 'light', 'dark']);
    });

    it('never leaves the interface without a palette', function (mixed $configured) {
        config(['tracing.ui.enabled_themes' => $configured]);

        expect(Theme::availableIds())->toBe(['light']);
    })->with([
        'every id unknown' => [['sepia', 'solarized']],
        'only a derived theme, with nothing to derive from' => [['system']],
    ]);

    it('reports availability through the full theme records too', function () {
        config(['tracing.ui.enabled_themes' => ['light', 'dark']]);

        $available = Theme::available();

        expect(array_column($available, 'id'))->toBe(['light', 'dark'])
            ->and($available[0]['label'])->toBe('Light')
            ->and($available[0]['icon'])->not->toBeEmpty();
    });
});

describe('Theme::resolve()', function () {
    it('keeps a configured default that is available', function () {
        config(['tracing.ui.enabled_themes' => ['light', 'bimbo-pink']]);

        expect(Theme::resolve('bimbo-pink'))->toBe('bimbo-pink');
    });

    it('falls back to the registry fallback when it is available', function () {
        config(['tracing.ui.enabled_themes' => ['system', 'light', 'dark']]);

        expect(Theme::resolve('bimbo-pink'))->toBe('system');
    });

    it('falls back to the first available theme when the registry fallback is not offered', function () {
        config(['tracing.ui.enabled_themes' => ['dark', 'bimbo-pink']]);

        expect(Theme::resolve('light'))->toBe('dark');
    });

    it('still rejects a value naming no theme at all', function (mixed $configured) {
        config(['tracing.ui.enabled_themes' => []]);

        expect(Theme::resolve($configured))->toBe(Theme::fallback());
    })->with([
        'typo' => ['drak'],
        'null' => [null],
        'array' => [['dark']],
    ]);
});
