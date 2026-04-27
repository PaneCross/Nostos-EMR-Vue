<script setup lang="ts">
// ─── Finance/RevenueIntegrity.vue ────────────────────────────────────────────
// Revenue Integrity Dashboard: CMS encounter data quality, HCC risk capture,
// and capitation reconciliation. Matches React layout.
// Route: GET /billing/revenue-integrity → Inertia
// ─────────────────────────────────────────────────────────────────────────────
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import { CheckCircleIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'

interface FlatKpis {
    capitation_total: number
    submission_rate_30d: number
    rejection_rate: number
    troop_alerts: number
    hos_m_completion_rate: number
    encounter_completeness: number
}

interface DenialKpis {
    open_count: number
    appealing_count: number
    overdue_count: number
    revenue_at_risk: number
    won_this_month: number
}

const props = defineProps<{
    kpis: FlatKpis
    denial_kpis: DenialKpis
    gaps: any[]
    pending: any[]
}>()

// Local reactive copies for refresh
const kpis = ref<FlatKpis>(props.kpis ?? {} as FlatKpis)
const denialKpis = ref<DenialKpis>(props.denial_kpis ?? {} as DenialKpis)
const gaps = ref<any[]>(props.gaps ?? [])
const pending = ref<any[]>(props.pending ?? [])
const refreshing = ref(false)
const lastUpdated = ref(new Date().toLocaleTimeString())

function refresh() {
    refreshing.value = true
    axios.get('/billing/revenue-integrity/data').then(res => {
        kpis.value = res.data.kpis ?? kpis.value
        denialKpis.value = res.data.denial_kpis ?? denialKpis.value
        gaps.value = res.data.gaps ?? gaps.value
        pending.value = res.data.pending ?? pending.value
        lastUpdated.value = new Date().toLocaleTimeString()
    }).finally(() => refreshing.value = false)
}

function fmtCurrency(n: number | null | undefined): string {
    if (n == null) return '$0'
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2 }).format(n)
}

function fmtPct(n: number | null | undefined): string {
    if (n == null) return '0.0%'
    return n.toFixed(1) + '%'
}

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val)
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

// KPI status thresholds
function submissionStatus(rate: number): string {
    if (rate >= 90) return 'ok'
    if (rate >= 70) return 'warning'
    return 'critical'
}

function rejectionStatus(rate: number): string {
    if (rate <= 2) return 'ok'
    if (rate <= 5) return 'warning'
    return 'critical'
}

function completenessStatus(rate: number): string {
    if (rate >= 95) return 'ok'
    if (rate >= 80) return 'warning'
    return 'critical'
}

const STATUS_CARD: Record<string, string> = {
    ok:       'bg-white dark:bg-slate-800 border-gray-200 dark:border-slate-700',
    warning:  'bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-700',
    critical: 'bg-red-50 dark:bg-red-950/40 border-red-300 dark:border-red-700',
}
const STATUS_LABEL: Record<string, string> = {
    ok:       'text-gray-500 dark:text-slate-400',
    warning:  'text-amber-600 dark:text-amber-400',
    critical: 'text-red-600 dark:text-red-400',
}
const STATUS_VALUE: Record<string, string> = {
    ok:       'text-gray-900 dark:text-slate-100',
    warning:  'text-amber-700 dark:text-amber-300',
    critical: 'text-red-700 dark:text-red-300',
}
</script>

