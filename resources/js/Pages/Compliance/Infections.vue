<script setup lang="ts">
// ─── Compliance/Infections.vue ───────────────────────────────────────────────
// Phase B2. Surveyor-ready 12-month infection surveillance pull.
// 42 CFR §460 + CMS PACE Audit Protocol + CDC LTC surveillance.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Case {
    id: number
    participant: { id: number | null; mrn: string | null; name: string | null }
    site_id: number | null
    site_name: string | null
    organism_type: string
    organism_detail: string | null
    onset_date: string | null
    resolution_date: string | null
    severity: 'mild' | 'moderate' | 'severe' | 'fatal'
    source: string
    hospitalization_required: boolean
    isolation_started_at: string | null
    isolation_ended_at: string | null
    outbreak_id: number | null
    outbreak_status: string | null
    reported_by: string | null
    href: string
}

interface Outbreak {
    id: number
    site_id: number | null
    site_name: string | null
    organism_type: string
    organism_detail: string | null
    status: 'active' | 'contained' | 'ended'
    started_at: string | null
    declared_ended_at: string | null
    attack_rate_pct: number | null
    containment_measures_text: string | null
    reported_to_state_at: string | null
    declared_by: string | null
    cases_count: number
}

interface Summary {
    count_cases: number
    count_cases_hospitalized: number
    count_cases_severe: number
    count_cases_unresolved: number
    count_outbreaks: number
    count_outbreaks_active: number
    count_outbreaks_unreported: number
    window_start: string
    window_end: string
}

const props = defineProps<{ cases: Case[]; outbreaks: Outbreak[]; summary: Summary }>()

const filter = ref<'all'|'unresolved'|'hospitalized'|'severe'|'outbreak_linked'>('all')
const filtered = computed(() => {
    switch (filter.value) {
        case 'unresolved':      return props.cases.filter(c => !c.resolution_date)
        case 'hospitalized':    return props.cases.filter(c => c.hospitalization_required)
        case 'severe':          return props.cases.filter(c => c.severity === 'severe' || c.severity === 'fatal')
        case 'outbreak_linked': return props.cases.filter(c => c.outbreak_id)
        default: return props.cases
    }
})

