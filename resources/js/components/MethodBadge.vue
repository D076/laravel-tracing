<script setup>
import { computed } from 'vue'

const props = defineProps({ method: String })

// Verbs carry their own token group rather than reusing the status colours, so
// a theme can recolour GET and POST without touching what 2xx and 5xx mean.
const cls = computed(() => ({
    GET:    'bg-method-get text-method-get-fg',
    POST:   'bg-method-post text-method-post-fg',
    PUT:    'bg-method-put text-method-put-fg',
    PATCH:  'bg-method-patch text-method-patch-fg',
    DELETE: 'bg-method-delete text-method-delete-fg',
}[props.method] ?? 'bg-method-other text-method-other-fg'))
</script>

<template>
    <span :class="['inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-semibold', cls]">
        {{ method }}
    </span>
</template>
