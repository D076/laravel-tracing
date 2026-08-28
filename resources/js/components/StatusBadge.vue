<script setup>
import { computed } from 'vue'

const props = defineProps({ status: Number })

// Class names are written out in full: Tailwind scans the source as text and
// would never see a name assembled at runtime.
const cls = computed(() => {
    const s = props.status
    if (s >= 500) return 'bg-status-error text-status-error-fg'
    if (s >= 400) return 'bg-status-warning text-status-warning-fg'
    if (s >= 300) return 'bg-status-info text-status-info-fg'
    return 'bg-status-success text-status-success-fg'
})
</script>

<template>
    <span :class="['inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-semibold', cls]">
        {{ status }}
    </span>
</template>
