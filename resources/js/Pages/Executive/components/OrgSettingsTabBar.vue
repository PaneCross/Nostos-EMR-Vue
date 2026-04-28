<script setup lang="ts">
// ─── OrgSettingsTabBar ───────────────────────────────────────────────────────
// Shared top-level tab bar used by every Org-Settings sub-page. Each tab is its
// own Inertia route so the tab state lives in the URL itself (back button
// behaves naturally, deep links work).
//
// Tabs : Notifications | Job Titles | Credentials | Credentials Dashboard
// ─────────────────────────────────────────────────────────────────────────────
import { Link } from '@inertiajs/vue3'
import {
    BellAlertIcon,
    BriefcaseIcon,
    AcademicCapIcon,
    PresentationChartLineIcon,
} from '@heroicons/vue/24/outline'

interface Props {
    active: 'notifications' | 'job_titles' | 'credentials' | 'dashboard'
}
defineProps<Props>()

const tabs = [
    { id: 'notifications', label: 'Notifications',         href: '/executive/org-settings',           icon: BellAlertIcon },
    { id: 'job_titles',    label: 'Job Titles',            href: '/executive/job-titles-page',        icon: BriefcaseIcon },
    { id: 'credentials',   label: 'Credentials Catalog',   href: '/executive/credentials-catalog',    icon: AcademicCapIcon },
    { id: 'dashboard',     label: 'Compliance Dashboard',  href: '/executive/credentials-dashboard',  icon: PresentationChartLineIcon },
] as const
</script>

<template>
    <div class="flex items-center gap-1 mb-6 border-b border-gray-200 dark:border-slate-700 overflow-x-auto">
        <Link
            v-for="tab in tabs"
            :key="tab.id"
            :href="tab.href"
            :class="[
                'px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors inline-flex items-center gap-2',
                active === tab.id
                    ? 'border-indigo-500 text-indigo-700 dark:text-indigo-300'
                    : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200',
            ]"
        >
            <component :is="tab.icon" class="w-4 h-4" />
            {{ tab.label }}
        </Link>
    </div>
</template>
