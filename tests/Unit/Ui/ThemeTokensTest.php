<?php

/**
 * Regression lock for the theming refactor: the SPA must address colour through
 * semantic tokens only, never through a raw Tailwind palette utility. A single
 * hardcoded `bg-gray-200` is invisible in the light theme and wrong in every
 * other one, so it is cheaper to fail the build than to find it by eye.
 */

use D076\Tracing\Support\Theme;

/**
 * Every Tailwind utility that takes a colour. `ring-offset` precedes `ring` so
 * the longer prefix wins the alternation.
 */
const COLOUR_UTILITIES = 'bg|text|border|divide|ring-offset|ring|outline|placeholder|caret|accent|decoration|shadow|fill|stroke|from|via|to';

const TAILWIND_PALETTE = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

/**
 * Matches `bg-gray-200`, `hover:text-red-500`, `group-hover:border-indigo-100`,
 * `bg-white/50` — but not a semantic token such as `bg-surface-sunken`, whose
 * name carries no palette word followed by a shade number.
 */
const PALETTE_PATTERN = '/(?<![\w-])(?:'.COLOUR_UTILITIES.')-(?:(?:'.TAILWIND_PALETTE.')-(?:50|[1-9]00|950)|white|black)(?![\w-])/';

/**
 * @return list<string>
 */
function spaSourceFiles(): array
{
    $root = dirname(__DIR__, 3).'/resources/js';

    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['vue', 'js'], true)) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('finds SPA sources to scan', function () {
    expect(spaSourceFiles())->not->toBeEmpty();
});

it('addresses colour through semantic tokens, never a raw Tailwind palette utility', function () {
    $offenders = [];

    foreach (spaSourceFiles() as $path) {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        foreach ($lines === false ? [] : $lines as $number => $line) {
            if (preg_match_all(PALETTE_PATTERN, $line, $matches) > 0) {
                $relative = substr($path, strlen(dirname(__DIR__, 3)) + 1);
                $offenders[] = $relative.':'.($number + 1).' → '.implode(', ', $matches[0]);
            }
        }
    }

    expect($offenders)->toBe([], "Raw Tailwind colour utilities found:\n".implode("\n", $offenders));
});

it('flags a raw palette utility when one is introduced', function (string $class) {
    expect(preg_match(PALETTE_PATTERN, '"px-2 '.$class.' rounded"'))->toBe(1);
})->with([
    'bg-gray-200',
    'text-red-500',
    'border-indigo-100',
    'hover:bg-gray-50',
    'group-hover:text-gray-400',
    'focus:ring-gray-400',
    'divide-gray-100',
    'bg-white',
    'md:hover:bg-emerald-100',
    'bg-gray-800/50',
]);

it('leaves semantic token utilities alone', function (string $class) {
    expect(preg_match(PALETTE_PATTERN, '"px-2 '.$class.' rounded"'))->toBe(0);
})->with([
    'bg-canvas',
    'bg-surface',
    'bg-surface-sunken',
    'text-fg',
    'text-fg-faint',
    'hover:text-fg-strong',
    'border-line',
    'divide-line-subtle',
    'focus:ring-focus',
    'bg-status-success',
    'text-method-get-fg',
    'bg-tag',
    'text-danger-strong',
    'text-metric-warn',
    'bg-accent/50',
]);

it('gives every registered theme a palette block of its own', function () {
    $css = file_get_contents(dirname(__DIR__, 3).'/resources/css/themes.css');

    expect($css)->not->toBeFalse();

    foreach (Theme::ids() as $id) {
        expect($css)->toContain("[data-theme='".$id."']");
    }
});
