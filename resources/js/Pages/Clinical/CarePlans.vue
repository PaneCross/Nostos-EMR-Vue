<script setup lang="ts">
// ─── Clinical/CarePlans.vue ───────────────────────────────────────────────────
// Care plan status overview for all participants. Shows plan status badges,
// version numbers, goal counts, effective dates, and review due dates.
// Review-due dates past today are highlighted red per 42 CFR §460.104 IDT cadence.
// Client-side search by name or MRN; rows with no care plan sort to the bottom.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import {
    MagnifyingGlassIcon,
    ClipboardDocumentListIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface CarePlanSummary {
    id: number
    status: 'active' | 'draft' | 'under_review' | 'archived'
    version: number
    goal_count: number
    effective_date: string | null
    review_due_date: string | null
}

interface ParticipantRow {
    id: number
    mrn: string
    first_name: string
    last_name: string
    care_plan: CarePlanSummary | null
}

const props = defineProps<{ participants: ParticipantRow[] }>()

// ── Search ─────────────────────────────────────────────────────────────────────

const search = ref('')

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    const rows = q
        ? props.participants.filter(
              (p) =>
                  p.mrn.toLowerCase().includes(q) ||
                  p.first_name.toLowerCase().includes(q) ||
                  p.last_name.toLowerCase().includes(q),
          )
        : [...props.participants]

    // Sort: participants with care plans first (by last_name asc), no-plan at bottom
    return rows.sort((a, b) => {
        if (a.care_plan && !b.care_plan) return -1
        if (!a.care_plan && b.care_plan) return 1
        return a.last_name.localeCompare(b.last_name)
    })
})

// ── Summary counts ─────────────────────────────────────────────────────────────

const counts = computed(() => {
    let active = 0
    let draft = 0
    let under_review = 0
    let no_plan = 0
    for (const p of props.participants) {
        if (!p.care_plan) {
            no_plan++
            continue
        }
        if (p.care_plan.status === 'active') active++
        else if (p.care_plan.status === 'draft') draft++
        else if (p.care_plan.status === 'under_review') under_review++
    }
    return { active, draft, under_review, no_plan }
})

// ── Helpers ────────────────────────────────────────────────────────────────────

const today = new Date()
today.setHours(0, 0, 0, 0)

function parseDate(val: string | null | undefined): Date | null {
    if (!val) return null
    return new Date(val.slice(0, 10) + 'T12:00:00')
}

function fmtDate(val: string | null | undefined): string {
    const d = parseDate(val)
    return d
        ? d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
        : '-'
}

function isOverdue(val: string | null | undefined): boolean {
    const d = parseDate(val)
    return d !== null && d < today
}

// ── Badge helpers ──────────────────────────────────────────────────────────────

const STATUS_BADGE: Record<string, string> = {
    active: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
    draft: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
    under_review: 'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300',
    archived: 'bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500',
}

const STATUS_LABEL: Record<string, string> = {
    active: 'Active',
    draft: 'Draft',
    under_review: 'Under Review',
    archived: 'Archived',
}
</script>

