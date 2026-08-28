<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { fetchOutgoing } from '../api.js'
import StatusBadge from '../components/StatusBadge.vue'
import MethodBadge from '../components/MethodBadge.vue'
import TagBadge from '../components/TagBadge.vue'
import { formatDuration, durationClass, timeAgo, formatTime, wantsNewTab } from '../utils.js'

const router = useRouter()
const route = useRoute()

// Filters/sort/pagination are seeded from the URL query so they survive
// navigation to a detail page and back, and can be shared or opened in a new tab.
const q = route.query

const requests = ref([])
const meta = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0 })
const loading = ref(false)
const error = ref(null)
const page = ref(Number(q.page) || 1)
const sort = ref(q.sort ?? 'created_at')
const direction = ref(q.direction ?? 'desc')

const filters = reactive({
    status_group: q.status_group ? String(q.status_group).split(',') : [],
    method: q.method ?? '',
    date_from: q.date_from ?? '',
    date_to: q.date_to ?? '',
    has_exception: q.has_exception === '1',
    search: q.search ?? '',
    tag: q.tag ?? '',
    payload: q.payload ?? '',
})

// Deep search is expensive (it scans bodies), so it is bound to a separate input
// and only runs on Enter — never on every keystroke like the cheap search.
const payloadInput = ref(q.payload ?? '')

const STATUS_GROUPS = ['2xx', '3xx', '4xx', '5xx']
const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']

const hasActiveFilters = computed(() =>
    filters.status_group.length > 0 ||
    filters.method ||
    filters.date_from ||
    filters.date_to ||
    filters.has_exception ||
    filters.search ||
    filters.tag ||
    filters.payload,
)

function buildQuery() {
    const query = {}
    if (filters.status_group.length) query.status_group = filters.status_group.join(',')
    if (filters.method) query.method = filters.method
    if (filters.date_from) query.date_from = filters.date_from
    if (filters.date_to) query.date_to = filters.date_to
    if (filters.has_exception) query.has_exception = '1'
    if (filters.search) query.search = filters.search
    if (filters.tag) query.tag = filters.tag
    if (filters.payload) query.payload = filters.payload
    if (sort.value !== 'created_at') query.sort = sort.value
    if (direction.value !== 'desc') query.direction = direction.value
    if (page.value > 1) query.page = String(page.value)
    return query
}

// Mirror current filter/sort/page state into the URL (replace, so typing
// doesn't flood history). Returning from a detail view restores this URL.
function syncQuery() {
    router.replace({ query: buildQuery() })
}

