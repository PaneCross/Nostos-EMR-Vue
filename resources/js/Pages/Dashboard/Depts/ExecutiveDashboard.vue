<script setup lang="ts">
// ─── ExecutiveDashboard.vue ───────────────────────────────────────────────────
// Executive role live dashboard.
// Endpoints:
//   GET /dashboards/executive/org-overview      → org KPIs
//   GET /dashboards/executive/site-comparison   → per-site metrics
//   GET /dashboards/executive/financial-overview → capitation by site
//   GET /dashboards/executive/sites-list        → all sites
//   GET /dashboards/executive/dept-compliance   → per-dept compliance + org totals
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'
import {
    ExclamationTriangleIcon,
    CheckCircleIcon,
    XCircleIcon,
} from '@heroicons/vue/24/solid'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const kpis = ref<Record<string, number>>({})
const siteComparison = ref<any[]>([])
const financialOverview = ref<any>(null)
const allSites = ref<any[]>([])
const deptCompliance = ref<any>(null)

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/executive/org-overview'),
        axios.get('/dashboards/executive/site-comparison'),
        axios.get('/dashboards/executive/financial-overview'),
        axios.get('/dashboards/executive/sites-list'),
        axios.get('/dashboards/executive/dept-compliance'),
    ]).then(([r1, r2, r3, r4, r5]) => {
        kpis.value = r1.data ?? {}
        siteComparison.value = r2.data.sites ?? []
        financialOverview.value = r3.data ?? null
        allSites.value = r4.data.sites ?? []
        deptCompliance.value = r5.data ?? null
    }).finally(() => loading.value = false)
})

const orgTotals = computed(() => deptCompliance.value?.org_totals ?? {})
const departments = computed(() => deptCompliance.value?.departments ?? [])

function formatCurrency(n: number | null | undefined): string {
    if (n == null) return '-'
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(n)
}

// ── Existing widget computeds ─────────────────────────────────────────────────

