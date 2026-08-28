import registry from '../themes.json'

/**
 * The single list of themes the interface offers.
 *
 * It is loaded from resources/themes.json because the Blade shell needs the
 * same list: it renders the configured theme onto <html> and its inline script
 * validates whatever localStorage hands back. Adding a theme means an entry
 * there and a palette block in css/themes.css — no component knows the ids.
 *
 * `icon` is the `d` of a single stroked path drawn in a 24×24 viewBox.
 *
 * The bundle carries the whole registry, but an installation may offer only
 * part of it through `tracing.ui.enabled_themes`; the shell passes the surviving ids on
 * `window.__tracing.themes`, already reduced — a derived theme such as `system`
 * is gone from that list once light or dark is switched off. Filtering here
 * rather than in the components keeps the ids out of every component, as before.
 */
const offered = window.__tracing?.themes

export const THEMES = Array.isArray(offered) && offered.length > 0
    ? registry.themes.filter((theme) => offered.includes(theme.id))
    : registry.themes

export const STORAGE_KEY = registry.storageKey

/**
 * Only meaningful as a last resort for `currentTheme()`: the shell has already
 * applied a theme this installation offers, so this is reached only if the
 * attribute was tampered with. It stays inside the offered list.
 */
export const FALLBACK_THEME = THEMES.some((theme) => theme.id === registry.fallback)
    ? registry.fallback
    : THEMES[0]?.id

/**
 * The theme currently in force, read off <html> — the shell's inline script has
 * already reconciled config and localStorage there by the time Vue mounts, so
 * this never disagrees with what the visitor is looking at.
 */
export function currentTheme() {
    const applied = document.documentElement.getAttribute('data-theme')

    return THEMES.some((theme) => theme.id === applied) ? applied : FALLBACK_THEME
}

export function applyTheme(id) {
    document.documentElement.setAttribute('data-theme', id)

    try {
        window.localStorage.setItem(STORAGE_KEY, id)
    } catch (e) {
        // Storage can be unavailable (private mode, blocked cookies). The theme
        // still applies to this page; it just will not survive a reload.
    }
}
