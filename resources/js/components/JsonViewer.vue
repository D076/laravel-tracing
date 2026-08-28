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
    <div v-if="formatted !== null" class="relative group rounded-lg border border-line bg-surface-sunken overflow-hidden">
        <button
            @click="copy"
            class="absolute top-2 right-5 text-xs px-2 py-0.5 rounded bg-surface border border-line text-fg-faint hover:text-fg hover:border-line-input transition-all opacity-0 group-hover:opacity-100 z-10"
        >{{ copied ? '✓' : 'Copy' }}</button>
        <pre class="overflow-auto p-3 text-xs text-fg max-h-80 leading-relaxed">{{ formatted }}</pre>
    </div>
    <span v-else class="text-fg-faint text-sm italic">—</span>
</template>
