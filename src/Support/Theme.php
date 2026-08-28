<?php

namespace D076\Tracing\Support;

/**
 * The theme registry shared by the Blade shell and the Vue SPA.
 *
 * The list lives in resources/themes.json rather than in PHP so that adding a
 * theme costs one entry there plus one palette block in resources/css/themes.css.
 * Both halves of the UI read the same file, so the ids the SPA offers and the
 * ids the shell accepts cannot drift apart.
 */
final class Theme
{
    /**
     * @var array{storageKey: string, fallback: string, themes: list<array{id: string, label: string, icon: string}>}|null
     */
    private static ?array $registry = null;

    /**
     * @return list<array{id: string, label: string, icon: string}>
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
     * Narrows an arbitrary config value to a theme id the stylesheet actually
     * defines. Anything else — a typo, a theme removed from the registry, a
     * non-string, or an injection attempt — becomes the fallback, so the value
     * that reaches the `data-theme` attribute is always one of ours.
     */
    public static function resolve(mixed $theme): string
    {
        return is_string($theme) && in_array($theme, self::ids(), true)
            ? $theme
            : self::fallback();
    }

    /**
     * @return array{storageKey: string, fallback: string, themes: list<array{id: string, label: string, icon: string}>}
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

                $themes[] = [
                    'id' => $theme['id'],
                    'label' => is_string($theme['label'] ?? null) ? $theme['label'] : $theme['id'],
                    'icon' => is_string($theme['icon'] ?? null) ? $theme['icon'] : '',
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
