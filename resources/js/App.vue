<script setup>
import { RouterView, RouterLink, useRoute } from 'vue-router'
import { computed } from 'vue'

const route = useRoute()
const isIncoming = computed(() => route.path === '/' || (route.path.length > 1 && !route.path.startsWith('/outgoing')))
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="max-w-7xl mx-auto px-6 py-3 flex items-center gap-6">
                <span class="text-base font-semibold text-gray-900 tracking-tight">Tracing</span>
                <nav class="flex gap-1">
                    <RouterLink
                        to="/"
                        :class="[
                            'px-3 py-1.5 rounded text-sm font-medium transition-colors',
                            isIncoming ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-800',
                        ]"
                    >Incoming</RouterLink>
                    <RouterLink
                        to="/outgoing"
                        :class="[
                            'px-3 py-1.5 rounded text-sm font-medium transition-colors',
                            route.path.startsWith('/outgoing') ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-800',
                        ]"
                    >Outgoing</RouterLink>
                </nav>
            </div>
        </header>
        <main class="max-w-7xl mx-auto px-6 py-6">
            <RouterView />
        </main>
    </div>
</template>
