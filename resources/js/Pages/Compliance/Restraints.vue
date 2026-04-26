<script setup lang="ts">
// ─── Compliance/Restraints ──────────────────────────────────────────────────
// Audit-pull universe: every physical or chemical restraint episode in the
// last 12 months, exportable for a CMS surveyor on demand.
//
// Audience: QA Compliance, Primary Care leadership. Read-only here; restraint
// episodes are recorded inside the participant chart.
//
// Notable rules:
//   - 42 CFR §460 — physical/chemical restraints require monitoring
//     observations + IDT (Interdisciplinary Team) review.
//   - CMS PACE Audit Protocol 2.0 universe-pull format.
//   - Append-only — historical episodes cannot be edited (audit trail).
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Row {
    id: number
    participant: { id: number | null; mrn: string | null; name: string | null }
    restraint_type: 'physical' | 'chemical' | 'both'
    initiated_at: string
    initiated_by: string | null
    ordering_provider: string | null
    medication_text: string | null
    reason_text: string
    alternatives_tried_text: string | null
    status: 'active' | 'discontinued' | 'expired'
    discontinued_at: string | null
    discontinuation_reason: string | null
    idt_review_date: string | null
    idt_reviewer: string | null
    outcome_text: string | null
    observations_count: number
    idt_review_overdue: boolean
    monitoring_overdue: boolean
    duration_minutes: number
}

interface Summary {
    count_total: number
    count_active: number
    count_physical: number
    count_chemical: number
    count_idt_overdue: number
    count_monitoring_overdue: number
    window_start: string
    window_end: string
}

const props = defineProps<{ rows: Row[]; summary: Summary }>()

const filter = ref<'all'|'active'|'physical'|'chemical'|'idt_overdue'|'monitoring_overdue'>('all')
const filtered = computed(() => {
    switch (filter.value) {
        case 'active':            return props.rows.filter(r => r.status === 'active')
        case 'physical':          return props.rows.filter(r => r.restraint_type === 'physical')
        case 'chemical':          return props.rows.filter(r => r.restraint_type === 'chemical' || r.restraint_type === 'both')
        case 'idt_overdue':       return props.rows.filter(r => r.idt_review_overdue)
        case 'monitoring_overdue':return props.rows.filter(r => r.monitoring_overdue)
        default: return props.rows
    }
})

function fmt(ts: string | null): string {
    if (!ts) return '—'
    return new Date(ts).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}
function hours(m: number): string {
    if (m < 60) return `${m}m`
    const h = Math.floor(m / 60), mm = m % 60
    return mm ? `${h}h ${mm}m` : `${h}h`
}

const TYPE_CLASS: Record<string, string> = {
    physical: 'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    chemical: 'bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300',
    both:     'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
}
const STATUS_CLASS: Record<string, string> = {
    active:       'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300',
    discontinued: 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    expired:      'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-300',
}
</script>

<template>
    <AppShell title="Restraints Universe">
        <Head title="Restraints — Compliance" />
        <div class="max-w-7xl mx-auto p-6 space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Restraint Episodes — 12-Month Universe</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    42 CFR §460 + CMS PACE Audit Protocol. Window:
                    {{ fmt(summary.window_start) }} → {{ fmt(summary.window_end) }}.
                </p>
            </div>

            <!-- KPI + click-to-filter -->
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <button @click="filter = 'all'"
                    :class="['text-left p-3 rounded-xl border', filter === 'all' ? 'border-blue-500 ring-1 ring-blue-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-slate-500">Total</div>
                    <div class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ summary.count_total }}</div>
                </button>
                <button @click="filter = 'active'"
                    :class="['text-left p-3 rounded-xl border', filter === 'active' ? 'border-emerald-500 ring-1 ring-emerald-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-emerald-600 dark:text-emerald-400">Active</div>
                    <div class="text-2xl font-semibold text-emerald-700 dark:text-emerald-300">{{ summary.count_active }}</div>
                </button>
                <button @click="filter = 'physical'"
                    :class="['text-left p-3 rounded-xl border', filter === 'physical' ? 'border-amber-500 ring-1 ring-amber-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-amber-600 dark:text-amber-400">Physical</div>
                    <div class="text-2xl font-semibold text-amber-700 dark:text-amber-300">{{ summary.count_physical }}</div>
                </button>
                <button @click="filter = 'chemical'"
                    :class="['text-left p-3 rounded-xl border', filter === 'chemical' ? 'border-purple-500 ring-1 ring-purple-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-purple-600 dark:text-purple-400">Chemical</div>
                    <div class="text-2xl font-semibold text-purple-700 dark:text-purple-300">{{ summary.count_chemical }}</div>
                </button>
                <button @click="filter = 'monitoring_overdue'"
                    :class="['text-left p-3 rounded-xl border', filter === 'monitoring_overdue' ? 'border-red-500 ring-1 ring-red-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">Monitoring overdue</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_monitoring_overdue }}</div>
                </button>
                <button @click="filter = 'idt_overdue'"
                    :class="['text-left p-3 rounded-xl border', filter === 'idt_overdue' ? 'border-red-600 ring-1 ring-red-600' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">IDT overdue</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_idt_overdue }}</div>
                </button>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-left">Participant</th>
                            <th class="px-3 py-2 text-left">Type</th>
                            <th class="px-3 py-2 text-left">Started</th>
                            <th class="px-3 py-2 text-left">Duration</th>
                            <th class="px-3 py-2 text-left">Initiated by</th>
                            <th class="px-3 py-2 text-left">Ordering provider</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-center">Obs</th>
                            <th class="px-3 py-2 text-left">IDT review</th>
                            <th class="px-3 py-2 text-left">Flags</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="filtered.length === 0">
                            <td colspan="10" class="px-3 py-6 text-center text-slate-400">No episodes match.</td>
                        </tr>
                        <tr v-for="r in filtered" :key="r.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2">
                                <Link v-if="r.participant.id" :href="`/participants/${r.participant.id}`" class="text-blue-600 dark:text-blue-400 hover:underline">
                                    {{ r.participant.name }}
                                </Link>
                                <span v-else>—</span>
                                <div class="text-xs text-slate-500">{{ r.participant.mrn }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <span :class="['inline-flex px-2 py-0.5 rounded text-xs', TYPE_CLASS[r.restraint_type]]">{{ r.restraint_type }}</span>
                            </td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ fmt(r.initiated_at) }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ hours(r.duration_minutes) }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ r.initiated_by ?? '—' }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ r.ordering_provider ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <span :class="['inline-flex px-2 py-0.5 rounded text-xs', STATUS_CLASS[r.status]]">{{ r.status }}</span>
                            </td>
                            <td class="px-3 py-2 text-center text-slate-600 dark:text-slate-300">{{ r.observations_count }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">
                                <template v-if="r.idt_review_date">
                                    {{ r.idt_review_date }}<br>
                                    <span class="text-xs text-slate-500">{{ r.idt_reviewer }}</span>
                                </template>
                                <span v-else class="text-xs text-red-500">pending</span>
                            </td>
                            <td class="px-3 py-2 space-y-1">
                                <span v-if="r.monitoring_overdue" class="inline-block text-xs px-2 py-0.5 rounded bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">monitoring</span>
                                <span v-if="r.idt_review_overdue" class="inline-block text-xs px-2 py-0.5 rounded bg-red-200 dark:bg-red-800/50 text-red-800 dark:text-red-200">IDT</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
