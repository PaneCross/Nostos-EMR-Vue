<script setup lang="ts">
// ─── Compliance/AdeReporting ────────────────────────────────────────────────
// Audit-pull universe for ADEs (Adverse Drug Events): patient harm
// associated with medication use. Surfaces serious / fatal events that may
// require FDA MedWatch reporting and CMS audit response.
//
// Audience: QA Compliance, Pharmacy leadership.
//
// Notable rules:
//   - 42 CFR §460.200 (QAPI): adverse events must be tracked + analyzed.
//   - FDA MedWatch reporting threshold for serious/unexpected events.
//   - Append-only: historical events cannot be edited (audit trail).
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Row {
    id: number
    participant: { id: number | null; mrn: string | null; name: string | null }
    medication: string | null
    onset_date: string | null
    severity: 'mild' | 'moderate' | 'severe' | 'life_threatening' | 'fatal'
    causality: 'definite' | 'probable' | 'possible' | 'unlikely'
    reaction_description: string
    reporter: string | null
    reported_to_medwatch_at: string | null
    medwatch_tracking_number: string | null
    auto_allergy_created: boolean
    requires_medwatch: boolean
    medwatch_overdue: boolean
    outcome_text: string | null
    href: string
}

interface Summary {
    count_total: number
    count_severe_plus: number
    count_requires_medwatch: number
    count_medwatch_reported: number
    count_medwatch_overdue: number
    count_auto_allergy: number
    window_start: string
    window_end: string
}

const props = defineProps<{ rows: Row[]; summary: Summary }>()

const filter = ref<'all'|'severe'|'medwatch_overdue'|'medwatch_reported'|'auto_allergy'>('all')
const filtered = computed(() => {
    switch (filter.value) {
        case 'severe':             return props.rows.filter(r => ['severe','life_threatening','fatal'].includes(r.severity))
        case 'medwatch_overdue':   return props.rows.filter(r => r.medwatch_overdue)
        case 'medwatch_reported':  return props.rows.filter(r => r.reported_to_medwatch_at)
        case 'auto_allergy':       return props.rows.filter(r => r.auto_allergy_created)
        default: return props.rows
    }
})

function fmt(d: string | null): string {
    if (!d) return '-'
    return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

const SEVERITY_CLASS: Record<string, string> = {
    mild:             'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300',
    moderate:         'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    severe:           'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
    life_threatening: 'bg-red-200 dark:bg-red-800/50 text-red-800 dark:text-red-200',
    fatal:            'bg-red-300 dark:bg-red-800/70 text-red-900 dark:text-red-100',
}
</script>

<template>
    <AppShell title="ADE Reporting">
        <Head title="Adverse Drug Events: Compliance" />
        <div class="max-w-7xl mx-auto p-6 space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Adverse Drug Events: 12-Month Universe</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    FDA MedWatch + CMS PACE Audit. Severe+ events auto-create allergies. MedWatch deadline 15 days from onset.
                    Window: {{ fmt(summary.window_start) }} → {{ fmt(summary.window_end) }}.
                </p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <button @click="filter = 'all'"
                    :class="['text-left p-3 rounded-xl border', filter === 'all' ? 'border-blue-500 ring-1 ring-blue-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-slate-500">Total</div>
                    <div class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ summary.count_total }}</div>
                </button>
                <button @click="filter = 'severe'"
                    :class="['text-left p-3 rounded-xl border', filter === 'severe' ? 'border-red-500 ring-1 ring-red-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">Severe+</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_severe_plus }}</div>
                </button>
                <div class="p-3 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Requires MedWatch</div>
                    <div class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ summary.count_requires_medwatch }}</div>
                </div>
                <button @click="filter = 'medwatch_overdue'"
                    :class="['text-left p-3 rounded-xl border', filter === 'medwatch_overdue' ? 'border-red-600 ring-1 ring-red-600' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">MedWatch overdue</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_medwatch_overdue }}</div>
                </button>
                <button @click="filter = 'medwatch_reported'"
                    :class="['text-left p-3 rounded-xl border', filter === 'medwatch_reported' ? 'border-emerald-500 ring-1 ring-emerald-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-emerald-600 dark:text-emerald-400">MedWatch reported</div>
                    <div class="text-2xl font-semibold text-emerald-700 dark:text-emerald-300">{{ summary.count_medwatch_reported }}</div>
                </button>
                <button @click="filter = 'auto_allergy'"
                    :class="['text-left p-3 rounded-xl border', filter === 'auto_allergy' ? 'border-purple-500 ring-1 ring-purple-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-purple-600 dark:text-purple-400">Auto-allergy</div>
                    <div class="text-2xl font-semibold text-purple-700 dark:text-purple-300">{{ summary.count_auto_allergy }}</div>
                </button>
            </div>

            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-left">Participant</th>
                            <th class="px-3 py-2 text-left">Medication</th>
                            <th class="px-3 py-2 text-left">Onset</th>
                            <th class="px-3 py-2 text-left">Severity</th>
                            <th class="px-3 py-2 text-left">Causality</th>
                            <th class="px-3 py-2 text-left">Reaction</th>
                            <th class="px-3 py-2 text-left">MedWatch</th>
                            <th class="px-3 py-2 text-left">Flags</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="filtered.length === 0">
                            <td colspan="8" class="px-3 py-6 text-center text-slate-400">No ADEs match.</td>
                        </tr>
                        <tr v-for="r in filtered" :key="r.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2">
                                <Link v-if="r.participant.id" :href="r.href" class="text-blue-600 dark:text-blue-400 hover:underline">
                                    {{ r.participant.name }}
                                </Link>
                                <span v-else>-</span>
                                <div class="text-xs text-slate-500">{{ r.participant.mrn }}</div>
                            </td>
                            <td class="px-3 py-2 text-slate-700 dark:text-slate-200">{{ r.medication ?? '-' }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ fmt(r.onset_date) }}</td>
                            <td class="px-3 py-2">
                                <span :class="['inline-flex px-2 py-0.5 rounded text-xs', SEVERITY_CLASS[r.severity]]">{{ r.severity }}</span>
                            </td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ r.causality }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ r.reaction_description }}</td>
                            <td class="px-3 py-2">
                                <template v-if="r.reported_to_medwatch_at">
                                    <span class="text-emerald-600 dark:text-emerald-400">{{ fmt(r.reported_to_medwatch_at) }}</span>
                                    <div class="text-xs text-slate-500">{{ r.medwatch_tracking_number }}</div>
                                </template>
                                <span v-else-if="r.medwatch_overdue" class="inline-flex px-2 py-0.5 rounded text-xs bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">OVERDUE</span>
                                <span v-else-if="r.requires_medwatch" class="text-xs text-amber-600 dark:text-amber-400">pending</span>
                                <span v-else class="text-slate-400">-</span>
                            </td>
                            <td class="px-3 py-2 space-y-1">
                                <span v-if="r.auto_allergy_created" class="inline-block text-xs px-2 py-0.5 rounded bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300">auto-allergy</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
