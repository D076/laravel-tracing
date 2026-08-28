<script setup>
const props = defineProps({
    tag: { type: String, required: true },
    clickable: { type: Boolean, default: false },
})
const emit = defineEmits(['select'])

// Stop propagation so clicking a tag inside a table row filters instead of
// opening the row's detail view.
function onClick(e) {
    if (!props.clickable) return
    e.stopPropagation()
    emit('select', props.tag)
}
</script>

<template>
    <span
        @click="onClick"
        :class="[
            'inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-medium border border-tag-line bg-tag text-tag-fg whitespace-nowrap',
            clickable ? 'cursor-pointer hover:bg-tag-hover' : '',
        ]"
        :title="clickable ? 'Filter by this tag' : tag"
    >{{ tag }}</span>
</template>
