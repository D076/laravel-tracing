<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchRequest, fetchOutgoing } from '../api.js'
import StatusBadge from '../components/StatusBadge.vue'
import MethodBadge from '../components/MethodBadge.vue'
import TagBadge from '../components/TagBadge.vue'
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
            <button @click="router.back()" class="text-sm text-fg-muted hover:text-fg transition-colors">
                ← Back to requests
            </button>
        </div>

        <div v-if="loading" class="text-fg-faint text-sm py-8 text-center">Loading...</div>
        <div v-else-if="error" class="text-danger text-sm py-8 text-center">{{ error }}</div>

        <template v-else-if="record">
            <!-- Header card -->
            <div class="bg-surface rounded-xl border border-line p-5 mb-4">
                <div class="flex items-center gap-3 flex-wrap">
                    <MethodBadge :method="record.method" />
                    <StatusBadge :status="record.response_status" />
                    <span class="font-mono text-sm text-fg flex-1 min-w-0 truncate">{{ record.url }}</span>
                    <span :class="['text-sm font-mono', durationClass(record.duration_ms)]">{{ formatDuration(record.duration_ms) }}</span>
                    <span class="text-sm text-fg-faint whitespace-nowrap">{{ formatTime(record.created_at) }}</span>
                    <button @click="share" class="text-xs text-fg-faint hover:text-fg transition-colors whitespace-nowrap">
                        {{ shared ? '✓ Copied' : 'Share' }}
                    </button>
                </div>

                <div v-if="record.tags?.length" class="mt-3 flex items-center gap-1.5 flex-wrap text-xs">
                    <span class="text-fg-faint">Tags</span>
                    <TagBadge
                        v-for="t in record.tags"
                        :key="t"
                        :tag="t"
                        clickable
                        @select="router.push({ path: '/', query: { tag: t } })"
                    />
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs">
                    <div class="flex items-center gap-1.5">
                        <span class="text-fg-faint">Trace ID</span>
                        <code class="font-mono text-fg bg-surface-sunken px-1.5 py-0.5 rounded">{{ record.id }}</code>
                        <button @click="copyTraceId" class="text-fg-faint hover:text-fg transition-colors">
                            {{ copied ? '✓ Copied' : 'Copy' }}
                        </button>
                    </div>
                    <div v-if="record.route_path" class="flex items-center gap-1.5">
                        <span class="text-fg-faint">Route</span>
                        <code class="font-mono text-fg">
                            {{ record.route_name ? record.route_name + ' · ' : '' }}{{ record.route_path }}
                        </code>
                    </div>
                    <div v-if="record.authenticatable_id" class="flex items-center gap-1.5">
                        <span class="text-fg-faint">User</span>
                        <code class="font-mono text-fg">{{ record.authenticatable_type }} #{{ record.authenticatable_id }}</code>
                    </div>
                </div>
            </div>

            <!-- Exception (prominent) -->
            <div v-if="record.exception" class="bg-danger-surface border border-danger-line rounded-xl p-5 mb-4">
                <h2 class="text-sm font-semibold text-danger mb-3">Exception</h2>
                <div class="space-y-2 text-sm">
                    <div>
                        <div class="text-xs text-danger-faint mb-0.5">Class</div>
                        <code class="font-mono text-danger-strong break-all">{{ record.exception.class }}</code>
                    </div>
                    <div>
                        <div class="text-xs text-danger-faint mb-0.5">Message</div>
                        <span class="text-danger">{{ record.exception.message }}</span>
                    </div>
                    <div>
                        <div class="text-xs text-danger-faint mb-0.5">Location</div>
                        <code class="font-mono text-danger text-xs break-all">{{ record.exception.file }}:{{ record.exception.line }}</code>
                    </div>
                </div>
            </div>

            <!-- Request -->
            <div class="bg-surface rounded-xl border border-line p-5 mb-4">
                <h2 class="text-sm font-semibold text-fg mb-4">Request</h2>
                <div class="space-y-4">
                    <div>
                        <div class="text-xs text-fg-faint mb-1.5">Headers</div>
                        <JsonViewer :data="record.request_headers" />
                    </div>
                    <div v-if="record.query_params">
                        <div class="text-xs text-fg-faint mb-1.5">Query Params</div>
                        <JsonViewer :data="record.query_params" />
                    </div>
                    <div v-if="record.body_params">
                        <div class="text-xs text-fg-faint mb-1.5">Body</div>
                        <JsonViewer :data="record.body_params" />
                    </div>
                </div>
            </div>

            <!-- Response -->
            <div class="bg-surface rounded-xl border border-line p-5 mb-4">
                <h2 class="text-sm font-semibold text-fg mb-4">Response</h2>
                <div class="space-y-4">
                    <div>
                        <div class="text-xs text-fg-faint mb-1.5">Headers</div>
                        <JsonViewer :data="record.response_headers" />
                    </div>
                    <div v-if="record.response_body">
                        <div class="text-xs text-fg-faint mb-1.5">Body</div>
                        <JsonViewer :data="record.response_body" />
                    </div>
                </div>
            </div>

            <!-- Outgoing HTTP -->
            <div v-if="outgoing.length > 0" class="bg-surface rounded-xl border border-line overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-line-subtle flex items-center gap-2">
                    <h2 class="text-sm font-semibold text-fg">Outgoing HTTP</h2>
                    <span class="text-xs text-fg-faint bg-surface-sunken px-1.5 py-0.5 rounded">{{ outgoing.length }}</span>
                </div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-line-subtle">
                        <tr
                            v-for="r in outgoing"
                            :key="r.id"
                            @click="router.push('/outgoing/' + r.id)"
                            class="hover:bg-surface-sunken cursor-pointer transition-colors"
                        >
                            <td class="px-4 py-2.5 w-[90px]"><MethodBadge :method="r.method" /></td>
                            <td class="px-4 py-2.5 w-[80px]">
                                <div class="flex items-center gap-1">
                                    <StatusBadge v-if="r.response_status" :status="r.response_status" />
                                    <span v-else class="text-fg-faint text-xs italic">—</span>
                                    <span v-if="r.has_exception" class="text-danger-faint text-xs">⚠</span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 max-w-0">
                                <div class="truncate text-xs font-mono text-fg">{{ r.url }}</div>
                            </td>
                            <td class="px-4 py-2.5 w-[90px]">
                                <span :class="['text-xs font-mono', durationClass(r.duration_ms)]">{{ formatDuration(r.duration_ms) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Meta -->
            <div class="bg-surface rounded-xl border border-line p-5">
                <h2 class="text-sm font-semibold text-fg mb-3">Meta</h2>
                <div class="text-sm space-y-1.5">
                    <div class="flex gap-2">
                        <span class="text-fg-faint w-24 shrink-0">IP Address</span>
                        <code class="font-mono text-fg">{{ record.ip_address ?? '—' }}</code>
                    </div>
                    <div class="flex gap-2">
                        <span class="text-fg-faint w-24 shrink-0">User Agent</span>
                        <span class="text-fg text-xs break-all">{{ record.user_agent ?? '—' }}</span>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
