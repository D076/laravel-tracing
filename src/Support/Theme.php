<?php

namespace D076\Tracing\Support;

/**
 * The theme registry shared by the Blade shell and the Vue SPA.
 *
 * The list lives in resources/themes.json rather than in PHP so that adding a
 * theme costs one entry there plus one palette block in resources/css/themes.css.
 * Both halves of the UI read the same file, so the ids the SPA offers and the
 * ids the shell accepts cannot drift apart.
 *
 * `all()` is the registry — every theme the stylesheet ships. `available()` is
 * what this installation offers, once `tracing.ui.enabled_themes` has had its say. The
 * two are deliberately distinct: the palette blocks are compiled into the
 * bundle either way, and a theme being absent from the switcher says nothing
 * about the CSS being on disk.
 */
final class Theme
{
    /**
     * @var array{storageKey: string, fallback: string, themes: list<array{id: string, label: string, icon: string, requires: list<string>}>}|null
     */
    private static ?array $registry = null;

    /**
     * @return list<array{id: string, label: string, icon: string, requires: list<string>}>
     */
    public static function all(): array
    {
        return self::registry()['themes'];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_map(static fn (array $theme): string => $theme['id'], self::all());
    }

    public static function fallback(): string
    {
        return self::registry()['fallback'];
    }

    /**
     * localStorage key the SPA persists the visitor's choice under. The inline
     * shell script reads the same key before the first paint.
     */
    public static function storageKey(): string
    {
        return self::registry()['storageKey'];
    }

    /**
     * The themes this installation offers, in registry order.
     *
     * `tracing.ui.enabled_themes` narrows the registry; empty or absent offers all of
     * it. A derived theme — one declaring `requires` — survives only if every
     * theme it derives from survived: `system` is not a palette but the rule
     * "follow prefers-color-scheme", so without both light and dark it is a
     * switch that changes nothing. That is why its availability is inferred and
     * cannot be configured directly.
     *
     * The result is never empty. A config that names only unknown ids, or only
     * a derived theme with nothing left to derive from, would otherwise leave
     * the interface with no palette at all; the first non-derived theme in the
     * registry stands in.
     *
     * @return list<array{id: string, label: string, icon: string, requires: list<string>}>
     */
    public static function available(): array
    {
        $configured = self::configuredIds();

        $themes = $configured === []
            ? self::all()
            : array_values(array_filter(
                self::all(),
                static fn (array $theme): bool => in_array($theme['id'], $configured, true),
            ));

        // Repeated to a fixed point: a derived theme may itself be required by
        // another, and dropping one can strand the next.
        while (true) {
            $ids = array_map(static fn (array $theme): string => $theme['id'], $themes);

            $kept = array_values(array_filter(
                $themes,
                static fn (array $theme): bool => array_diff($theme['requires'], $ids) === [],
            ));

            if (count($kept) === count($themes)) {
                break;
            }

            $themes = $kept;
        }

        if ($themes !== []) {
            return $themes;
        }

        foreach (self::all() as $theme) {
            if ($theme['requires'] === []) {
                return [$theme];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public static function availableIds(): array
    {
        return array_map(static fn (array $theme): string => $theme['id'], self::available());
    }

    /**
     * Narrows an arbitrary config value to a theme id this installation offers.
     * Anything else — a typo, a theme removed from the registry, one switched
     * off through `tracing.ui.enabled_themes`, a non-string, or an injection attempt —
     * resolves deterministically: the registry fallback when it is available,
     * otherwise the first available theme. So the value reaching the
     * `data-theme` attribute is always one of ours *and* always one the visitor
     * could have picked themselves.
     */
    public static function resolve(mixed $theme): string
    {
        $available = self::availableIds();

        if (is_string($theme) && in_array($theme, $available, true)) {
            return $theme;
        }

        $fallback = self::fallback();

        if (in_array($fallback, $available, true)) {
            return $fallback;
        }

        return $available[0] ?? $fallback;
    }

    /**
     * The ids named by `tracing.ui.enabled_themes`, accepting both the array a published
     * config file holds and the comma-separated string an env variable carries —
     * `TRACING_UI_ENABLED_THEMES=light,dark` has to work without publishing the config.
     *
     * @return list<string>
     */
    private static function configuredIds(): array
    {
        $configured = config('tracing.ui.enabled_themes');

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        $ids = [];

        foreach ($configured as $id) {
            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        return $ids;
    }

    /**
     * @return array{storageKey: string, fallback: string, themes: list<array{id: string, label: string, icon: string, requires: list<string>}>}
     */
    private static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $contents = file_get_contents(__DIR__.'/../../resources/themes.json');
        $decoded = $contents === false ? null : json_decode($contents, true);

        $themes = [];

        if (is_array($decoded) && isset($decoded['themes']) && is_array($decoded['themes'])) {
            foreach ($decoded['themes'] as $theme) {
                if (! is_array($theme) || ! is_string($theme['id'] ?? null)) {
                    continue;
                }

                $requires = [];

                foreach (is_array($theme['requires'] ?? null) ? $theme['requires'] : [] as $required) {
                    if (is_string($required)) {
                        $requires[] = $required;
                    }
                }

                $themes[] = [
                    'id' => $theme['id'],
                    'label' => is_string($theme['label'] ?? null) ? $theme['label'] : $theme['id'],
                    'icon' => is_string($theme['icon'] ?? null) ? $theme['icon'] : '',
                    'requires' => $requires,
                ];
            }
        }

        $fallback = is_array($decoded) && is_string($decoded['fallback'] ?? null)
            ? $decoded['fallback']
            : 'system';

        return self::$registry = [
            'storageKey' => is_array($decoded) && is_string($decoded['storageKey'] ?? null)
                ? $decoded['storageKey']
                : 'tracing.theme',
            'fallback' => $fallback,
            'themes' => $themes,
        ];
    }
}
