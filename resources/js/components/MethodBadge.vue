<script setup>
import { computed } from 'vue'

const props = defineProps({ method: String })

const cls = computed(() => ({
    GET:    'bg-sky-100 text-sky-700',
    POST:   'bg-emerald-100 text-emerald-700',
    PUT:    'bg-amber-100 text-amber-700',
    PATCH:  'bg-orange-100 text-orange-700',
    DELETE: 'bg-red-100 text-red-700',
}[props.method] ?? 'bg-gray-100 text-gray-600'))
</script>

<template>
    <span :class="['inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-semibold', cls]">
        {{ method }}
    </span>
</template>