function fmt(ts: string | null): string {
    if (!ts) return '—'
    return new Date(ts).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

const SEVERITY_CLASS: Record<string, string> = {
    mild:     'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300',
    moderate: 'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    severe:   'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
    fatal:    'bg-red-200 dark:bg-red-800/50 text-red-800 dark:text-red-200',
}
const OUTBREAK_STATUS_CLASS: Record<string, string> = {
    active:    'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
    contained: 'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    ended:     'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
}
</script>

<template>
    <AppShell title="Infection Surveillance">
        <Head title="Infections — Compliance" />
        <div class="max-w-7xl mx-auto p-6 space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Infection Surveillance — 12-Month Universe</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    42 CFR §460 + CDC LTC surveillance. Window:
                    {{ fmt(summary.window_start) }} → {{ fmt(summary.window_end) }}.
                    Outbreak threshold: ≥3 cases of same organism at same site within 7 days.
                </p>
            </div>

            <!-- KPI -->
            <div class="grid grid-cols-2 md:grid-cols-7 gap-3">
                <button @click="filter = 'all'"
                    :class="['text-left p-3 rounded-xl border', filter === 'all' ? 'border-blue-500 ring-1 ring-blue-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-slate-500">Total cases</div>
                    <div class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ summary.count_cases }}</div>
                </button>
                <button @click="filter = 'unresolved'"
                    :class="['text-left p-3 rounded-xl border', filter === 'unresolved' ? 'border-amber-500 ring-1 ring-amber-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-amber-600 dark:text-amber-400">Unresolved</div>
                    <div class="text-2xl font-semibold text-amber-700 dark:text-amber-300">{{ summary.count_cases_unresolved }}</div>
                </button>
                <button @click="filter = 'hospitalized'"
                    :class="['text-left p-3 rounded-xl border', filter === 'hospitalized' ? 'border-purple-500 ring-1 ring-purple-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-purple-600 dark:text-purple-400">Hospitalized</div>
                    <div class="text-2xl font-semibold text-purple-700 dark:text-purple-300">{{ summary.count_cases_hospitalized }}</div>
                </button>
                <button @click="filter = 'severe'"
                    :class="['text-left p-3 rounded-xl border', filter === 'severe' ? 'border-red-500 ring-1 ring-red-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">Severe/Fatal</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_cases_severe }}</div>
                </button>
                <button @click="filter = 'outbreak_linked'"
                    :class="['text-left p-3 rounded-xl border', filter === 'outbreak_linked' ? 'border-red-600 ring-1 ring-red-600' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">In outbreak</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ props.cases.filter(c => c.outbreak_id).length }}</div>
                </button>
                <div class="p-3 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Outbreaks (total)</div>
                    <div class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ summary.count_outbreaks }}</div>
                </div>
                <div class="p-3 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <div class="text-xs text-red-600 dark:text-red-400">Active / Unreported</div>
                    <div class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                        {{ summary.count_outbreaks_active }} / {{ summary.count_outbreaks_unreported }}
                    </div>
                </div>
            </div>

            <!-- Outbreaks -->
            <div v-if="outbreaks.length > 0">
                <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-2">Declared Outbreaks</h2>
                <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase text-slate-500 dark:text-slate-400">
                            <tr>
                                <th class="px-3 py-2 text-left">Organism</th>
                                <th class="px-3 py-2 text-left">Site</th>
                                <th class="px-3 py-2 text-left">Started</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-center">Cases</th>
                                <th class="px-3 py-2 text-left">State reported</th>
                                <th class="px-3 py-2 text-left">Declared by</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in outbreaks" :key="o.id" class="border-t border-gray-100 dark:border-slate-700">
                                <td class="px-3 py-2 font-medium text-slate-900 dark:text-slate-100">
                                    {{ o.organism_type }}
                                    <div v-if="o.organism_detail" class="text-xs text-slate-500">{{ o.organism_detail }}</div>
                                </td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ o.site_name ?? '—' }}</td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ fmt(o.started_at) }}</td>
                                <td class="px-3 py-2">
                                    <span :class="['inline-flex px-2 py-0.5 rounded text-xs', OUTBREAK_STATUS_CLASS[o.status]]">{{ o.status }}</span>
                                </td>
                                <td class="px-3 py-2 text-center text-slate-600 dark:text-slate-300">{{ o.cases_count }}</td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300">
                                    <span v-if="o.reported_to_state_at">{{ fmt(o.reported_to_state_at) }}</span>
                                    <span v-else class="text-xs text-red-500">not reported</span>
                                </td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ o.declared_by ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Cases Table -->
            <div>
                <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-2">Infection Cases</h2>
                <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase text-slate-500 dark:text-slate-400">
                            <tr>
                                <th class="px-3 py-2 text-left">Participant</th>
                                <th class="px-3 py-2 text-left">Organism</th>
                                <th class="px-3 py-2 text-left">Onset</th>
                                <th class="px-3 py-2 text-left">Severity</th>
                                <th class="px-3 py-2 text-left">Source</th>
                                <th class="px-3 py-2 text-center">Hosp</th>
                                <th class="px-3 py-2 text-left">Resolved</th>
                                <th class="px-3 py-2 text-left">Outbreak</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="filtered.length === 0">
                                <td colspan="8" class="px-3 py-6 text-center text-slate-400">No cases match.</td>
                            </tr>
                            <tr v-for="c in filtered" :key="c.id" class="border-t border-gray-100 dark:border-slate-700">
                                <td class="px-3 py-2">
                                    <Link v-if="c.participant.id" :href="c.href" class="text-blue-600 dark:text-blue-400 hover:underline">
                                        {{ c.participant.name }}
                                    </Link>
                                    <span v-else>—</span>
                                    <div class="text-xs text-slate-500">{{ c.participant.mrn }}</div>
                                </td>
                                <td class="px-3 py-2 text-slate-700 dark:text-slate-200">
                                    {{ c.organism_type }}
                                    <div v-if="c.organism_detail" class="text-xs text-slate-500">{{ c.organism_detail }}</div>
                                </td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ fmt(c.onset_date) }}</td>
                                <td class="px-3 py-2">
                                    <span :class="['inline-flex px-2 py-0.5 rounded text-xs', SEVERITY_CLASS[c.severity]]">{{ c.severity }}</span>
                                </td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ c.source }}</td>
                                <td class="px-3 py-2 text-center">
                                    <span v-if="c.hospitalization_required" class="text-red-600 dark:text-red-400">●</span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300">
                                    <span v-if="c.resolution_date">{{ fmt(c.resolution_date) }}</span>
                                    <span v-else class="text-xs text-amber-600 dark:text-amber-400">unresolved</span>
                                </td>
                                <td class="px-3 py-2">
                                    <span v-if="c.outbreak_id" class="inline-flex px-2 py-0.5 rounded text-xs bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">
                                        #{{ c.outbreak_id }}
                                    </span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppShell>
</template>
