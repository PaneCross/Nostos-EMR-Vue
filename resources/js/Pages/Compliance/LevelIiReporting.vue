<script setup lang="ts">
// ─── Compliance/LevelIiReporting ────────────────────────────────────────────
// CMS PACE quarterly quality-indicator submissions: Level I (mortality,
// falls w/ injury, pressure injuries stage 2+, vaccinations, etc.) and
// Level II (more granular root-cause fields).
//
// Audience: QA Compliance.
//
// Notable rules:
//   - Per-indicator aggregators feed CSV output; "Mark CMS Submitted" only
//     records the upload timestamp — no automated HPMS (Health Plan
//     Management System) transmission is wired pre-go-live.
//   - Quarterly cadence; missed quarters surface as red banners.
// ────────────────────────────────────────────────────────────────────────────

import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    ChartBarIcon,
    DocumentArrowDownIcon,
    CheckBadgeIcon,
    PlusIcon,
    InformationCircleIcon,
} from '@heroicons/vue/24/outline'

interface IndicatorSnapshot {
    period_start?: string
    period_end?: string
    avg_daily_enrolled_census?: number
    deaths?: number
    hospital_admissions?: number
    er_visits?: number
    falls_total?: number
    falls_with_injury?: number
    pressure_injuries_new?: number
    pressure_injuries_stage_2p?: number
    pressure_injuries_critical?: number
    flu_vaccinations_given?: number
    flu_vaccination_rate_pct?: number | null
    pneumo_vaccinations_given?: number
    pneumo_vaccination_rate_pct?: number | null
    burns?: number
    infectious_disease?: number
    medication_errors?: number
    elopements?: number
    abuse_neglect?: number
    unexpected_deaths?: number
}

interface Submission {
    id: number
    year: number
    quarter: number
    label: string
    generated_at: string | null
    generated_by: string | null
    indicators_snapshot: IndicatorSnapshot | null
    marked_cms_submitted_at: string | null
    marked_cms_submitted_by: string | null
    marked_cms_submitted_notes: string | null
    csv_available: boolean
}

const props = defineProps<{
    submissions: Submission[]
    current_year: number
    current_quarter: number
}>()

const yearInput = ref(props.current_year)
const quarterInput = ref(props.current_quarter)
const generating = ref(false)
const markingId = ref<number | null>(null)
const markNotes = ref('')
const expandedId = ref<number | null>(null)

async function generate() {
    generating.value = true
    try {
        await axios.post('/compliance/level-ii-reporting', {
            year: yearInput.value,
            quarter: quarterInput.value,
        })
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to generate submission.')
    } finally {
        generating.value = false
    }
}

function openMark(s: Submission) {
    markingId.value = s.id
    markNotes.value = ''
}

async function submitMark() {
    if (markingId.value === null) return
    try {
        await axios.post(`/compliance/level-ii-reporting/${markingId.value}/mark-submitted`, {
            notes: markNotes.value,
        })
        markingId.value = null
        markNotes.value = ''
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to stamp submission.')
    }
}

function fmt(d: string | null): string {
    return d ? new Date(d).toLocaleString() : '—'
}

function toggle(id: number) {
    expandedId.value = expandedId.value === id ? null : id
}

const INDICATOR_ROWS: Array<{ key: keyof IndicatorSnapshot; label: string }> = [
    { key: 'deaths',                      label: 'Deaths (all causes)' },
    { key: 'hospital_admissions',         label: 'Hospital Admissions' },
    { key: 'er_visits',                   label: 'ER Visits' },
    { key: 'falls_total',                 label: 'Falls (total)' },
    { key: 'falls_with_injury',           label: 'Falls with Injury' },
    { key: 'pressure_injuries_new',       label: 'Pressure Injuries (new)' },
    { key: 'pressure_injuries_stage_2p',  label: 'Pressure Injuries Stage 2+' },
    { key: 'pressure_injuries_critical',  label: 'Pressure Injuries Stage 3+' },
    { key: 'flu_vaccinations_given',      label: 'Flu Vaccinations Given' },
    { key: 'flu_vaccination_rate_pct',    label: 'Flu Vaccination Rate (%)' },
    { key: 'pneumo_vaccinations_given',   label: 'Pneumococcal Given' },
    { key: 'pneumo_vaccination_rate_pct', label: 'Pneumo Vaccination Rate (%)' },
    { key: 'burns',                       label: 'Burns' },
    { key: 'infectious_disease',          label: 'Infectious Disease Events' },
    { key: 'medication_errors',           label: 'Medication Errors' },
    { key: 'elopements',                  label: 'Elopements' },
    { key: 'abuse_neglect',               label: 'Abuse / Neglect' },
    { key: 'unexpected_deaths',           label: 'Unexpected Deaths' },
]
</script>

