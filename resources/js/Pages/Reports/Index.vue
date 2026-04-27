<script setup lang="ts">
// Reports/Index.vue
// Tabbed report catalog organized by category (Enrollment, Quality, Finance,
// Clinical, Administration). Filtered server-side by department access.
// KPI summary row loads client-side via GET /reports/data.
// Also includes a "By PACE Site" tab for site transfer history.
// Route: GET /reports → Inertia::render('Reports/Index')

import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import {
    DocumentChartBarIcon,
    ArrowDownTrayIcon,
    ClockIcon,
    BuildingOfficeIcon,
} from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Report {
    id:          string
    title:       string
    description: string
    category:    string
    export_url:  string | null
}

interface Kpis {
    enrolled_participants: number
    open_incidents:        number
    overdue_sdrs:          number
    meetings_this_month:   number
}

interface SiteTransferRow {
    participant_id:   number
    participant_name: string
    mrn:              string
    current_site:     string
    prior_sites:      string
    transfer_dates:   string
    transfer_count:   number
}

interface SiteOption { id: number; name: string }

// ── Props from Inertia ─────────────────────────────────────────────────────────

const props = defineProps<{
    reports:    Report[]
    department: string
    canExport:  boolean
}>()

// ── Constants ──────────────────────────────────────────────────────────────────

const CATEGORY_ORDER = ['Enrollment', 'Quality', 'Finance', 'Clinical', 'Administration']

const CATEGORY_COLORS: Record<string, string> = {
    Enrollment:     'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    Quality:        'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    Finance:        'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    Clinical:       'bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300',
    Administration: 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300',
}

// ── State ──────────────────────────────────────────────────────────────────────

const mainTab        = ref<'catalog' | 'site'>('catalog')
const activeCategory = ref<string>('All')
const kpis           = ref<Kpis | null>(null)
const downloading    = ref<string | null>(null)

// By PACE Site tab
const siteRows    = ref<SiteTransferRow[] | null>(null)
const siteOptions = ref<SiteOption[]>([])
const siteFilter  = ref('')
const siteLoading = ref(false)

// ── Load KPI summary on mount ─────────────────────────────────────────────────

onMounted(() => {
    axios.get('/reports/data')
        .then(res => { kpis.value = res.data.kpis })
        .catch(() => {/* non-fatal: KPIs are supplementary */})
})

// ── Computed ───────────────────────────────────────────────────────────────────

const availableCategories = computed(() =>
    CATEGORY_ORDER.filter(cat => props.reports.some(r => r.category === cat))
)

const visibleReports = computed(() =>
    activeCategory.value === 'All'
        ? props.reports
        : props.reports.filter(r => r.category === activeCategory.value)
)

const filteredSiteRows = computed(() => {
    if (!siteRows.value) return []
    if (!siteFilter.value) return siteRows.value
    return siteRows.value.filter(r =>
        r.current_site.includes(siteFilter.value) || r.prior_sites.includes(siteFilter.value)
    )
})

const kpiItems = computed(() => {
    if (!kpis.value) return null
    return [
        { label: 'Enrolled Participants', value: kpis.value.enrolled_participants, color: 'text-blue-600 dark:text-blue-400' },
        { label: 'Open Incidents',        value: kpis.value.open_incidents,        color: 'text-red-600 dark:text-red-400' },
        { label: 'Overdue SDRs',          value: kpis.value.overdue_sdrs,          color: 'text-amber-600 dark:text-amber-400' },
        { label: 'Meetings This Month',   value: kpis.value.meetings_this_month,   color: 'text-green-600 dark:text-green-400' },
    ]
})

// ── Methods ────────────────────────────────────────────────────────────────────

function switchToSiteTab() {
    mainTab.value = 'site'
    if (siteRows.value !== null) return
    siteLoading.value = true
    axios.get('/reports/site-transfers')
        .then(res => {
            siteRows.value    = res.data.participants ?? []
            siteOptions.value = res.data.sites ?? []
        })
        .catch(() => { siteRows.value = [] })
        .finally(() => { siteLoading.value = false })
}

async function handleExport(report: Report) {
    if (!report.export_url) return
    downloading.value = report.id
    try {
        window.open(report.export_url, '_blank')
    } finally {
        downloading.value = null
    }
}

function categoryCount(cat: string): number {
    return cat === 'All'
        ? props.reports.length
        : props.reports.filter(r => r.category === cat).length
}
</script>

