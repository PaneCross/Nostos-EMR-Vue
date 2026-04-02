<script setup lang="ts">
// ─── ComingSoon.vue ───────────────────────────────────────────────────────────
// Placeholder page for features that are stubs or pending integration.
// ─────────────────────────────────────────────────────────────────────────────

import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { ClockIcon, TruckIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
    mode?: 'transport' | 'planned'
    description?: string
}>()
</script>

<template>
    <Head title="Coming Soon" />

    <AppShell>
        <template #header>
            <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">Coming Soon</h1>
        </template>

        <div class="flex items-center justify-center min-h-[60vh] p-8">
            <div class="max-w-md text-center">
                <div
                    :class="[
                        'mx-auto mb-4 w-16 h-16 rounded-full flex items-center justify-center',
                        mode === 'transport' ? 'bg-amber-100 dark:bg-amber-900/30' : 'bg-indigo-100 dark:bg-indigo-900/30',
                    ]"
                >
                    <TruckIcon v-if="mode === 'transport'" class="w-8 h-8 text-amber-600 dark:text-amber-400" />
                    <ClockIcon v-else class="w-8 h-8 text-indigo-600 dark:text-indigo-400" />
                </div>

                <h2
                    :class="[
                        'text-xl font-semibold mb-2',
                        mode === 'transport' ? 'text-amber-800 dark:text-amber-300' : 'text-indigo-800 dark:text-indigo-300',
                    ]"
                >
                    {{ mode === 'transport' ? 'Nostos Integration Pending' : 'Planned Feature' }}
                </h2>

                <p class="text-gray-600 dark:text-slate-400 text-sm">
                    {{ description ?? (mode === 'transport'
                        ? 'This feature requires the Nostos transport integration. Coming in a future release.'
                        : 'This feature is on the roadmap and will be available in a future release.') }}
                </p>
            </div>
        </div>
    </AppShell>
</template>