async function load() {
    syncQuery()
    loading.value = true
    error.value = null
    try {
        const res = await fetchOutgoing({
            status_group: filters.status_group.join(',') || undefined,
            method: filters.method || undefined,
            date_from: filters.date_from || undefined,
            date_to: filters.date_to || undefined,
            has_exception: filters.has_exception || undefined,
            search: filters.search || undefined,
            tag: filters.tag || undefined,
            payload: filters.payload || undefined,
            sort: sort.value,
            direction: direction.value,
            page: page.value,
        })
        requests.value = res.data
        meta.value = res.meta
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}

// Open in place on a plain click; in a new tab on modifier/middle click so the
// table rows behave like real links.
function openRow(r, e) {
    // auxclick fires for middle (1) and right (2) buttons; only middle navigates,
    // so a right click can still open the context menu.
    if (e.type === 'auxclick' && e.button !== 1) return
    const to = '/outgoing/' + r.id
    if (wantsNewTab(e)) {
        e.preventDefault()
        window.open(router.resolve(to).href, '_blank', 'noopener')
    } else {
        router.push(to)
    }
}

function toggleStatusGroup(group) {
    const idx = filters.status_group.indexOf(group)
    if (idx === -1) filters.status_group.push(group)
    else filters.status_group.splice(idx, 1)
}

function toggleSort(column) {
    if (sort.value === column) {
        direction.value = direction.value === 'desc' ? 'asc' : 'desc'
    } else {
        sort.value = column
        direction.value = 'desc'
    }
    page.value = 1
}

function clearFilters() {
    filters.status_group = []
    filters.method = ''
    filters.date_from = ''
    filters.date_to = ''
    filters.has_exception = false
    filters.search = ''
    filters.tag = ''
    filters.payload = ''
    payloadInput.value = ''
    // No page reset here — the filter watcher handles it via reloadFromFirstPage().
}

// Set the exact-tag filter (from clicking a tag chip in a row).
function filterByTag(tag) {
    filters.tag = tag
}

// Commit the deep-search term (Enter or the Search button) — this is what
// actually triggers the expensive query.
function runPayloadSearch() {
    filters.payload = payloadInput.value.trim()
}

// Any filter change resets to page 1. Resetting the page fires the [sort,
// direction, page] watcher, which loads — so only load() here when the page
// was already 1, otherwise an expensive deep search would run twice.
function reloadFromFirstPage() {
    if (page.value !== 1) page.value = 1
    else load()
}

let debounceTimer = null
function scheduleLoad() {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(reloadFromFirstPage, 400)
}

watch(() => [filters.status_group.join(), filters.method, filters.has_exception, filters.date_from, filters.date_to, filters.tag, filters.payload], reloadFromFirstPage)
watch([sort, direction, page], load)

onMounted(load)
</script>

<template>
    <!-- Filters -->
    <div class="bg-surface rounded-xl border border-line p-4 mb-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex gap-1">
                <button
                    v-for="g in STATUS_GROUPS"
                    :key="g"
                    @click="toggleStatusGroup(g)"
                    :class="[
                        'px-2.5 py-1 rounded text-xs font-medium border transition-colors',
                        filters.status_group.includes(g)
                            ? 'bg-accent text-accent-fg border-accent'
                            : 'bg-surface text-fg-muted border-line-input hover:border-line-input-hover',
                    ]"
                >{{ g }}</button>
            </div>

            <select
                v-model="filters.method"
                class="text-sm border border-line-input rounded px-2 py-1 bg-surface text-fg focus:outline-none focus:ring-1 focus:ring-focus"
            >
                <option value="">All methods</option>
                <option v-for="m in METHODS" :key="m">{{ m }}</option>
            </select>

            <label class="flex items-center gap-1.5 text-sm text-fg-muted cursor-pointer select-none">
                <input type="checkbox" v-model="filters.has_exception" class="rounded" />
                Exceptions only
            </label>

            <button
                v-if="hasActiveFilters"
                @click="clearFilters"
                class="ml-auto text-xs text-fg-faint hover:text-fg underline"
            >Clear filters</button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <input type="date" v-model="filters.date_from"
                class="text-sm border border-line-input rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-focus" />
            <span class="text-fg-faint text-sm">—</span>
            <input type="date" v-model="filters.date_to"
                class="text-sm border border-line-input rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-focus" />

            <input
                v-model="filters.search"
                @input="scheduleLoad"
                placeholder="URL, Trace ID, or tag..."
                class="text-sm border border-line-input rounded px-2.5 py-1 focus:outline-none focus:ring-1 focus:ring-focus flex-1 min-w-52"
            />

            <button
                @click="load"
                class="text-sm px-3 py-1 rounded border border-line-input hover:bg-surface-sunken text-fg-muted"
                title="Refresh"
            >↻</button>
        </div>

        <!-- Deep search: scans bodies, so it only runs on Enter / button -->
        <div class="flex flex-wrap items-center gap-2 border-t border-line-subtle pt-3">
            <input
                v-model="payloadInput"
                @keyup.enter="runPayloadSearch"
                placeholder="Deep search: bodies, params, headers..."
                class="text-sm border border-line-input rounded px-2.5 py-1 focus:outline-none focus:ring-1 focus:ring-focus flex-1 min-w-52"
            />
            <button
                @click="runPayloadSearch"
                class="text-sm px-3 py-1 rounded border border-line-input hover:bg-surface-sunken text-fg-muted whitespace-nowrap"
            >Search</button>
            <span class="text-xs text-fg-faint">Scans request/response bodies — slow on large datasets</span>
        </div>

        <!-- Active tag filter -->
        <div v-if="filters.tag" class="flex items-center gap-1.5 text-xs text-fg-muted">
            <span>Tag:</span>
            <TagBadge :tag="filters.tag" />
            <button @click="filters.tag = ''" class="text-fg-faint hover:text-fg" title="Clear tag filter">×</button>
        </div>

        <!-- Active deep search -->
        <div v-if="filters.payload" class="flex items-center gap-1.5 text-xs text-fg-muted">
            <span>Deep search:</span>
            <code class="font-mono bg-surface-sunken px-1.5 py-0.5 rounded text-fg">{{ filters.payload }}</code>
            <button
                @click="filters.payload = ''; payloadInput = ''"
                class="text-fg-faint hover:text-fg"
                title="Clear deep search"
            >×</button>
        </div>
    </div>

    <!-- Table -->
    <div class="bg-surface rounded-xl border border-line overflow-hidden">
        <div v-if="loading" class="flex items-center justify-center py-16 text-fg-faint text-sm">Loading...</div>
        <div v-else-if="error" class="flex items-center justify-center py-16 text-danger text-sm">{{ error }}</div>
        <div v-else-if="requests.length === 0" class="flex items-center justify-center py-16 text-fg-faint text-sm">No outgoing requests found.</div>

        <table v-else class="w-full text-sm">
            <thead>
                <tr class="border-b border-line bg-surface-sunken">
                    <th class="text-left font-medium text-fg-muted px-4 py-3 w-[90px]">Method</th>
                    <th
                        class="text-left font-medium text-fg-muted px-4 py-3 w-[90px] cursor-pointer hover:text-fg select-none"
                        @click="toggleSort('response_status')"
                    >Status <span class="text-fg-faint">{{ sort === 'response_status' ? (direction === 'desc' ? '↓' : '↑') : '' }}</span></th>
                    <th class="text-left font-medium text-fg-muted px-4 py-3">URL</th>
                    <th
                        class="text-left font-medium text-fg-muted px-4 py-3 w-[110px] cursor-pointer hover:text-fg select-none"
                        @click="toggleSort('duration_ms')"
                    >Duration <span class="text-fg-faint">{{ sort === 'duration_ms' ? (direction === 'desc' ? '↓' : '↑') : '' }}</span></th>
                    <th
                        class="text-left font-medium text-fg-muted px-4 py-3 w-[120px] cursor-pointer hover:text-fg select-none"
                        @click="toggleSort('created_at')"
                    >Time <span class="text-fg-faint">{{ sort === 'created_at' ? (direction === 'desc' ? '↓' : '↑') : '' }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line-subtle">
                <tr
                    v-for="r in requests"
                    :key="r.id"
                    @click="openRow(r, $event)"
                    @auxclick="openRow(r, $event)"
                    class="group hover:bg-surface-sunken cursor-pointer transition-colors"
                >
                    <td class="px-4 py-3"><MethodBadge :method="r.method" /></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1.5">
                            <StatusBadge v-if="r.response_status" :status="r.response_status" />
                            <span v-else class="text-fg-faint text-xs italic">no resp</span>
                            <span v-if="r.has_exception" class="text-danger-faint text-xs" title="Exception">⚠</span>
                        </div>
                    </td>
                    <td class="px-4 py-3 max-w-0">
                        <div class="truncate text-xs font-mono text-fg">{{ r.url }}</div>
                        <div v-if="r.trace_id" class="text-xs text-fg-faint font-mono truncate mt-0.5">{{ r.trace_id }}</div>
                        <div v-if="r.tags?.length" class="flex flex-wrap gap-1 mt-1">
                            <TagBadge v-for="t in r.tags" :key="t" :tag="t" clickable @select="filterByTag" />
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <span :class="['text-xs font-mono', durationClass(r.duration_ms)]">
                            {{ formatDuration(r.duration_ms) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-fg-faint" :title="formatTime(r.created_at)">
                        <div class="flex items-center justify-between gap-2">
                            <span>{{ timeAgo(r.created_at) }}</span>
                            <RouterLink
                                :to="'/outgoing/' + r.id"
                                target="_blank"
                                @click.stop
                                @auxclick.stop
                                class="opacity-0 group-hover:opacity-100 text-fg-faint hover:text-fg transition-opacity"
                                title="Open in new tab"
                            >↗</RouterLink>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="mt-4 flex items-center justify-between text-sm text-fg-muted">
        <span>{{ meta.total.toLocaleString() }} total</span>
        <div v-if="meta.last_page > 1" class="flex items-center gap-2">
            <span class="text-fg-faint">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
            <button :disabled="meta.current_page <= 1" @click="page--"
                class="px-3 py-1 rounded border border-line-input disabled:opacity-40 hover:bg-surface-sunken disabled:cursor-not-allowed">← Prev</button>
            <button :disabled="meta.current_page >= meta.last_page" @click="page++"
                class="px-3 py-1 rounded border border-line-input disabled:opacity-40 hover:bg-surface-sunken disabled:cursor-not-allowed">Next →</button>
        </div>
    </div>
</template>