<template>
    <AppShell>
        <Head title="Reports" />

        <div class="px-6 py-6 space-y-6">

            <!-- Header + view toggle -->
            <div class="flex items-end justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-slate-100">Reports</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                        {{ reports.length }} report{{ reports.length !== 1 ? 's' : '' }} available for your department.
                    </p>
                </div>
                <div class="flex gap-2">
                    <button
                        @click="mainTab = 'catalog'"
                        :class="[
                            'text-sm px-3 py-1.5 rounded-lg border font-medium transition-colors flex items-center gap-1.5',
                            mainTab === 'catalog'
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400'
                        ]"
                        aria-label="Show report catalog"
                    >
                        <DocumentChartBarIcon class="w-4 h-4" aria-hidden="true" />
                        Report Catalog
                    </button>
                    <button
                        @click="switchToSiteTab"
                        :class="[
                            'text-sm px-3 py-1.5 rounded-lg border font-medium transition-colors flex items-center gap-1.5',
                            mainTab === 'site'
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400'
                        ]"
                        aria-label="Show by PACE site"
                    >
                        <BuildingOfficeIcon class="w-4 h-4" aria-hidden="true" />
                        By PACE Site
                    </button>
                </div>
            </div>

            <!-- KPI row -->
            <div v-if="kpiItems" class="grid grid-cols-4 gap-3">
                <div
                    v-for="item in kpiItems"
                    :key="item.label"
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 text-center shadow-sm"
                >
                    <p :class="['text-2xl font-bold', item.color]">{{ item.value }}</p>
                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ item.label }}</p>
                </div>
            </div>

            <!-- By PACE Site tab -->
            <template v-if="mainTab === 'site'">
                <div class="space-y-4">
                    <div class="flex items-center justify-between gap-4 flex-wrap">
                        <div class="flex items-center gap-3">
                            <select name="siteFilter"
                                v-model="siteFilter"
                                class="text-sm border border-gray-300 dark:border-slate-600 dark:bg-slate-800 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                aria-label="Filter by site"
                            >
                                <option value="">All sites</option>
                                <option v-for="s in siteOptions" :key="s.id" :value="s.name">{{ s.name }}</option>
                            </select>
                            <span v-if="siteRows" class="text-sm text-gray-500 dark:text-slate-400">
                                {{ filteredSiteRows.length }} participant{{ filteredSiteRows.length !== 1 ? 's' : '' }} with transfer history
                            </span>
                        </div>
                        <a
                            v-if="canExport"
                            href="/reports/site-transfers/export"
                            class="inline-flex items-center gap-1.5 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium"
                        >
                            <ArrowDownTrayIcon class="w-4 h-4" aria-hidden="true" />
                            Export CSV
                        </a>
                    </div>

                    <p v-if="siteLoading" class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">
                        Loading transfer history...
                    </p>

                    <div
                        v-else-if="filteredSiteRows.length === 0"
                        class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-6 py-10 text-center"
                    >
                        <BuildingOfficeIcon class="w-10 h-10 text-gray-300 dark:text-slate-600 mx-auto mb-3" aria-hidden="true" />
                        <p class="text-gray-400 dark:text-slate-500 text-sm">No completed site transfers on record.</p>
                    </div>

                    <div v-else class="overflow-x-auto rounded-xl border border-gray-200 dark:border-slate-700">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-slate-700/50">
                                <tr>
                                    <th
                                        v-for="h in ['Participant', 'MRN', 'Current Site', 'Prior Sites', 'Transfer Date(s)', 'Count']"
                                        :key="h"
                                        class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                    >{{ h }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-slate-700 bg-white dark:bg-slate-800">
                                <tr
                                    v-for="row in filteredSiteRows"
                                    :key="row.participant_id"
                                    class="hover:bg-gray-50 dark:hover:bg-slate-700/50"
                                >
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{{ row.participant_name }}</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-slate-400 font-mono text-xs">{{ row.mrn }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-slate-300">{{ row.current_site }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-slate-400 text-xs">{{ row.prior_sites }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-slate-400 text-xs">{{ row.transfer_dates }}</td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300">
                                            {{ row.transfer_count }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <!-- Category tab pills -->
            <div
                v-if="mainTab === 'catalog' && availableCategories.length > 1"
                class="flex gap-2 flex-wrap"
            >
                <button
                    v-for="cat in ['All', ...availableCategories]"
                    :key="cat"
                    @click="activeCategory = cat"
                    :class="[
                        'text-xs px-3 py-1.5 rounded-full border font-medium transition-colors',
                        activeCategory === cat
                            ? 'bg-blue-600 text-white border-blue-600'
                            : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400 dark:hover:border-blue-500'
                    ]"
                    :aria-pressed="activeCategory === cat"
                >
                    {{ cat }}
                    <span class="ml-1.5 text-xs opacity-70">{{ categoryCount(cat) }}</span>
                </button>
            </div>

            <!-- Report cards grid -->
            <template v-if="mainTab === 'catalog'">
                <div
                    v-if="visibleReports.length === 0"
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-6 py-10 text-center shadow-sm"
                >
                    <DocumentChartBarIcon class="w-10 h-10 text-gray-300 dark:text-slate-600 mx-auto mb-3" aria-hidden="true" />
                    <p class="text-gray-400 dark:text-slate-500 text-sm">
                        No reports available for the selected category.
                    </p>
                </div>

                <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="report in visibleReports"
                        :key="report.id"
                        class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5 flex flex-col gap-3"
                    >
                        <!-- Category badge + title -->
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <span :class="['inline-flex text-xs font-medium px-2 py-0.5 rounded mb-1.5', CATEGORY_COLORS[report.category] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400']">
                                    {{ report.category }}
                                </span>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 leading-snug">
                                    {{ report.title }}
                                </h3>
                            </div>
                            <DocumentChartBarIcon class="w-5 h-5 text-gray-400 dark:text-slate-500 shrink-0 mt-0.5" aria-hidden="true" />
                        </div>

                        <!-- Description -->
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed flex-1">
                            {{ report.description }}
                        </p>

                        <!-- Action -->
                        <div class="pt-1 border-t border-gray-100 dark:border-slate-700">
                            <button
                                v-if="report.export_url && canExport"
                                @click="handleExport(report)"
                                :disabled="downloading === report.id"
                                class="inline-flex items-center gap-1.5 text-xs text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium disabled:opacity-50 transition-colors"
                                :aria-label="`Export ${report.title} as CSV`"
                            >
                                <ArrowDownTrayIcon class="w-3.5 h-3.5" aria-hidden="true" />
                                {{ downloading === report.id ? 'Downloading...' : 'Export CSV' }}
                            </button>
                            <span
                                v-else-if="report.export_url && !canExport"
                                class="text-xs text-gray-400 dark:text-slate-500"
                            >
                                Export restricted
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-1 text-xs text-gray-400 dark:text-slate-500"
                            >
                                <ClockIcon class="w-3.5 h-3.5" aria-hidden="true" />
                                Export coming soon
                            </span>
                        </div>
                    </div>
                </div>
            </template>

        </div>
    </AppShell>
</template>
