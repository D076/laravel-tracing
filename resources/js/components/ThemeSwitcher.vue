<script setup>
import { ref } from 'vue'
import { THEMES, currentTheme, applyTheme } from '../themes.js'

const active = ref(currentTheme())

function select(id) {
    active.value = id
    applyTheme(id)
}
</script>

<template>
    <!-- One theme is not a choice: a switcher that cannot switch is noise. -->
    <div v-if="THEMES.length > 1" class="flex items-center gap-0.5 rounded-lg border border-line bg-surface-sunken p-0.5">
        <button
            v-for="theme in THEMES"
            :key="theme.id"
            type="button"
            @click="select(theme.id)"
            :title="theme.label"
            :aria-label="theme.label"
            :aria-pressed="active === theme.id"
            :class="[
                'inline-flex items-center justify-center rounded-md p-1.5 transition-colors',
                active === theme.id
                    ? 'bg-surface text-fg-strong'
                    : 'text-fg-faint hover:text-fg',
            ]"
        >
            <svg
                viewBox="0 0 24 24"
                class="h-4 w-4"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path :d="theme.icon" />
            </svg>
        </button>
    </div>
</template>
