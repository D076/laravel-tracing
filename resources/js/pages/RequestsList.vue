<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { fetchRequests } from '../api.js'
import StatusBadge from '../components/StatusBadge.vue'
import MethodBadge from '../components/MethodBadge.vue'
import { formatDuration, durationClass, timeAgo, formatTime } from '../utils.js'

const router = useRouter()

const requests = ref([])
const meta = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0 })
const loading = ref(false)
const error = ref(null)
const page = ref(1)
const sort = ref('created_at')
const direction = ref('desc')

const filters = reactive({
    status_group: [],
    method: '',
    route_path: '',
    date_from: '',
    date_to: '',
    has_exception: false,
    search: '',
})

const STATUS_GROUPS = ['2xx', '3xx', '4xx', '5xx']
const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']

const hasActiveFilters = computed(() =>
    filters.status_group.length > 0 ||
    filters.method ||
    filters.route_path ||
    filters.date_from ||
    filters.date_to ||
    filters.has_exception ||
    filters.search,
)

async function load() {
    loading.value = true
    error.value = null
    try {
        const res = await fetchRequests({
            status_group: filters.status_group.join(',') || undefined,
            method: filters.method || undefined,
            route_path: filters.route_path || undefined,
            date_from: filters.date_from || undefined,
            date_to: filters.date_to || undefined,
            has_exception: filters.has_exception || undefined,
            search: filters.search || undefined,
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
    filters.route_path = ''
    filters.date_from = ''
    filters.date_to = ''
    filters.has_exception = false
    filters.search = ''
    page.value = 1
}

let debounceTimer = null
function scheduleLoad() {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => { page.value = 1; load() }, 400)
}

// Immediate reload for select/checkbox/date filters
watch(() => [filters.status_group.join(), filters.method, filters.has_exception, filters.date_from, filters.date_to], () => {
    page.value = 1
    load()
})
watch([sort, direction, page], load)

onMounted(load)
</script>

<template>
    <!-- Filters -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <!-- Status group buttons -->
            <div class="flex gap-1">
                <button
                    v-for="g in STATUS_GROUPS"
                    :key="g"
                    @click="toggleStatusGroup(g)"
                    :class="[
                        'px-2.5 py-1 rounded text-xs font-medium border transition-colors',
                        filters.status_group.includes(g)
                            ? 'bg-gray-800 text-white border-gray-800'
                            : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500',
                    ]"
                >{{ g }}</button>
            </div>

            <!-- Method select -->
            <select
                v-model="filters.method"
                class="text-sm border border-gray-300 rounded px-2 py-1 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-gray-400"
            >
                <option value="">All methods</option>
                <option v-for="m in METHODS" :key="m">{{ m }}</option>
            </select>

            <!-- Exceptions only -->
            <label class="flex items-center gap-1.5 text-sm text-gray-600 cursor-pointer select-none">
                <input type="checkbox" v-model="filters.has_exception" class="rounded" />
                Exceptions only
            </label>

            <button
                v-if="hasActiveFilters"
                @click="clearFilters"
                class="ml-auto text-xs text-gray-400 hover:text-gray-700 underline"
            >Clear filters</button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <input
                v-model="filters.route_path"
                @input="scheduleLoad"
                placeholder="Route path..."
                class="text-sm border border-gray-300 rounded px-2.5 py-1 focus:outline-none focus:ring-1 focus:ring-gray-400 w-44"
            />

            <input
                type="date"
                v-model="filters.date_from"
                class="text-sm border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-gray-400"
            />
            <span class="text-gray-400 text-sm">—</span>
            <input
                type="date"
                v-model="filters.date_to"
                class="text-sm border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-gray-400"
            />

            <input
                v-model="filters.search"
                @input="scheduleLoad"
                placeholder="Trace ID, URL, or header..."
                class="text-sm border border-gray-300 rounded px-2.5 py-1 focus:outline-none focus:ring-1 focus:ring-gray-400 flex-1 min-w-52"
            />

            <button
                @click="load"
                class="text-sm px-3 py-1 rounded border border-gray-300 hover:bg-gray-50 text-gray-600"
                title="Refresh"
            >↻</button>
        </div>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div v-if="loading" class="flex items-center justify-center py-16 text-gray-400 text-sm">Loading...</div>
        <div v-else-if="error" class="flex items-center justify-center py-16 text-red-500 text-sm">{{ error }}</div>
        <div v-else-if="requests.length === 0" class="flex items-center justify-center py-16 text-gray-400 text-sm">No requests found.</div>

        <table v-else class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="text-left font-medium text-gray-500 px-4 py-3 w-[90px]">Method</th>
                    <th
                        class="text-left font-medium text-gray-500 px-4 py-3 w-[90px] cursor-pointer hover:text-gray-800 select-none"
                        @click="toggleSort('response_status')"
                    >Status <span class="text-gray-400">{{ sort === 'response_status' ? (direction === 'desc' ? '↓' : '↑') : '' }}</span></th>
                    <th class="text-left font-medium text-gray-500 px-4 py-3">Route / URL</th>
                    <th
                        class="text-left font-medium text-gray-500 px-4 py-3 w-[110px] cursor-pointer hover:text-gray-800 select-none"
                        @click="toggleSort('duration_ms')"
                    >Duration <span class="text-gray-400">{{ sort === 'duration_ms' ? (direction === 'desc' ? '↓' : '↑') : '' }}</span></th>
                    <th class="text-left font-medium text-gray-500 px-4 py-3 w-[120px]">IP</th>
                    <th
                        class="text-left font-medium text-gray-500 px-4 py-3 w-[120px] cursor-pointer hover:text-gray-800 select-none"
                        @click="toggleSort('created_at')"
                    >Time <span class="text-gray-400">{{ sort === 'created_at' ? (direction === 'desc' ? '↓' : '↑') : '' }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr
                    v-for="r in requests"
                    :key="r.id"
                    @click="router.push('/' + r.id)"
                    class="hover:bg-gray-50 cursor-pointer transition-colors"
                >
                    <td class="px-4 py-3"><MethodBadge :method="r.method" /></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1.5">
                            <StatusBadge :status="r.response_status" />
                            <span v-if="r.has_exception" class="text-red-400 text-xs leading-none" title="Exception thrown">⚠</span>
                        </div>
                    </td>
                    <td class="px-4 py-3 max-w-0">
                        <div class="truncate text-xs font-mono text-gray-800">{{ r.route_path ?? r.url }}</div>
                        <div v-if="r.route_path" class="truncate text-xs text-gray-400 mt-0.5">{{ r.url }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span :class="['text-xs font-mono', durationClass(r.duration_ms)]">
                            {{ formatDuration(r.duration_ms) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 font-mono">{{ r.ip_address ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs text-gray-400" :title="formatTime(r.created_at)">
                        {{ timeAgo(r.created_at) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="mt-4 flex items-center justify-between text-sm text-gray-500">
        <span>{{ meta.total.toLocaleString() }} total</span>
        <div v-if="meta.last_page > 1" class="flex items-center gap-2">
            <span class="text-gray-400">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
            <button
                :disabled="meta.current_page <= 1"
                @click="page--"
                class="px-3 py-1 rounded border border-gray-300 disabled:opacity-40 hover:bg-gray-50 disabled:cursor-not-allowed"
            >← Prev</button>
            <button
                :disabled="meta.current_page >= meta.last_page"
                @click="page++"
                class="px-3 py-1 rounded border border-gray-300 disabled:opacity-40 hover:bg-gray-50 disabled:cursor-not-allowed"
            >Next →</button>
        </div>
    </div>
</template>
