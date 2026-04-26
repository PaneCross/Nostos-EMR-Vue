<script setup lang="ts">
// ─── Clinical/Assessments ───────────────────────────────────────────────────
// Worklist of standardized clinical assessments due across the participant
// roster (PHQ-9 depression, Mini-Cog, Morse fall risk, Katz ADL, Lawton IADL,
// AUDIT-C, etc.). Three tabs: Overdue, Due Soon (14 days), Recently Completed.
//
// Audience: Clinical staff (Primary Care, Behavioral Health, Social Work).
//
// Notable rules:
//   - 42 CFR §460.104 — comprehensive assessment at enrollment + every 6
//     months minimum (or after significant change).
//   - Overdue rows are clickable into the participant chart for completion.
// ────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Assessment {
    id: number
    assessment_type: string
    status: string
    next_due_date: string | null
    completed_at: string | null
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null
}

const props = defineProps<{
    overdue: Assessment[]
    dueSoon: Assessment[]
    recent: Assessment[]
}>()

// ── Tab state ─────────────────────────────────────────────────────────────────

type Tab = 'overdue' | 'due_soon' | 'recent'
const activeTab = ref<Tab>('overdue')

interface TabDef {
    key: Tab
    label: string
    count: number
    badgeClass: string
}

const tabs = computed<TabDef[]>(() => [
    {
        key: 'overdue',
        label: 'Overdue',
        count: props.overdue.length,
        badgeClass: 'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300',
    },
    {
        key: 'due_soon',
        label: 'Due Soon (14 days)',
        count: props.dueSoon.length,
        badgeClass: 'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300',
    },
    {
        key: 'recent',
        label: 'Recently Completed',
        count: props.recent.length,
        badgeClass: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
    },
])

const activeRows = computed<Assessment[]>(() => {
    if (activeTab.value === 'overdue') return props.overdue
    if (activeTab.value === 'due_soon') return props.dueSoon
    return props.recent
})

// ── Display helpers ────────────────────────────────────────────────────────────

function fmtType(t: string | null | undefined): string {
    if (!t) return '-'
    return t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function fmtStatus(s: string | null | undefined): string {
    if (!s) return '-'
    return s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function fmtDate(val: string | null): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

// Per-tab column heading for date column
const dateColLabel = computed(() => {
    if (activeTab.value === 'recent') return 'Completed'
    return 'Due Date'
})

function dateValue(row: Assessment): string {
    if (activeTab.value === 'recent') return fmtDate(row.completed_at)
    return fmtDate(row.next_due_date)
}

// Row left-border accent by tab
function rowClass(tab: Tab): string {
    if (tab === 'overdue') return 'border-l-4 border-red-400 bg-red-50/30 dark:bg-red-900/10'
    if (tab === 'due_soon') return 'border-l-4 border-amber-400 bg-amber-50/30 dark:bg-amber-900/10'
    return 'bg-white dark:bg-slate-800'
}

// Tab button classes
function tabClass(key: Tab): string {
    const base = 'flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-t-lg border-b-2 transition-colors whitespace-nowrap'
    if (key === activeTab.value) {
        return `${base} border-blue-600 text-blue-700 dark:text-blue-400`
    }
    return `${base} border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 hover:border-gray-300 dark:hover:border-slate-500`
}
</script>

<template>
    <Head title="Assessment Worklist" />

    <AppShell>
        <template #header>
            <div class="flex items-center gap-2">
                <ClipboardDocumentCheckIcon class="w-5 h-5 text-gray-500 dark:text-slate-400" aria-hidden="true" />
                <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                    Assessment Worklist
                </h1>
            </div>
        </template>

        <div class="px-6 py-5">
            <!-- ── Page header badges ── -->
            <div class="flex flex-wrap items-center gap-2 mb-5">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300">
                    {{ overdue.length }} overdue
                </span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300">
                    {{ dueSoon.length }} due soon
                </span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300">
                    {{ recent.length }} recently completed
                </span>
            </div>

            <!-- ── Tabs ── -->
            <div class="border-b border-gray-200 dark:border-slate-700 mb-4">
                <nav class="flex gap-1 -mb-px" role="tablist" aria-label="Assessment categories">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        :class="tabClass(tab.key)"
                        role="tab"
                        :aria-selected="activeTab === tab.key"
                        @click="activeTab = tab.key"
                    >
                        {{ tab.label }}
                        <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold', tab.badgeClass]">
                            {{ tab.count }}
                        </span>
                    </button>
                </nav>
            </div>

            <!-- ── Table ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-slate-700" aria-label="Assessments">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th
                                v-for="col in ['Participant', 'Assessment Type', 'Status', dateColLabel]"
                                :key="col"
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <!-- Empty state -->
                        <tr v-if="activeRows.length === 0">
                            <td colspan="4" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                                No assessments in this category.
                            </td>
                        </tr>

                        <!-- Assessment rows -->
                        <tr
                            v-for="row in activeRows"
                            :key="row.id"
                            :class="[rowClass(activeTab), 'hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors']"
                            tabindex="0"
                            :aria-label="row.participant ? `Open chart for ${row.participant.last_name}, ${row.participant.first_name}` : 'Open participant chart'"
                            @click="row.participant && router.visit(`/participants/${row.participant.id}?tab=assessments`)"
                            @keydown.enter="row.participant && router.visit(`/participants/${row.participant.id}?tab=assessments`)"
                        >
                            <!-- Participant -->
                            <td class="px-4 py-3">
                                <template v-if="row.participant">
                                    <div class="font-medium text-blue-600 dark:text-blue-400 hover:underline text-sm">
                                        {{ row.participant.last_name }}, {{ row.participant.first_name }}
                                    </div>
                                    <div class="font-mono text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                                        {{ row.participant.mrn }}
                                    </div>
                                </template>
                                <span v-else class="text-gray-400 dark:text-slate-500 text-sm">-</span>
                            </td>

                            <!-- Assessment type -->
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-slate-300">
                                {{ fmtType(row.assessment_type) }}
                            </td>

                            <!-- Status -->
                            <td class="px-4 py-3">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300">
                                    {{ fmtStatus(row.status) }}
                                </span>
                            </td>

                            <!-- Due / completed date -->
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-400">
                                {{ dateValue(row) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
