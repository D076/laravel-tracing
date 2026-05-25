<script setup>
import { computed } from 'vue'

const props = defineProps({ status: Number })

const cls = computed(() => {
    const s = props.status
    if (s >= 500) return 'bg-red-100 text-red-700'
    if (s >= 400) return 'bg-amber-100 text-amber-700'
    if (s >= 300) return 'bg-blue-100 text-blue-700'
    return 'bg-emerald-100 text-emerald-700'
})
</script>

<template>
    <span :class="['inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-semibold', cls]">
        {{ status }}
    </span>
</template>
