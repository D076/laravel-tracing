<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchRequest, fetchOutgoing } from '../api.js'
import StatusBadge from '../components/StatusBadge.vue'
import MethodBadge from '../components/MethodBadge.vue'
import JsonViewer from '../components/JsonViewer.vue'
import { formatDuration, durationClass, formatTime } from '../utils.js'

const route = useRoute()
const router = useRouter()

const record = ref(null)
const loading = ref(true)
const error = ref(null)
const copied = ref(false)
const shared = ref(false)
const outgoing = ref([])

onMounted(async () => {
    try {
        const [main, out] = await Promise.all([
            fetchRequest(route.params.id),
            fetchOutgoing({ trace_id: route.params.id, per_page: 100, sort: 'created_at', direction: 'asc' }),
        ])
        record.value = main.data
        outgoing.value = out.data
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
})

async function copyTraceId() {
    await navigator.clipboard.writeText(record.value.id)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
}

async function share() {
    await navigator.clipboard.writeText(window.location.href)
    shared.value = true
    setTimeout(() => { shared.value = false }, 1500)
}
</script>

<template>
    <div>
        <div class="mb-5">
            <button @click="router.back()" class="text-sm text-gray-500 hover:text-gray-800 transition-colors">
                ← Back to requests
            </button>
        </div>

        <div v-if="loading" class="text-gray-400 text-sm py-8 text-center">Loading...</div>
        <div v-else-if="error" class="text-red-500 text-sm py-8 text-center">{{ error }}</div>

        <template v-else-if="record">
            <!-- Header card -->
            <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
                <div class="flex items-center gap-3 flex-wrap">
                    <MethodBadge :method="record.method" />
                    <StatusBadge :status="record.response_status" />
                    <span class="font-mono text-sm text-gray-800 flex-1 min-w-0 truncate">{{ record.url }}</span>
                    <span :class="['text-sm font-mono', durationClass(record.duration_ms)]">{{ formatDuration(record.duration_ms) }}</span>
                    <span class="text-sm text-gray-400 whitespace-nowrap">{{ formatTime(record.created_at) }}</span>
                    <button @click="share" class="text-xs text-gray-400 hover:text-gray-700 transition-colors whitespace-nowrap">
                        {{ shared ? '✓ Copied' : 'Share' }}
                    </button>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs">
                    <div class="flex items-center gap-1.5">
                        <span class="text-gray-400">Trace ID</span>
                        <code class="font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded">{{ record.id }}</code>
                        <button @click="copyTraceId" class="text-gray-400 hover:text-gray-700 transition-colors">
                            {{ copied ? '✓ Copied' : 'Copy' }}
                        </button>
                    </div>
                    <div v-if="record.route_path" class="flex items-center gap-1.5">
                        <span class="text-gray-400">Route</span>
                        <code class="font-mono text-gray-700">
                            {{ record.route_name ? record.route_name + ' · ' : '' }}{{ record.route_path }}
                        </code>
                    </div>
                    <div v-if="record.authenticatable_id" class="flex items-center gap-1.5">
                        <span class="text-gray-400">User</span>
                        <code class="font-mono text-gray-700">{{ record.authenticatable_type }} #{{ record.authenticatable_id }}</code>
                    </div>
                </div>
            </div>

            <!-- Exception (prominent) -->
            <div v-if="record.exception" class="bg-red-50 border border-red-200 rounded-xl p-5 mb-4">
                <h2 class="text-sm font-semibold text-red-700 mb-3">Exception</h2>
                <div class="space-y-2 text-sm">
                    <div>
                        <div class="text-xs text-red-400 mb-0.5">Class</div>
                        <code class="font-mono text-red-800 break-all">{{ record.exception.class }}</code>
                    </div>
                    <div>
                        <div class="text-xs text-red-400 mb-0.5">Message</div>
                        <span class="text-red-700">{{ record.exception.message }}</span>
                    </div>
                    <div>
                        <div class="text-xs text-red-400 mb-0.5">Location</div>
                        <code class="font-mono text-red-700 text-xs break-all">{{ record.exception.file }}:{{ record.exception.line }}</code>
                    </div>
                </div>
            </div>

            <!-- Request -->
            <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">Request</h2>
                <div class="space-y-4">
                    <div>
                        <div class="text-xs text-gray-400 mb-1.5">Headers</div>
                        <JsonViewer :data="record.request_headers" />
                    </div>
                    <div v-if="record.query_params">
                        <div class="text-xs text-gray-400 mb-1.5">Query Params</div>
                        <JsonViewer :data="record.query_params" />
                    </div>
                    <div v-if="record.body_params">
                        <div class="text-xs text-gray-400 mb-1.5">Body</div>
                        <JsonViewer :data="record.body_params" />
                    </div>
                </div>
            </div>

            <!-- Response -->
            <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">Response</h2>
                <div class="space-y-4">
                    <div>
                        <div class="text-xs text-gray-400 mb-1.5">Headers</div>
                        <JsonViewer :data="record.response_headers" />
                    </div>
                    <div v-if="record.response_body">
                        <div class="text-xs text-gray-400 mb-1.5">Body</div>
                        <JsonViewer :data="record.response_body" />
                    </div>
                </div>
            </div>

            <!-- Outgoing HTTP -->
            <div v-if="outgoing.length > 0" class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-2">
                    <h2 class="text-sm font-semibold text-gray-700">Outgoing HTTP</h2>
                    <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">{{ outgoing.length }}</span>
                </div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        <tr
                            v-for="r in outgoing"
                            :key="r.id"
                            @click="router.push('/outgoing/' + r.id)"
                            class="hover:bg-gray-50 cursor-pointer transition-colors"
                        >
                            <td class="px-4 py-2.5 w-[90px]"><MethodBadge :method="r.method" /></td>
                            <td class="px-4 py-2.5 w-[80px]">
                                <div class="flex items-center gap-1">
                                    <StatusBadge v-if="r.response_status" :status="r.response_status" />
                                    <span v-else class="text-gray-400 text-xs italic">—</span>
                                    <span v-if="r.has_exception" class="text-red-400 text-xs">⚠</span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 max-w-0">
                                <div class="truncate text-xs font-mono text-gray-700">{{ r.url }}</div>
                            </td>
                            <td class="px-4 py-2.5 w-[90px]">
                                <span :class="['text-xs font-mono', durationClass(r.duration_ms)]">{{ formatDuration(r.duration_ms) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Meta -->
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h2 class="text-sm font-semibold text-gray-700 mb-3">Meta</h2>
                <div class="text-sm space-y-1.5">
                    <div class="flex gap-2">
                        <span class="text-gray-400 w-24 shrink-0">IP Address</span>
                        <code class="font-mono text-gray-700">{{ record.ip_address ?? '—' }}</code>
                    </div>
                    <div class="flex gap-2">
                        <span class="text-gray-400 w-24 shrink-0">User Agent</span>
                        <span class="text-gray-700 text-xs break-all">{{ record.user_agent ?? '—' }}</span>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
