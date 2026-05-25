<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
    data: { default: null },
})

const copied = ref(false)

const formatted = computed(() => {
    if (props.data === null || props.data === undefined) return null
    if (typeof props.data === 'string') {
        try { return JSON.stringify(JSON.parse(props.data), null, 2) } catch { return props.data }
    }
    return JSON.stringify(props.data, null, 2)
})

async function copy() {
    await navigator.clipboard.writeText(formatted.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
}
</script>

<template>
    <div v-if="formatted !== null" class="relative group rounded-lg border border-gray-200 bg-gray-50 overflow-hidden">
        <button
            @click="copy"
            class="absolute top-2 right-5 text-xs px-2 py-0.5 rounded bg-white border border-gray-200 text-gray-400 hover:text-gray-700 hover:border-gray-300 transition-all opacity-0 group-hover:opacity-100 z-10"
        >{{ copied ? '✓' : 'Copy' }}</button>
        <pre class="overflow-auto p-3 text-xs text-gray-800 max-h-80 leading-relaxed">{{ formatted }}</pre>
    </div>
    <span v-else class="text-gray-400 text-sm italic">—</span>
</template>