<template>
    <AppShell>
        <Head title="Level I/II Reporting" />

        <div class="px-6 py-6 max-w-6xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex items-start gap-3">
                <ChartBarIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                <div>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">CMS Level I / Level II Reporting</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Quarterly PACE quality indicators — CMS Reporting &amp; Monitoring Requirements
                    </p>
                </div>
            </div>

            <!-- Honest labeling banner -->
            <div class="flex items-start gap-3 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-5 py-4">
                <InformationCircleIcon class="w-5 h-5 shrink-0 mt-0.5 text-amber-700 dark:text-amber-300" />
                <div class="text-sm text-amber-800 dark:text-amber-200">
                    <p class="font-semibold mb-0.5">Manual Submission Flag</p>
                    <p>
                        This tool generates the CSV artifact and records a submission timestamp when you click
                        <strong>Mark CMS Submitted</strong>. It does NOT currently transmit to CMS HPMS automatically.
                        Staff must upload the downloaded CSV to the HPMS portal and record the submission here.
                    </p>
                </div>
            </div>

            <!-- Generate card -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">
                    Generate Quarterly Submission
                </h2>
                <div class="flex items-end gap-3 flex-wrap">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Year</label>
                        <input v-model.number="yearInput" type="number" min="2000" max="2100"
                            class="w-24 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Quarter</label>
                        <select v-model.number="quarterInput" class="w-24 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2">
                            <option :value="1">Q1</option>
                            <option :value="2">Q2</option>
                            <option :value="3">Q3</option>
                            <option :value="4">Q4</option>
                        </select>
                    </div>
                    <button :disabled="generating" @click="generate"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
                        <PlusIcon class="w-4 h-4" />
                        {{ generating ? 'Generating...' : 'Generate / Regenerate' }}
                    </button>
                    <p class="text-xs text-slate-500 dark:text-slate-400 flex-1">
                        Regeneration refreshes indicators + CSV — submission timestamp is preserved.
                    </p>
                </div>
            </div>

            <!-- List -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <div v-if="submissions.length === 0" class="py-16 text-center text-sm text-slate-400">
                    No quarterly submissions generated yet.
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/40 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left">Period</th>
                            <th class="px-4 py-3 text-left">Generated</th>
                            <th class="px-4 py-3 text-left">Highlights</th>
                            <th class="px-4 py-3 text-left">CMS Submission</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <template v-for="s in submissions" :key="s.id">
                            <tr :class="[expandedId === s.id ? 'bg-slate-50 dark:bg-slate-900/30' : '']">
                                <td class="px-4 py-3 font-semibold text-slate-800 dark:text-slate-200">{{ s.label }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                                    {{ fmt(s.generated_at) }}
                                    <span v-if="s.generated_by" class="block text-slate-400">by {{ s.generated_by }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                                    <span class="tabular-nums">
                                        deaths {{ s.indicators_snapshot?.deaths ?? 0 }} ·
                                        falls {{ s.indicators_snapshot?.falls_total ?? 0 }}
                                        ({{ s.indicators_snapshot?.falls_with_injury ?? 0 }} w/injury)
                                    </span>
                                    <span class="block text-slate-400">
                                        hosp {{ s.indicators_snapshot?.hospital_admissions ?? 0 }} ·
                                        ER {{ s.indicators_snapshot?.er_visits ?? 0 }} ·
                                        pressure {{ s.indicators_snapshot?.pressure_injuries_new ?? 0 }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <template v-if="s.marked_cms_submitted_at">
                                        <span class="inline-flex items-center gap-1 text-green-700 dark:text-green-300 font-semibold">
                                            <CheckBadgeIcon class="w-4 h-4" /> Marked Submitted
                                        </span>
                                        <span class="block text-slate-500">
                                            {{ fmt(s.marked_cms_submitted_at) }}
                                            <template v-if="s.marked_cms_submitted_by">by {{ s.marked_cms_submitted_by }}</template>
                                        </span>
                                        <p v-if="s.marked_cms_submitted_notes" class="text-slate-500 mt-1 whitespace-pre-wrap">{{ s.marked_cms_submitted_notes }}</p>
                                    </template>
                                    <template v-else>
                                        <button @click="openMark(s)"
                                            class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 text-xs font-medium hover:bg-indigo-50 dark:hover:bg-indigo-900/30">
                                            Mark CMS Submitted
                                        </button>
                                    </template>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <button @click="toggle(s.id)" class="text-xs text-slate-500 dark:text-slate-400 hover:underline">
                                            {{ expandedId === s.id ? 'hide' : 'details' }}
                                        </button>
                                        <a v-if="s.csv_available"
                                           :href="`/compliance/level-ii-reporting/${s.id}/download`"
                                           class="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                            <DocumentArrowDownIcon class="w-4 h-4" /> CSV
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="expandedId === s.id" class="bg-slate-50 dark:bg-slate-900/30">
                                <td colspan="5" class="px-4 pb-4">
                                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 text-xs">
                                        <div v-for="r in INDICATOR_ROWS" :key="r.key"
                                            class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2">
                                            <p class="text-slate-500 dark:text-slate-400">{{ r.label }}</p>
                                            <p class="text-sm font-semibold text-slate-800 dark:text-slate-200 tabular-nums">
                                                {{ s.indicators_snapshot?.[r.key] ?? '—' }}
                                            </p>
                                        </div>
                                    </div>
                                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-3">
                                        Period: {{ s.indicators_snapshot?.period_start ?? '—' }} → {{ s.indicators_snapshot?.period_end ?? '—' }}
                                        · Avg daily enrolled census: {{ s.indicators_snapshot?.avg_daily_enrolled_census ?? '—' }}
                                    </p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Mark submitted modal -->
            <div v-if="markingId !== null"
                class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
                @click.self="markingId = null">
                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md">
                    <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                        <h3 class="font-semibold text-slate-900 dark:text-slate-100">Mark CMS Submitted</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            Records a submission timestamp to the audit log. Does NOT transmit to CMS HPMS automatically.
                            Upload the downloaded CSV to the HPMS portal first, then stamp the submission here.
                        </p>
                    </div>
                    <div class="px-6 py-5 space-y-3">
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Notes (optional — e.g. HPMS confirmation number, upload date)
                        </label>
                        <textarea v-model="markNotes" rows="4"
                            placeholder="HPMS confirmation #, uploaded by..."
                            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                    </div>
                    <div class="px-6 py-3 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-2">
                        <button @click="markingId = null" class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 text-sm">Cancel</button>
                        <button @click="submitMark"
                            class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">
                            Mark CMS Submitted
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
