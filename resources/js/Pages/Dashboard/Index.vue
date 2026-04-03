<script setup lang="ts">
// ─── Dashboard/Index.vue ───────────────────────────────────────────────────────
// Main dashboard page — renders a live dept-specific dashboard or a placeholder.
// Dept dashboards are lazy-loaded when the user's department is supported.
// ─────────────────────────────────────────────────────────────────────────────

import { computed } from 'vue'
import { usePage, Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import type { PageProps } from '@/types'

const page = usePage<PageProps>()
const user = computed(() => page.props.auth.user)
const departmentLabel = computed(() => page.props.department_label as string)
</script>

<template>
    <Head :title="`${departmentLabel} Dashboard`" />

    <AppShell>
        <template #header>
            <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                {{ departmentLabel }} Dashboard
            </h1>
        </template>

        <div class="p-6">
            <div
                class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-8 text-center"
            >
                <p class="text-gray-500 dark:text-slate-400 text-sm">
                    Welcome, {{ user.first_name }}. Dashboard widgets are being migrated to Vue.
                </p>
            </div>
        </div>
    </AppShell>
</template>