<template>
    <AppShell>
        <Head title="Revenue Integrity" />

        <div class="p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Revenue Integrity Dashboard</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">CMS encounter data quality, HCC risk capture, and capitation reconciliation</p>
                </div>
                <div class="flex items-center gap-3 text-sm text-gray-400 dark:text-slate-500">
                    <span>Updated {{ lastUpdated }}</span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors text-sm font-medium"
                        :disabled="refreshing"
                        @click="refresh"
                    >
                        <ArrowPathIcon class="w-4 h-4" :class="{ 'animate-spin': refreshing }" />
                        Refresh
                    </button>
                </div>
            </div>

            <!-- Revenue KPI Cards: 2 rows of 3 -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <!-- Capitation Total -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-1">
                    <p class="text-sm font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wider">Current Month Capitation</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ fmtCurrency(kpis.capitation_total) }}</p>
                    <p class="text-sm text-gray-400 dark:text-slate-500">Total across all enrolled participants</p>
                </div>

                <!-- Submission Rate -->
                <div :class="[STATUS_CARD[submissionStatus(kpis.submission_rate_30d ?? 0)], 'rounded-xl border p-5 space-y-1']">
                    <p :class="[STATUS_LABEL[submissionStatus(kpis.submission_rate_30d ?? 0)], 'text-sm font-medium uppercase tracking-wider']">Submission Rate (30-Day)</p>
                    <p :class="[STATUS_VALUE[submissionStatus(kpis.submission_rate_30d ?? 0)], 'text-2xl font-bold']">{{ fmtPct(kpis.submission_rate_30d) }}</p>
                    <p class="text-sm text-gray-400 dark:text-slate-500">Encounters submitted to CMS EDS</p>
                </div>

                <!-- Rejection Rate -->
                <div :class="[STATUS_CARD[rejectionStatus(kpis.rejection_rate ?? 0)], 'rounded-xl border p-5 space-y-1']">
                    <p :class="[STATUS_LABEL[rejectionStatus(kpis.rejection_rate ?? 0)], 'text-sm font-medium uppercase tracking-wider']">Rejection Rate</p>
                    <p :class="[STATUS_VALUE[rejectionStatus(kpis.rejection_rate ?? 0)], 'text-2xl font-bold']">{{ fmtPct(kpis.rejection_rate) }}</p>
                    <p class="text-sm text-gray-400 dark:text-slate-500">CMS rejections of submitted encounters</p>
                </div>

                <!-- TrOOP Threshold -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-1">
                    <p class="text-sm font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wider">TrOOP Threshold Alerts</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ kpis.troop_alerts ?? 0 }}</p>
                    <p class="text-sm text-gray-400 dark:text-slate-500">Participants at/near $7,400 catastrophic limit</p>
                </div>

                <!-- HOS-M Completion -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-1">
                    <p class="text-sm font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wider">HOS-M Completion</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ fmtPct(kpis.hos_m_completion_rate) }}</p>
                    <p class="text-sm text-gray-400 dark:text-slate-500">Annual survey completion rate</p>
                </div>

                <!-- Encounter Completeness -->
                <div :class="[STATUS_CARD[completenessStatus(kpis.encounter_completeness ?? 0)], 'rounded-xl border p-5 space-y-1']">
                    <p :class="[STATUS_LABEL[completenessStatus(kpis.encounter_completeness ?? 0)], 'text-sm font-medium uppercase tracking-wider']">Encounter Completeness</p>
                    <p :class="[STATUS_VALUE[completenessStatus(kpis.encounter_completeness ?? 0)], 'text-2xl font-bold']">{{ fmtPct(kpis.encounter_completeness) }}</p>
                    <p class="text-sm text-gray-400 dark:text-slate-500">Encounters with ICD-10 diagnosis codes</p>
                </div>
            </div>

            <!-- Denial Management Section -->
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100 uppercase tracking-wider mb-3">Denial Management</h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-amber-50 dark:bg-amber-950/40 rounded-xl border border-amber-300 dark:border-amber-700 p-5 space-y-1">
                        <p class="text-sm font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider">Open Denials</p>
                        <p class="text-2xl font-bold text-amber-700 dark:text-amber-300">{{ (denialKpis.open_count ?? 0) + (denialKpis.appealing_count ?? 0) }}</p>
                        <p class="text-sm text-amber-600/70 dark:text-amber-400/70">{{ denialKpis.open_count ?? 0 }} open, {{ denialKpis.appealing_count ?? 0 }} appealing</p>
                    </div>
                    <div class="bg-amber-50 dark:bg-amber-950/40 rounded-xl border border-amber-300 dark:border-amber-700 p-5 space-y-1">
                        <p class="text-sm font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider">Revenue at Risk</p>
                        <p class="text-2xl font-bold text-amber-700 dark:text-amber-300">{{ fmtCurrency(denialKpis.revenue_at_risk) }}</p>
                        <p class="text-sm text-amber-600/70 dark:text-amber-400/70">Total denied amount in open and appealing claims</p>
                    </div>
                    <div :class="[(denialKpis.overdue_count ?? 0) > 0 ? 'bg-red-50 dark:bg-red-950/40 border-red-300 dark:border-red-700' : 'bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-700', 'rounded-xl border p-5 space-y-1']">
                        <p :class="[(denialKpis.overdue_count ?? 0) > 0 ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400', 'text-sm font-medium uppercase tracking-wider']">Overdue Appeals</p>
                        <p :class="[(denialKpis.overdue_count ?? 0) > 0 ? 'text-red-700 dark:text-red-300' : 'text-amber-700 dark:text-amber-300', 'text-2xl font-bold']">{{ denialKpis.overdue_count ?? 0 }}</p>
                        <p class="text-sm text-gray-400 dark:text-slate-500">Denials past 120-day CMS appeal deadline (42 CFR 405.942)</p>
                    </div>
                </div>
            </div>

            <!-- HCC Coding Opportunities -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">HCC Coding Opportunities</h2>
                    <p class="text-sm text-gray-400 dark:text-slate-500 mt-0.5">Active clinical problems not submitted as encounter diagnoses - each represents potential capitation uplift</p>
                </div>
                <table v-if="gaps.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider">Participant</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider">ICD-10</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider">HCC Category</th>
                            <th class="px-6 py-3 text-right text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider">Est. Monthly Impact</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr v-for="(gap, idx) in gaps" :key="idx" class="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                            <td class="px-6 py-3 text-sm text-gray-900 dark:text-slate-100">
                                {{ gap.participant_name ?? '-' }}
                                <span v-if="gap.mrn" class="text-gray-400 dark:text-slate-500 ml-1">({{ gap.mrn }})</span>
                            </td>
                            <td class="px-6 py-3 text-sm font-mono text-gray-600 dark:text-slate-300">{{ gap.icd10_code ?? gap.hcc_code ?? '-' }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600 dark:text-slate-300">{{ gap.description ?? gap.hcc_category ?? '-' }}</td>
                            <td class="px-6 py-3 text-sm text-right text-gray-600 dark:text-slate-300">
                                {{ gap.estimated_monthly_impact != null ? fmtCurrency(gap.estimated_monthly_impact) : '-' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="px-6 py-10 text-center text-gray-500 dark:text-slate-400">
                    <p class="text-sm">No HCC coding gaps identified. All active diagnoses are captured in recent encounters.</p>
                </div>
            </div>

            <!-- Encounters Missing Required Fields -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Encounters Missing Required Fields</h2>
                    <p class="text-sm text-gray-400 dark:text-slate-500 mt-0.5">These encounters cannot be included in an 837P batch until missing fields are resolved</p>
                </div>
                <table v-if="pending.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider">Participant</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider">Service Date</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider">Service Type</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider">Missing Fields</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr v-for="(item, idx) in pending" :key="idx" class="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                            <td class="px-6 py-3 text-sm text-gray-900 dark:text-slate-100">{{ item.participant_name ?? '-' }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600 dark:text-slate-300">{{ fmtDate(item.service_date) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600 dark:text-slate-300 capitalize">{{ (item.service_type ?? '-').replace(/_/g, ' ') }}</td>
                            <td class="px-6 py-3 text-sm">
                                <span
                                    v-for="field in (item.missing_fields ?? [])"
                                    :key="field"
                                    class="inline-flex items-center px-2 py-0.5 mr-1 mb-0.5 rounded text-sm font-medium bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300"
                                >
                                    {{ field.replace(/_/g, ' ') }}
                                </span>
                                <span v-if="!item.missing_fields?.length" class="text-gray-400 dark:text-slate-500 text-sm">-</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="px-6 py-10 text-center text-gray-500 dark:text-slate-400">
                    <CheckCircleIcon class="w-8 h-8 mb-2 text-green-400 mx-auto" />
                    <p class="text-sm">No pending encounters with missing fields.</p>
                </div>
            </div>
        </div>
    </AppShell>
</template>