<template>
    <Head title="Care Plans" />

    <AppShell>
        <template #header>
            <div class="flex items-center gap-2">
                <ClipboardDocumentListIcon
                    class="w-5 h-5 text-gray-500 dark:text-slate-400"
                    aria-hidden="true"
                />
                <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                    Care Plans
                </h1>
            </div>
        </template>

        <div class="px-6 py-5">
            <!-- ── Summary counts ── -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <!-- Active -->
                <div
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 shadow-sm"
                >
                    <p
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-1"
                    >
                        Active
                    </p>
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400">
                        {{ counts.active }}
                    </p>
                </div>
                <!-- Draft -->
                <div
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 shadow-sm"
                >
                    <p
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-1"
                    >
                        Draft
                    </p>
                    <p class="text-2xl font-bold text-gray-500 dark:text-slate-400">
                        {{ counts.draft }}
                    </p>
                </div>
                <!-- Under Review -->
                <div
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 shadow-sm"
                >
                    <p
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-1"
                    >
                        Under Review
                    </p>
                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">
                        {{ counts.under_review }}
                    </p>
                </div>
                <!-- No Plan -->
                <div
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 shadow-sm"
                >
                    <p
                        class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-1"
                    >
                        No Plan
                    </p>
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400">
                        {{ counts.no_plan }}
                    </p>
                </div>
            </div>

            <!-- ── Search bar ── -->
            <div class="relative mb-4 max-w-sm">
                <MagnifyingGlassIcon
                    class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-slate-500"
                    aria-hidden="true"
                />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by name or MRN"
                    aria-label="Search participants"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400"
                />
            </div>

            <!-- ── Table ── -->
            <div
                class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm"
            >
                <table class="w-full text-sm" aria-label="Care plans list">
                    <thead
                        class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700"
                    >
                        <tr>
                            <th
                                v-for="col in [
                                    'Participant',
                                    'Status',
                                    'Version',
                                    'Goals',
                                    'Effective Date',
                                    'Review Due',
                                ]"
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
                        <tr v-if="filtered.length === 0">
                            <td
                                colspan="6"
                                class="px-4 py-10 text-center text-gray-400 dark:text-slate-500"
                            >
                                No participants match your search.
                            </td>
                        </tr>

                        <!-- Rows -->
                        <tr
                            v-for="ppt in filtered"
                            :key="ppt.id"
                            class="hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors bg-white dark:bg-slate-800"
                            tabindex="0"
                            :aria-label="`Open profile for ${ppt.last_name}, ${ppt.first_name}`"
                            @click="router.visit(`/participants/${ppt.id}`)"
                            @keydown.enter="router.visit(`/participants/${ppt.id}`)"
                        >
                            <!-- Participant -->
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-slate-100">
                                    {{ ppt.last_name }}, {{ ppt.first_name }}
                                </div>
                                <div class="text-xs font-mono text-gray-400 dark:text-slate-500">
                                    {{ ppt.mrn }}
                                </div>
                            </td>

                            <!-- Status badge -->
                            <td class="px-4 py-3">
                                <span
                                    v-if="ppt.care_plan"
                                    :class="[
                                        'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                        STATUS_BADGE[ppt.care_plan.status] ??
                                            'bg-gray-100 text-gray-600',
                                    ]"
                                >
                                    {{ STATUS_LABEL[ppt.care_plan.status] ?? ppt.care_plan.status }}
                                </span>
                                <span
                                    v-else
                                    class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300"
                                >
                                    No Plan
                                </span>
                            </td>

                            <!-- Version -->
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                {{ ppt.care_plan ? `v${ppt.care_plan.version}` : '-' }}
                            </td>

                            <!-- Goals -->
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                {{ ppt.care_plan ? ppt.care_plan.goal_count : '-' }}
                            </td>

                            <!-- Effective Date -->
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                {{ ppt.care_plan ? fmtDate(ppt.care_plan.effective_date) : '-' }}
                            </td>

                            <!-- Review Due -->
                            <td class="px-4 py-3">
                                <template v-if="ppt.care_plan && ppt.care_plan.review_due_date">
                                    <span
                                        :class="[
                                            'inline-flex items-center gap-1 text-sm',
                                            isOverdue(ppt.care_plan.review_due_date)
                                                ? 'text-red-600 dark:text-red-400 font-semibold'
                                                : 'text-gray-600 dark:text-slate-400',
                                        ]"
                                    >
                                        <ExclamationTriangleIcon
                                            v-if="isOverdue(ppt.care_plan.review_due_date)"
                                            class="w-3.5 h-3.5"
                                            aria-hidden="true"
                                        />
                                        {{ fmtDate(ppt.care_plan.review_due_date) }}
                                    </span>
                                </template>
                                <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Row count ── -->
            <p class="mt-3 text-xs text-gray-400 dark:text-slate-500">
                Showing {{ filtered.length }} of {{ participants.length }} participants
            </p>
        </div>
    </AppShell>
</template>
