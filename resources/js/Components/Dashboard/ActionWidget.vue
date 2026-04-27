<script setup lang="ts">
// ─── ActionWidget.vue ─────────────────────────────────────────────────────────
// Reusable dashboard widget: every dept dashboard uses this component.
// Shows: title + description + clickable action rows with badge + sublabel.
// Loading state shows skeleton; empty state shows emptyMessage.
// "View all" link in footer navigates to viewAllHref.
// ─────────────────────────────────────────────────────────────────────────────

import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { ArrowRightIcon } from '@heroicons/vue/24/outline'

export interface ActionItem {
    label: string       // Primary row text (e.g. 'Mildred Testpatient: SOAP Note')
    href?: string       // Direct link to the specific item. When omitted, the row
                        // renders as non-interactive (most dept dashboards don't
                        // yet wire up per-item deep links: see backlog).
    badge?: string      // Optional badge text (e.g. '3d overdue', 'Critical')
    badgeColor?: string // Tailwind classes for the badge chip
    sublabel?: string   // Secondary text shown below label (e.g. 'MRN 00042 | 09:30')
}

const props = withDefaults(defineProps<{
    title: string
    description?: string
    items: ActionItem[]
    emptyMessage: string
    viewAllHref?: string
    maxItems?: number
    loading?: boolean
}>(), {
    maxItems: 5,
    loading: false,
})

const visible = computed(() => props.items.slice(0, props.maxItems))
const overflow = computed(() => props.items.length - props.maxItems)

function navigate(href: string | undefined) {
    if (!href) {
        // Item without a deep link: silently no-op rather than crash.
        // Backlog: wire per-item href on dept dashboards (see ItAdminDashboard etc.)
        return
    }
    router.visit(href)
}
</script>

<template>
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm flex flex-col">

        <!-- Header -->
        <div class="px-4 py-3 border-b border-gray-200 dark:border-slate-700">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {{ title }}
                </h3>
                <span
                    v-if="!loading && items.length > 0"
                    class="text-sm font-medium text-gray-400 dark:text-slate-500 tabular-nums shrink-0"
                >
                    {{ items.length }}
                </span>
            </div>
            <p v-if="description" class="text-sm italic text-gray-400 dark:text-slate-500 mt-0.5 leading-relaxed">
                {{ description }}
            </p>
        </div>

        <!-- Body -->
        <div class="flex-1 px-4 py-2">
            <!-- Loading skeleton -->
            <div v-if="loading" class="space-y-2.5 animate-pulse py-1">
                <div v-for="i in 3" :key="i" class="flex items-center gap-2">
                    <div class="h-4 bg-slate-100 dark:bg-slate-700 rounded flex-1" />
                    <div class="h-4 w-14 bg-slate-100 dark:bg-slate-700 rounded" />
                </div>
            </div>

            <!-- Empty state -->
            <p v-else-if="items.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-4 text-center">
                {{ emptyMessage }}
            </p>

            <!-- Item list -->
            <ul v-else class="divide-y divide-gray-50 dark:divide-slate-700/60">
                <li v-for="(item, idx) in visible" :key="idx">
                    <button
                        type="button"
                        :disabled="!item.href"
                        :class="[
                            'w-full flex items-center justify-between gap-3 py-2.5 group -mx-4 px-4 transition-colors text-left',
                            item.href
                                ? 'hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer'
                                : 'cursor-default',
                        ]"
                        @click="navigate(item.href)"
                    >
                        <div class="min-w-0 flex-1">
                            <p
                                :class="[
                                    'text-sm font-medium text-gray-800 dark:text-slate-200 truncate',
                                    item.href ? 'group-hover:text-blue-600 dark:group-hover:text-blue-400' : '',
                                ]"
                            >
                                {{ item.label }}
                            </p>
                            <p v-if="item.sublabel" class="text-sm text-gray-400 dark:text-slate-500 mt-0.5 truncate">
                                {{ item.sublabel }}
                            </p>
                        </div>
                        <span
                            v-if="item.badge"
                            :class="['inline-flex shrink-0 items-center px-1.5 py-0.5 rounded text-sm font-semibold', item.badgeColor ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300']"
                        >
                            {{ item.badge }}
                        </span>
                    </button>
                </li>
                <li v-if="overflow > 0" class="py-2 text-sm text-gray-400 dark:text-slate-500 text-center">
                    and {{ overflow }} more...
                </li>
            </ul>
        </div>

        <!-- Footer: View All link -->
        <div v-if="viewAllHref" class="px-4 py-2.5 border-t border-gray-100 dark:border-slate-700/60">
            <button
                type="button"
                class="inline-flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:underline"
                @click="navigate(viewAllHref)"
            >
                View all
                <ArrowRightIcon class="w-3 h-3" aria-hidden="true" />
            </button>
        </div>

    </div>
</template>
