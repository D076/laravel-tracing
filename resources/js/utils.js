export function formatDuration(ms) {
    if (ms === null || ms === undefined) return '—'
    if (ms < 1000) return `${ms}ms`
    return `${(ms / 1000).toFixed(2)}s`
}

export function durationClass(ms) {
    if (ms === null || ms === undefined) return 'text-fg-faint'
    if (ms > 1000) return 'text-metric-bad font-medium'
    if (ms > 500) return 'text-metric-warn'
    return 'text-fg-muted'
}

export function formatTime(iso) {
    return new Date(iso).toLocaleString()
}

export function timeAgo(iso) {
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 5) return 'just now'
    if (diff < 60) return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return formatTime(iso)
}

// Returns true when a row click should open in a new tab instead of navigating
// in place: Cmd/Ctrl/Shift + left click, or a middle click (auxclick button 1).
export function wantsNewTab(e) {
    return e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1
}
