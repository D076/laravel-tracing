const base = window.__tracing?.apiBase ?? '/tracing/api'

async function get(url) {
    const res = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
    if (!res.ok) {
        const body = await res.json().catch(() => null)
        throw new Error(body?.message ?? `HTTP ${res.status}`)
    }
    return res.json()
}

export function fetchRequests(params = {}) {
    const entries = Object.entries(params).filter(
        ([, v]) => v !== '' && v !== null && v !== undefined && v !== false,
    )
    const query = new URLSearchParams(entries)
    return get(`${base}/requests?${query}`)
}

export function fetchRequest(id) {
    return get(`${base}/requests/${encodeURIComponent(id)}`)
}

export function fetchOutgoing(params = {}) {
    const entries = Object.entries(params).filter(
        ([, v]) => v !== '' && v !== null && v !== undefined && v !== false,
    )
    const query = new URLSearchParams(entries)
    return get(`${base}/outgoing?${query}`)
}

export function fetchOutgoingRequest(id) {
    return get(`${base}/outgoing/${encodeURIComponent(id)}`)
}
