<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchOutgoingRequest } from '../api.js'
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
const rawRequestBody = ref(false)
const rawResponseBody = ref(false)

onMounted(async () => {
    try {
        const res = await fetchOutgoingRequest(route.params.id)
        record.value = res.data
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
})

async function copy(text) {
    await navigator.clipboard.writeText(text)
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
                ← Back
            </button>
        </div>

        <div v-if="loading" class="text-fg-faint text-sm py-8 text-center">Loading...</div>
        <div v-else-if="error" class="text-danger text-sm py-8 text-center">{{ error }}</div>

        <template v-else-if="record">
            <!-- Header -->
            <div class="bg-surface rounded-xl border border-line p-5 mb-4">
                <div class="flex items-center gap-3 flex-wrap">
                    <MethodBadge :method="record.method" />
                    <StatusBadge v-if="record.response_status" :status="record.response_status" />
                    <span v-else class="text-xs text-fg-faint italic">no response</span>
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
                        @select="router.push({ path: '/outgoing', query: { tag: t } })"
                    />
                </div>

                <div v-if="record.trace_id" class="mt-3 flex items-center gap-1.5 text-xs">
                    <span class="text-fg-faint">Trace ID</span>
                    <RouterLink
                        :to="'/' + record.trace_id"
                        class="font-mono text-link hover:text-link-hover bg-link-surface px-1.5 py-0.5 rounded"
                    >{{ record.trace_id }}</RouterLink>
                    <span class="text-fg-faint ml-1">← view incoming request</span>
                </div>
            </div>

            <!-- Exception -->
            <div v-if="record.exception_class" class="bg-danger-surface border border-danger-line rounded-xl p-5 mb-4">
                <h2 class="text-sm font-semibold text-danger mb-3">Exception</h2>
                <div class="space-y-2 text-sm">
                    <div>
                        <div class="text-xs text-danger-faint mb-0.5">Class</div>
                        <code class="font-mono text-danger-strong break-all">{{ record.exception_class }}</code>
                    </div>
                    <div v-if="record.exception_message">
                        <div class="text-xs text-danger-faint mb-0.5">Message</div>
                        <span class="text-danger">{{ record.exception_message }}</span>
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
                    <div v-if="record.request_body">
                        <div class="text-xs text-fg-faint mb-1.5 flex items-center gap-2">
                            <span>Body</span>
                            <span v-if="record.request_body_truncated" class="text-metric-warn">truncated</span>
                            <button
                                v-if="record.request_body_params"
                                @click="rawRequestBody = !rawRequestBody"
                                class="text-fg-faint hover:text-fg transition-colors"
                            >{{ rawRequestBody ? 'Parsed' : 'Raw' }}</button>
                        </div>
                        <JsonViewer :data="rawRequestBody ? record.request_body : (record.request_body_params ?? record.request_body)" />
                    </div>
                </div>
            </div>

            <!-- Response -->
            <div class="bg-surface rounded-xl border border-line p-5">
                <h2 class="text-sm font-semibold text-fg mb-4">Response</h2>
                <div class="space-y-4">
                    <div>
                        <div class="text-xs text-fg-faint mb-1.5">Headers</div>
                        <JsonViewer :data="record.response_headers" />
                    </div>
                    <div v-if="record.response_body">
                        <div class="text-xs text-fg-faint mb-1.5 flex items-center gap-2">
                            <span>Body</span>
                            <span v-if="record.response_body_truncated" class="text-metric-warn">truncated</span>
                            <button
                                v-if="record.response_body_params"
                                @click="rawResponseBody = !rawResponseBody"
                                class="text-fg-faint hover:text-fg transition-colors"
                            >{{ rawResponseBody ? 'Parsed' : 'Raw' }}</button>
                        </div>
                        <JsonViewer :data="rawResponseBody ? record.response_body : (record.response_body_params ?? record.response_body)" />
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
