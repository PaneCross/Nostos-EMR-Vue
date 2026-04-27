<script setup lang="ts">
// ─── Compliance/NfLocStatus ─────────────────────────────────────────────────
// Audit universe for NF-LOC (Nursing Facility Level of Care): the state
// medical-eligibility determination that every enrolled PACE participant
// must hold and renew annually to remain enrolled.
//
// Audience: QA Compliance, Enrollment.
//
// Notable rules:
//   - 42 CFR §460.160(b)(2): annual NF-LOC recertification required.
//   - Lapse triggers an involuntary disenrollment workflow (do not let
//     this page rot: it is a CMS surveyor target).
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Row {
    id: number
    mrn: string
    name: string
    nursing_facility_eligible: boolean
    nf_certification_date: string | null
    nf_expires_at: string | null
    days_remaining: number | null
    status: 'current' | 'due_60' | 'due_30' | 'due_15' | 'due_today' | 'overdue' | 'missing' | 'waived'
    recert_waived: boolean
    recert_waived_reason: string | null
    href: string
}

interface Summary {
    count_total: number
    count_overdue: number
    count_due_60d: number
    count_waived: number
    count_current: number
    count_missing: number
}

const props = defineProps<{
    rows: Row[]
    summary: Summary
}>()

const statusFilter = ref<string>('all')

const filtered = computed(() => {
    if (statusFilter.value === 'all') return props.rows
    if (statusFilter.value === 'due_soon') {
        return props.rows.filter(r => ['due_60', 'due_30', 'due_15', 'due_today'].includes(r.status))
    }
    return props.rows.filter(r => r.status === statusFilter.value)
})

const STATUS_CLASS: Record<string, string> = {
    current:   'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300',
    due_60:    'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300',
    due_30:    'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    due_15:    'bg-amber-200 dark:bg-amber-800/50 text-amber-800 dark:text-amber-200',
    due_today: 'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
    overdue:   'bg-red-200 dark:bg-red-800/50 text-red-800 dark:text-red-200 font-semibold',
    missing:   'bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300',
    waived:    'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300',
}

function statusLabel(s: string): string {
    return s.replace(/_/g, ' ')
}
</script>

<template>
    <AppShell title="NF-LOC Status">
        <Head title="NF-LOC Status" />
        <div class="max-w-6xl mx-auto p-6 space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">NF-LOC Recertification Status</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    42 CFR §460.160(b)(2): annual nursing-facility level-of-care recertification. Every enrolled participant needs a current NF-LOC determination on file.
                </p>
            </div>

            <!-- KPI summary -->
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <button @click="statusFilter = 'all'"
                    :class="['text-left p-3 rounded-xl border', statusFilter === 'all' ? 'border-blue-500 ring-1 ring-blue-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-slate-500 dark:text-slate-400">Total enrolled</div>
                    <div class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ summary.count_total }}</div>
                </button>
                <button @click="statusFilter = 'current'"
                    :class="['text-left p-3 rounded-xl border', statusFilter === 'current' ? 'border-emerald-500 ring-1 ring-emerald-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-emerald-600 dark:text-emerald-400">Current</div>
                    <div class="text-2xl font-semibold text-emerald-700 dark:text-emerald-300">{{ summary.count_current }}</div>
                </button>
                <button @click="statusFilter = 'due_soon'"
                    :class="['text-left p-3 rounded-xl border', statusFilter === 'due_soon' ? 'border-amber-500 ring-1 ring-amber-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-amber-600 dark:text-amber-400">Due in 60d</div>
                    <div class="text-2xl font-semibold text-amber-700 dark:text-amber-300">{{ summary.count_due_60d }}</div>
                </button>
                <button @click="statusFilter = 'overdue'"
                    :class="['text-left p-3 rounded-xl border', statusFilter === 'overdue' ? 'border-red-500 ring-1 ring-red-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">Overdue</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_overdue }}</div>
                </button>
                <button @click="statusFilter = 'missing'"
                    :class="['text-left p-3 rounded-xl border', statusFilter === 'missing' ? 'border-purple-500 ring-1 ring-purple-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-purple-600 dark:text-purple-400">Missing cert</div>
                    <div class="text-2xl font-semibold text-purple-700 dark:text-purple-300">{{ summary.count_missing }}</div>
                </button>
                <button @click="statusFilter = 'waived'"
                    :class="['text-left p-3 rounded-xl border', statusFilter === 'waived' ? 'border-gray-500 ring-1 ring-gray-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Waived</div>
                    <div class="text-2xl font-semibold text-gray-700 dark:text-gray-300">{{ summary.count_waived }}</div>
                </button>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-left">Participant</th>
                            <th class="px-3 py-2 text-left">MRN</th>
                            <th class="px-3 py-2 text-left">Cert date</th>
                            <th class="px-3 py-2 text-left">Expires</th>
                            <th class="px-3 py-2 text-right">Days left</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="filtered.length === 0">
                            <td colspan="7" class="px-3 py-6 text-center text-slate-400">No participants match this filter.</td>
                        </tr>
                        <tr v-for="r in filtered" :key="r.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2">
                                <Link :href="r.href" class="text-blue-600 dark:text-blue-400 hover:underline">{{ r.name }}</Link>
                            </td>
                            <td class="px-3 py-2 text-slate-500">{{ r.mrn }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ r.nf_certification_date ?? '-' }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ r.nf_expires_at ?? '-' }}</td>
                            <td class="px-3 py-2 text-right text-slate-600 dark:text-slate-300">{{ r.days_remaining ?? '-' }}</td>
                            <td class="px-3 py-2">
                                <span :class="['inline-flex px-2 py-0.5 rounded text-xs', STATUS_CLASS[r.status] ?? '']">
                                    {{ statusLabel(r.status) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500">
                                <template v-if="r.recert_waived">Waived: {{ r.recert_waived_reason }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
