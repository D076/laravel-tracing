<script setup>
import { RouterView, RouterLink, useRoute } from 'vue-router'
import { computed } from 'vue'
import ThemeSwitcher from './components/ThemeSwitcher.vue'

const route = useRoute()
const isIncoming = computed(() => route.path === '/' || (route.path.length > 1 && !route.path.startsWith('/outgoing')))
</script>

<template>
    <div class="min-h-screen bg-canvas">
        <header class="bg-surface border-b border-line sticky top-0 z-10">
            <div class="max-w-7xl mx-auto px-6 py-3 flex items-center gap-6">
                <span class="text-base font-semibold text-fg-strong tracking-tight">Tracing</span>
                <nav class="flex gap-1">
                    <RouterLink
                        to="/"
                        :class="[
                            'px-3 py-1.5 rounded text-sm font-medium transition-colors',
                            isIncoming ? 'bg-surface-sunken text-fg-strong' : 'text-fg-muted hover:text-fg',
                        ]"
                    >Incoming</RouterLink>
                    <RouterLink
                        to="/outgoing"
                        :class="[
                            'px-3 py-1.5 rounded text-sm font-medium transition-colors',
                            route.path.startsWith('/outgoing') ? 'bg-surface-sunken text-fg-strong' : 'text-fg-muted hover:text-fg',
                        ]"
                    >Outgoing</RouterLink>
                </nav>
                <ThemeSwitcher class="ml-auto" />
            </div>
        </header>
        <main class="max-w-7xl mx-auto px-6 py-6">
            <RouterView />
        </main>
    </div>
</template>