const orgOverviewItems = computed<ActionItem[]>(() => {
    const enrolled = kpis.value.enrolled ?? 0
    const pending = kpis.value.pending_enrollment ?? 0
    const referrals = kpis.value.new_referrals_30d ?? 0
    const sites = kpis.value.active_sites ?? 0
    return [
        { label: 'Participants Enrolled', badge: String(enrolled), badgeColor: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300' },
        { label: 'Pending Enrollment', badge: String(pending), badgeColor: pending > 0 ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' : 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' },
        { label: 'New Referrals (30d)', badge: String(referrals), badgeColor: 'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300' },
        { label: 'Active Sites', badge: String(sites), badgeColor: 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300' },
    ]
})

const siteComparisonItems = computed<ActionItem[]>(() =>
    siteComparison.value.map(s => ({
        label: s.site_name ?? s.name ?? '-',
        sublabel: `${s.active_care_plans ?? 0} active care plans`,
        badge: `${s.enrolled ?? 0} enrolled`,
        badgeColor: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        href: s.href ?? '/participants',
    }))
)

const financialItems = computed<ActionItem[]>(() => {
    if (!financialOverview.value?.by_site) return []
    return financialOverview.value.by_site.map((s: any) => ({
        label: s.site_name ?? '-',
        sublabel: `${s.participant_count ?? 0} participants`,
        badge: formatCurrency(s.total_capitation),
        badgeColor: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
        href: s.href ?? '/finance/capitation',
    }))
})

const allSiteItems = computed<ActionItem[]>(() =>
    allSites.value.map(s => ({
        label: s.name ?? '-',
        sublabel: [s.city, s.state].filter(Boolean).join(', ') || '-',
        badge: `${s.enrolled ?? 0} enrolled`,
        badgeColor: s.is_active ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        href: s.href ?? '/participants',
    }))
)

// ── KPI row items ─────────────────────────────────────────────────────────────

interface KpiCard {
    label: string
    value: number
    color: string       // text color when value > 0
    bgColor: string     // bg when value > 0
    neutralBg: string   // bg when value === 0
}

const kpiCards = computed<KpiCard[]>(() => {
    const t = orgTotals.value
    return [
        { label: 'Overdue SDRs', value: t.overdue_sdrs ?? 0, color: 'text-red-700 dark:text-red-300', bgColor: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800', neutralBg: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800' },
        { label: 'Unsigned Notes', value: t.unsigned_notes ?? 0, color: 'text-amber-700 dark:text-amber-300', bgColor: 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800', neutralBg: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800' },
        { label: 'Open Incidents', value: t.open_incidents ?? 0, color: 'text-red-700 dark:text-red-300', bgColor: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800', neutralBg: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800' },
        { label: 'Overdue Care Plans', value: t.overdue_care_plans ?? 0, color: 'text-amber-700 dark:text-amber-300', bgColor: 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800', neutralBg: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800' },
        { label: 'IDT Reviews Overdue', value: t.overdue_idt_reviews ?? 0, color: 'text-amber-700 dark:text-amber-300', bgColor: 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800', neutralBg: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800' },
        { label: 'Critical Wounds', value: t.critical_wounds ?? 0, color: 'text-red-700 dark:text-red-300', bgColor: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800', neutralBg: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800' },
        { label: 'Hospitalizations', value: t.hospitalizations_this_month ?? 0, color: 'text-blue-700 dark:text-blue-300', bgColor: 'bg-blue-50 dark:bg-blue-950/60 border-blue-200 dark:border-blue-800', neutralBg: 'bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700' },
        { label: 'Drug Interactions', value: t.unacked_interactions ?? 0, color: 'text-red-700 dark:text-red-300', bgColor: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800', neutralBg: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800' },
    ]
})

// ── Dept table helpers ────────────────────────────────────────────────────────

const SCORE_DOT: Record<string, string> = {
    good: 'bg-green-500',
    warning: 'bg-amber-500',
    critical: 'bg-red-500',
}

function switchToDept(dept: string) {
    axios.post('/super-admin/view-as', { department: dept }).then(() => {
        location.href = '/'
    })
}
</script>

<template>
    <div class="space-y-6">

        <!-- ── Org Compliance KPI Row ──────────────────────────────────────── -->
        <div>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100 mb-3">Organization Compliance Overview</h2>
            <div v-if="loading" class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
                <div v-for="i in 8" :key="i" class="h-20 bg-slate-100 dark:bg-slate-800 rounded-lg animate-pulse" />
            </div>
            <div v-else class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
                <div
                    v-for="card in kpiCards"
                    :key="card.label"
                    :class="['text-center p-3 rounded-lg border', card.value > 0 ? card.bgColor : card.neutralBg]"
                >
                    <p :class="['text-2xl font-bold', card.value > 0 ? card.color : 'text-green-700 dark:text-green-300']">
                        {{ card.value }}
                    </p>
                    <p class="text-sm text-slate-600 dark:text-slate-400 font-medium mt-0.5">{{ card.label }}</p>
                </div>
            </div>
        </div>

        <!-- ── Existing Widgets ────────────────────────────────────────────── -->
        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">
            <ActionWidget
                title="Organization Overview"
                description="High-level KPIs across the entire organization."
                :items="orgOverviewItems"
                emptyMessage="No organization data available."
                viewAllHref="/participants"
                :loading="loading"
            />

            <ActionWidget
                title="Site Comparison"
                description="Enrolled participants and care plan counts per site."
                :items="siteComparisonItems"
                emptyMessage="No site data available."
                viewAllHref="/participants"
                :loading="loading"
            />

            <ActionWidget
                title="Capitation by Site"
                :description="financialOverview ? `${financialOverview.month_year} | Total: ${formatCurrency(financialOverview.grand_total)}` : 'Current month capitation by site.'"
                :items="financialItems"
                emptyMessage="No capitation records found."
                viewAllHref="/finance/capitation"
                :loading="loading"
            />

            <ActionWidget
                title="All Sites"
                description="Status of all registered PACE sites."
                :items="allSiteItems"
                emptyMessage="No sites found."
                viewAllHref="/participants"
                :loading="loading"
            />
        </div>

        <!-- ── Department Operations Table ─────────────────────────────────── -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Department Operations</h2>
                <p class="text-sm text-gray-400 dark:text-slate-500 mt-0.5">Click a department to switch to its dashboard view.</p>
            </div>

            <div v-if="loading" class="p-5 space-y-3 animate-pulse">
                <div v-for="i in 6" :key="i" class="h-8 bg-slate-100 dark:bg-slate-700 rounded" />
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-slate-700/50 text-left">
                            <th class="px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Department</th>
                            <th class="px-4 py-3 font-semibold text-gray-700 dark:text-slate-300 text-center">Status</th>
                            <th class="px-4 py-3 font-semibold text-gray-700 dark:text-slate-300 text-center">Overdue SDRs</th>
                            <th class="px-4 py-3 font-semibold text-gray-700 dark:text-slate-300 text-center">Unsigned Notes</th>
                            <th class="px-4 py-3 font-semibold text-gray-700 dark:text-slate-300 text-center">Overdue Assessments</th>
                            <th class="px-4 py-3 font-semibold text-gray-700 dark:text-slate-300 text-center">Pending Orders</th>
                            <th class="px-4 py-3 font-semibold text-gray-700 dark:text-slate-300 text-center">STAT</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr
                            v-for="dept in departments"
                            :key="dept.department"
                            class="hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer transition-colors"
                            @click="switchToDept(dept.department)"
                        >
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{{ dept.label }}</td>
                            <td class="px-4 py-3 text-center">
                                <span :class="['inline-block w-3 h-3 rounded-full', SCORE_DOT[dept.score] ?? 'bg-gray-400']" :title="dept.score" />
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="dept.overdue_sdrs > 0 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-400 dark:text-slate-500'">
                                    {{ dept.overdue_sdrs }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="dept.unsigned_notes > 3 ? 'text-amber-600 dark:text-amber-400 font-semibold' : dept.unsigned_notes > 0 ? 'text-gray-700 dark:text-slate-300' : 'text-gray-400 dark:text-slate-500'">
                                    {{ dept.unsigned_notes }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="dept.overdue_assessments > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-gray-400 dark:text-slate-500'">
                                    {{ dept.overdue_assessments }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="dept.pending_orders > 0 ? 'text-gray-700 dark:text-slate-300' : 'text-gray-400 dark:text-slate-500'">
                                    {{ dept.pending_orders }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="dept.stat_orders > 0 ? 'text-red-600 dark:text-red-400 font-bold' : 'text-gray-400 dark:text-slate-500'">
                                    {{ dept.stat_orders }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</template>
