/**
 * Colours are declared as `rgb(var(--tr-*) / <alpha-value>)` over the channel
 * triples in css/themes.css. Keeping the variables channel-only is what lets
 * Tailwind's alpha modifiers (`bg-surface/50`, `border-line/40`) keep working;
 * a variable holding a finished colour would break them.
 *
 * A theme therefore never touches this file — it only redefines the variables.
 */
const token = (name) => `rgb(var(--tr-${name}) / <alpha-value>)`

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './js/**/*.{vue,js}',
        './views/**/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                canvas: token('canvas'),
                surface: {
                    DEFAULT: token('surface'),
                    sunken: token('surface-sunken'),
                },
                // `line` rather than `border`, which would collide with the
                // border-width utilities (`border`, `border-2`).
                line: {
                    DEFAULT: token('border'),
                    subtle: token('border-subtle'),
                    input: token('border-input'),
                    'input-hover': token('border-input-hover'),
                },
                fg: {
                    DEFAULT: token('text-body'),
                    strong: token('text-strong'),
                    muted: token('text-muted'),
                    faint: token('text-faint'),
                },
                focus: token('ring-focus'),
                accent: {
                    DEFAULT: token('accent'),
                    fg: token('accent-fg'),
                    hover: token('accent-hover'),
                },
                link: {
                    DEFAULT: token('link'),
                    hover: token('link-hover'),
                    surface: token('link-bg'),
                },
                // Response classes. Semantic, and deliberately separate from
                // `method` below: a theme may recolour HTTP verbs without
                // touching what 2xx and 5xx mean.
                status: {
                    success: { DEFAULT: token('status-success-bg'), fg: token('status-success-fg') },
                    info: { DEFAULT: token('status-info-bg'), fg: token('status-info-fg') },
                    warning: { DEFAULT: token('status-warning-bg'), fg: token('status-warning-fg') },
                    error: { DEFAULT: token('status-error-bg'), fg: token('status-error-fg') },
                },
                method: {
                    get: { DEFAULT: token('method-get-bg'), fg: token('method-get-fg') },
                    post: { DEFAULT: token('method-post-bg'), fg: token('method-post-fg') },
                    put: { DEFAULT: token('method-put-bg'), fg: token('method-put-fg') },
                    patch: { DEFAULT: token('method-patch-bg'), fg: token('method-patch-fg') },
                    delete: { DEFAULT: token('method-delete-bg'), fg: token('method-delete-fg') },
                    other: { DEFAULT: token('method-other-bg'), fg: token('method-other-fg') },
                },
                tag: {
                    DEFAULT: token('tag-bg'),
                    hover: token('tag-bg-hover'),
                    line: token('tag-border'),
                    fg: token('tag-fg'),
                },
                danger: {
                    DEFAULT: token('danger-text'),
                    surface: token('danger-surface'),
                    line: token('danger-border'),
                    strong: token('danger-text-strong'),
                    faint: token('danger-faint'),
                },
                metric: {
                    warn: token('metric-warn'),
                    bad: token('metric-bad'),
                },
            },
        },
    },
    plugins: [],
}
