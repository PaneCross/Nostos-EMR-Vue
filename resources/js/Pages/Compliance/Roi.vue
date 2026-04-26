<script setup lang="ts">
// ─── Compliance/Roi ─────────────────────────────────────────────────────────
// Audit-pull universe for ROI (Release of Information) requests — i.e. a
// participant or their representative invoking their HIPAA right to access
// their own medical record. (NOT return-on-investment.)
//
// Audience: QA Compliance, Health Information Management.
//
// Notable rules:
//   - HIPAA §164.524 — covered entity must respond to an access request
//     within 30 days; one 30-day extension allowed with written notice.
//   - Append-only — closed/fulfilled rows are historical and immutable.
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Row {
    id: number
    participant: { id: number | null; mrn: string | null; name: string | null }
    requestor_type: string
    requestor_name: string
    requestor_contact: string | null
    records_requested_scope: string
    requested_at: string | null
    due_by: string | null
    status: 'pending' | 'in_progress' | 'fulfilled' | 'denied' | 'withdrawn'
    fulfilled_at: string | null
    fulfilled_by: string | null
    denial_reason: string | null
    is_overdue: boolean
    days_until_due: number | null
}

interface Summary {
    count_total: number
    count_open: number
    count_overdue: number
    count_fulfilled: number
    count_denied: number
    window_start: string
    window_end: string
}

const props = defineProps<{ rows: Row[]; summary: Summary }>()
const filter = ref<'all'|'open'|'overdue'|'fulfilled'|'denied'>('all')
const filtered = computed(() => {
    switch (filter.value) {
        case 'open':      return props.rows.filter(r => ['pending','in_progress'].includes(r.status))
        case 'overdue':   return props.rows.filter(r => r.is_overdue)
        case 'fulfilled': return props.rows.filter(r => r.status === 'fulfilled')
        case 'denied':    return props.rows.filter(r => r.status === 'denied')
        default: return props.rows
    }
})

function fmt(ts: string | null): string {
    if (!ts) return '—'
    return new Date(ts).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

const STATUS_CLASS: Record<string, string> = {
    pending:     'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    in_progress: 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300',
    fulfilled:   'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300',
    denied:      'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
    withdrawn:   'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
}
</script>

<template>
    <AppShell title="ROI Requests">
        <Head title="ROI Requests — Compliance" />
        <div class="max-w-7xl mx-auto p-6 space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Release of Information Requests — 12-Month Universe</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    HIPAA §164.524. 30-day response deadline. Window: {{ fmt(summary.window_start) }} → {{ fmt(summary.window_end) }}.
                </p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <button @click="filter = 'all'"
                    :class="['text-left p-3 rounded-xl border', filter === 'all' ? 'border-blue-500 ring-1 ring-blue-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-slate-500">Total</div>
                    <div class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ summary.count_total }}</div>
                </button>
                <button @click="filter = 'open'"
                    :class="['text-left p-3 rounded-xl border', filter === 'open' ? 'border-amber-500 ring-1 ring-amber-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-amber-600 dark:text-amber-400">Open</div>
                    <div class="text-2xl font-semibold text-amber-700 dark:text-amber-300">{{ summary.count_open }}</div>
                </button>
                <button @click="filter = 'overdue'"
                    :class="['text-left p-3 rounded-xl border', filter === 'overdue' ? 'border-red-600 ring-1 ring-red-600' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">Overdue</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_overdue }}</div>
                </button>
                <button @click="filter = 'fulfilled'"
                    :class="['text-left p-3 rounded-xl border', filter === 'fulfilled' ? 'border-emerald-500 ring-1 ring-emerald-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-emerald-600 dark:text-emerald-400">Fulfilled</div>
                    <div class="text-2xl font-semibold text-emerald-700 dark:text-emerald-300">{{ summary.count_fulfilled }}</div>
                </button>
                <button @click="filter = 'denied'"
                    :class="['text-left p-3 rounded-xl border', filter === 'denied' ? 'border-red-500 ring-1 ring-red-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">Denied</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_denied }}</div>
                </button>
            </div>

            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-left">Participant</th>
                            <th class="px-3 py-2 text-left">Requestor</th>
                            <th class="px-3 py-2 text-left">Scope</th>
                            <th class="px-3 py-2 text-left">Requested</th>
                            <th class="px-3 py-2 text-left">Due</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Fulfilled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="filtered.length === 0">
                            <td colspan="7" class="px-3 py-6 text-center text-slate-400">No requests match.</td>
                        </tr>
                        <tr v-for="r in filtered" :key="r.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2">
                                <Link v-if="r.participant.id" :href="`/participants/${r.participant.id}`" class="text-blue-600 dark:text-blue-400 hover:underline">
                                    {{ r.participant.name }}
                                </Link>
                                <span v-else>—</span>
                                <div class="text-xs text-slate-500">{{ r.participant.mrn }}</div>
                            </td>
                            <td class="px-3 py-2 text-slate-700 dark:text-slate-200">
                                {{ r.requestor_name }}
                                <div class="text-xs text-slate-500">{{ r.requestor_type }}</div>
                            </td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300 max-w-md truncate">{{ r.records_requested_scope }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ fmt(r.requested_at) }}</td>
                            <td class="px-3 py-2">
                                {{ fmt(r.due_by) }}
                                <span v-if="r.is_overdue" class="ml-1 inline-flex px-1.5 py-0.5 rounded text-xs bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">OVERDUE</span>
                                <span v-else-if="r.days_until_due !== null && r.days_until_due <= 5 && r.days_until_due >= 0" class="ml-1 text-xs text-amber-600 dark:text-amber-400">
                                    {{ r.days_until_due }}d left
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <span :class="['inline-flex px-2 py-0.5 rounded text-xs', STATUS_CLASS[r.status]]">{{ r.status }}</span>
                            </td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">
                                <template v-if="r.fulfilled_at">
                                    {{ fmt(r.fulfilled_at) }}
                                    <div class="text-xs text-slate-500">{{ r.fulfilled_by }}</div>
                                </template>
                                <span v-else-if="r.status === 'denied'" class="text-xs text-red-500 italic">{{ r.denial_reason }}</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
