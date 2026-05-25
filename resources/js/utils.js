export function formatDuration(ms) {
    if (ms === null || ms === undefined) return '—'
    if (ms < 1000) return `${ms}ms`
    return `${(ms / 1000).toFixed(2)}s`
}

export function durationClass(ms) {
    if (ms === null || ms === undefined) return 'text-gray-400'
    if (ms > 1000) return 'text-red-600 font-medium'
    if (ms > 500) return 'text-amber-600'
    return 'text-gray-600'
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
