<script setup lang="ts">
// ─── Appeals/Index ──────────────────────────────────────────────────────────
// Queue of formal appeals filed by PACE participants against service denial.
// Distinct from Grievances (general complaints): appeals contest a specific
// denial decision and have hard CMS-mandated decision deadlines.
//
// Audience: QA Compliance, Enrollment, Super Admin roles.
//
// Notable rules:
//   - 42 CFR §460.122: standard appeals decided within 30 days; expedited
//     appeals within 72 hours. Row coloring (green/yellow/red) tracks how
//     much of that decision window has been consumed.
// ────────────────────────────────────────────────────────────────────────────

import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { ScaleIcon, ClockIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface Appeal {
    id: number
    type: 'standard' | 'expedited'
    status: string
    filed_at: string
    internal_decision_due_at: string
    internal_decision_at: string | null
    continuation_of_benefits: boolean
    participant: { id: number; mrn: string; first_name: string; last_name: string }
    denial_notice: { id: number; sdr_id: number | null; reason_code: string; issued_at: string }
}

interface Paginator<T> {
    data: T[]
    total: number
    current_page: number
    last_page: number
}

const props = defineProps<{
    appeals: Paginator<Appeal>
    filters: { status?: string }
}>()

const filterStatus = ref(props.filters.status ?? '')

const STATUS_LABELS: Record<string, string> = {
    received: 'Received',
    acknowledged: 'Acknowledged',
    under_review: 'Under Review',
    decided_upheld: 'Decided: Upheld',
    decided_overturned: 'Decided: Overturned',
    decided_partially_overturned: 'Decided: Partially Overturned',
    withdrawn: 'Withdrawn',
    external_review_requested: 'External Review Requested',
    closed: 'Closed',
}

const STATUS_CLASSES: Record<string, string> = {
    received: 'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-200',
    acknowledged: 'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-800 dark:text-indigo-200',
    under_review: 'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-200',
    decided_upheld: 'bg-orange-100 dark:bg-orange-900/60 text-orange-800 dark:text-orange-200',
    decided_overturned: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-200',
    decided_partially_overturned: 'bg-sky-100 dark:bg-sky-900/60 text-sky-800 dark:text-sky-200',
    withdrawn: 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300',
    external_review_requested: 'bg-purple-100 dark:bg-purple-900/60 text-purple-800 dark:text-purple-200',
    closed: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
}

const OPEN_STATUSES = new Set(['received', 'acknowledged', 'under_review', 'external_review_requested'])

function isOpen(a: Appeal): boolean {
    return OPEN_STATUSES.has(a.status)
}

function windowElapsedPercent(a: Appeal): number {
    const filed = new Date(a.filed_at).getTime()
    const due = new Date(a.internal_decision_due_at).getTime()
    const now = Date.now()
    if (due <= filed) return 100
    return Math.max(0, Math.min(100, Math.round(((now - filed) / (due - filed)) * 100)))
}

function ageColorClass(a: Appeal): string {
    if (!isOpen(a)) return 'bg-slate-200 dark:bg-slate-600'
    const pct = windowElapsedPercent(a)
    if (pct >= 100) return 'bg-red-500'
    if (pct >= 75) return 'bg-orange-500'
    if (pct >= 50) return 'bg-amber-400'
    return 'bg-emerald-500'
}

function dueLabel(a: Appeal): string {
    const due = new Date(a.internal_decision_due_at)
    const ms = due.getTime() - Date.now()
    if (!isOpen(a)) return due.toLocaleString()
    if (ms < 0) return `overdue by ${formatDuration(-ms)}`
    return `due in ${formatDuration(ms)}`
}

function formatDuration(ms: number): string {
    const hours = Math.floor(ms / 3_600_000)
    if (hours >= 48) return `${Math.floor(hours / 24)}d ${hours % 24}h`
    if (hours >= 1) return `${hours}h`
    const mins = Math.max(1, Math.floor(ms / 60_000))
    return `${mins}m`
}

function fmt(d: string) { return new Date(d).toLocaleDateString() }

function applyFilter(val: string) {
    filterStatus.value = val
    router.get('/appeals', { status: val || undefined }, { preserveState: true, replace: true })
}
</script>

<template>
    <AppShell>
        <Head title="Appeals" />

        <div class="px-6 py-6">
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-3">
                    <ScaleIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Appeals</h1>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Participant appeals of service denials: 42 CFR §460.122
                        </p>
                    </div>
                </div>
                <p class="text-sm text-slate-500 dark:text-slate-400 tabular-nums">
                    {{ appeals.total }} appeal{{ appeals.total !== 1 ? 's' : '' }}
                </p>
            </div>

            <!-- Filter pills -->
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <button
                    v-for="(label, key) in { '': 'All', open: 'Open', overdue: 'Overdue', received: 'Received', acknowledged: 'Acknowledged', under_review: 'Under Review', decided_upheld: 'Upheld', decided_overturned: 'Overturned', withdrawn: 'Withdrawn', closed: 'Closed' }"
                    :key="key"
                    type="button"
                    @click="applyFilter(String(key))"
                    :class="[
                        'px-3 py-1 rounded-full text-xs font-semibold border transition-colors',
                        filterStatus === key
                            ? 'bg-indigo-600 border-indigo-600 text-white shadow-sm'
                            : 'bg-white dark:bg-slate-700 border-gray-200 dark:border-slate-600 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-600'
                    ]"
                >
                    {{ label }}
                </button>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <div v-if="appeals.data.length === 0" class="py-16 text-center text-sm text-slate-400 dark:text-slate-500">
                    No appeals match the current filter.
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/40 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left">Appeal</th>
                            <th class="px-4 py-3 text-left">Participant</th>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Decision Window</th>
                            <th class="px-4 py-3 text-left">Filed</th>
                            <th class="px-4 py-3 text-left">Cont. of Benefits</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr
                            v-for="a in appeals.data"
                            :key="a.id"
                            class="hover:bg-slate-50 dark:hover:bg-slate-700/50 cursor-pointer transition-colors"
                            @click="router.visit(`/appeals/${a.id}`)"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-700 dark:text-slate-300">
                                APPEAL-{{ a.id }}
                            </td>
                            <td class="px-4 py-3 text-slate-800 dark:text-slate-200">
                                {{ a.participant.last_name }}, {{ a.participant.first_name }}
                                <span class="text-slate-400 text-xs ml-1">({{ a.participant.mrn }})</span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="[
                                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium',
                                    a.type === 'expedited'
                                        ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                                        : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300',
                                ]">
                                    <ClockIcon v-if="a.type === 'expedited'" class="w-3 h-3" />
                                    {{ a.type === 'expedited' ? 'Expedited (72h)' : 'Standard (30d)' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', STATUS_CLASSES[a.status] ?? 'bg-slate-100 text-slate-700']">
                                    {{ STATUS_LABELS[a.status] ?? a.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-24 h-2 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                                        <div :class="['h-full transition-all', ageColorClass(a)]" :style="{ width: windowElapsedPercent(a) + '%' }"></div>
                                    </div>
                                    <span :class="[
                                        'text-xs',
                                        isOpen(a) && windowElapsedPercent(a) >= 100
                                            ? 'text-red-600 dark:text-red-400 font-semibold'
                                            : 'text-slate-500 dark:text-slate-400',
                                    ]">
                                        {{ dueLabel(a) }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ fmt(a.filed_at) }}</td>
                            <td class="px-4 py-3">
                                <span v-if="a.continuation_of_benefits" class="inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-300">
                                    <ExclamationTriangleIcon class="w-3.5 h-3.5" /> Yes
                                </span>
                                <span v-else class="text-xs text-slate-400">-</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
