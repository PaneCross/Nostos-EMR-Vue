<script setup lang="ts">
// ─── Executive / Credentials Dashboard ───────────────────────────────────────
// Org-wide compliance matrix : credential definitions x departments. Each cell
// shows the bucket counts (compliant / expiring / expired / invalid / missing).
// Click any cell to drill down into the user list behind it. CSV export covers
// the matrix as currently filtered.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import OrgSettingsTabBar from '@/Pages/Executive/components/OrgSettingsTabBar.vue'
import axios from 'axios'
import {
    PresentationChartLineIcon, LockClosedIcon, XMarkIcon,
    ArrowDownTrayIcon, InformationCircleIcon,
} from '@heroicons/vue/24/outline'

interface Bucket {
    users_required: number
    users_compliant: number
    users_expiring_30d: number
    users_expired: number
    users_invalid: number
    users_missing: number
}
interface Row {
    definition_id: number
    code: string
    title: string
    credential_type: string
    is_cms_mandatory: boolean
    cells: Record<string, Bucket>
    totals: Bucket
}
interface Matrix {
    departments: string[]
    rows: Row[]
    summary: Bucket
    generated_at: string
}

const props = defineProps<{ matrix: Matrix }>()

const filterDefId = ref<number | 'all'>('all')
const filterDept  = ref<string | 'all'>('all')
const drilldown = ref<{
    title: string, dept: string, bucket: string, users: any[],
} | null>(null)
const drilldownLoading = ref(false)

const filteredRows = computed(() => {
    if (filterDefId.value === 'all') return props.matrix.rows
    return props.matrix.rows.filter(r => r.definition_id === filterDefId.value)
})

const visibleDepts = computed(() => {
    if (filterDept.value === 'all') return props.matrix.departments
    return [filterDept.value as string]
})

function deptLabel(code: string): string {
    return code.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function compliancePct(b: Bucket): number {
    if (b.users_required === 0) return 100
    return Math.round((b.users_compliant / b.users_required) * 100)
}

function cellClass(b: Bucket): string {
    if (b.users_required === 0) return 'bg-gray-50 dark:bg-slate-900/40 text-gray-400'
    const pct = compliancePct(b)
    if (b.users_missing > 0 || b.users_invalid > 0) return 'bg-rose-50 dark:bg-rose-950/40'
    if (b.users_expired > 0) return 'bg-rose-50 dark:bg-rose-950/30'
    if (pct < 70) return 'bg-amber-50 dark:bg-amber-950/40'
    if (pct < 100) return 'bg-amber-50 dark:bg-amber-950/20'
    return 'bg-emerald-50 dark:bg-emerald-950/30'
}

async function openDrilldown(row: Row, dept: string, bucket: keyof Bucket) {
    drilldownLoading.value = true
    drilldown.value = { title: row.title, dept, bucket, users: [] }
    try {
        const { data } = await axios.get(`/executive/credentials-dashboard/drilldown/${row.definition_id}/${dept}/${bucket}`)
        drilldown.value = { title: row.title, dept, bucket, users: data.users }
    } finally {
        drilldownLoading.value = false
    }
}

async function exportPerUserCsv() {
    // Per-user view : each row is a (user, credential) gap. Useful for HR / QA
    // to drive remediation. Pulls drilldown data for every non-compliant cell.
    const buckets: ('users_missing'|'users_expired'|'users_invalid'|'users_expiring_30d')[] = [
        'users_missing','users_expired','users_invalid','users_expiring_30d'
    ]
    const lines = ['Credential,Department,Bucket,User,Job Title,Expires,Status,Days remaining']
    for (const row of filteredRows.value) {
        for (const dept of visibleDepts.value) {
            const cell = row.cells[dept]
            if (!cell || cell.users_required === 0) continue
            for (const b of buckets) {
                if ((cell as any)[b] === 0) continue
                try {
                    const { data } = await axios.get(`/executive/credentials-dashboard/drilldown/${row.definition_id}/${dept}/${b}`)
                    for (const u of data.users) {
                        lines.push([
                            `"${row.title.replace(/"/g, '""')}"`,
                            deptLabel(dept),
                            b.replace('users_',''),
                            `"${u.name}"`,
                            u.job_title ?? '',
                            u.expires_at ?? '',
                            u.cms_status ?? '',
                            u.days_remaining ?? '',
                        ].join(','))
                    }
                } catch (e) {
                    // continue on individual cell failures
                }
            }
        }
    }
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `credentials-gaps-${new Date().toISOString().slice(0,10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
}

function exportCsv() {
    const headers = ['Credential', 'CMS-mandatory', ...visibleDepts.value.map(deptLabel), 'Required (total)', 'Compliant', 'Expiring 30d', 'Expired', 'Invalid', 'Missing']
    const lines = [headers.join(',')]

    for (const row of filteredRows.value) {
        const cells: string[] = visibleDepts.value.map(d => {
            const c = row.cells[d]
            if (!c || c.users_required === 0) return ''
            return `"${c.users_compliant}/${c.users_required}"`
        })
        lines.push([
            `"${row.title.replace(/"/g, '""')}"`,
            row.is_cms_mandatory ? 'yes' : '',
            ...cells,
            row.totals.users_required,
            row.totals.users_compliant,
            row.totals.users_expiring_30d,
            row.totals.users_expired,
            row.totals.users_invalid,
            row.totals.users_missing,
        ].join(','))
    }

    const blob = new Blob([lines.join('\n')], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `credentials-coverage-${new Date().toISOString().slice(0,10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
}

const bucketLabels: Record<string, string> = {
    users_compliant:    'Compliant',
    users_expiring_30d: 'Expiring within 30 days',
    users_expired:      'Expired',
    users_invalid:      'Suspended / Revoked / Pending',
    users_missing:      'Missing (never on file)',
}
</script>

<template>
    <AppShell>
        <Head title="Credentials Dashboard" />

        <div class="max-w-7xl mx-auto px-6 py-8">
            <div class="flex items-start justify-between mb-6 flex-wrap gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100 flex items-center gap-2">
                        <PresentationChartLineIcon class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                        Credentials Dashboard
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                        Org-wide compliance matrix. Click any cell to see the users behind it.
                    </p>
                </div>
                <div class="flex gap-2">
                    <button @click="exportCsv" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 text-sm font-medium text-gray-700 dark:text-slate-200">
                        <ArrowDownTrayIcon class="w-4 h-4" /> Matrix CSV
                    </button>
                    <button @click="exportPerUserCsv" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 text-sm font-medium text-gray-700 dark:text-slate-200" title="One row per (user, credential gap) - useful for HR remediation">
                        <ArrowDownTrayIcon class="w-4 h-4" /> Per-user gaps CSV
                    </button>
                </div>
            </div>

            <OrgSettingsTabBar active="dashboard" />

            <!-- Summary cards -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <div class="rounded-xl border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50 dark:bg-emerald-950/30 p-4">
                    <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Compliant</p>
                    <p class="text-2xl font-bold text-emerald-800 dark:text-emerald-200 tabular-nums">{{ matrix.summary.users_compliant }}</p>
                </div>
                <div class="rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50 dark:bg-amber-950/30 p-4">
                    <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Expiring 30d</p>
                    <p class="text-2xl font-bold text-amber-800 dark:text-amber-200 tabular-nums">{{ matrix.summary.users_expiring_30d }}</p>
                </div>
                <div class="rounded-xl border border-rose-200 dark:border-rose-900/40 bg-rose-50 dark:bg-rose-950/30 p-4">
                    <p class="text-xs uppercase tracking-wide text-rose-700 dark:text-rose-300">Expired</p>
                    <p class="text-2xl font-bold text-rose-800 dark:text-rose-200 tabular-nums">{{ matrix.summary.users_expired }}</p>
                </div>
                <div class="rounded-xl border border-rose-300 dark:border-rose-800 bg-rose-100 dark:bg-rose-950/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-rose-700 dark:text-rose-300">Invalid</p>
                    <p class="text-2xl font-bold text-rose-800 dark:text-rose-200 tabular-nums">{{ matrix.summary.users_invalid }}</p>
                </div>
                <div class="rounded-xl border border-rose-300 dark:border-rose-800 bg-rose-100 dark:bg-rose-950/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-rose-700 dark:text-rose-300">Missing</p>
                    <p class="text-2xl font-bold text-rose-800 dark:text-rose-200 tabular-nums">{{ matrix.summary.users_missing }}</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <label class="text-xs text-gray-700 dark:text-slate-300 flex items-center gap-1.5">
                    Credential
                    <select v-model="filterDefId" class="px-2 py-1.5 text-sm rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                        <option value="all">All credentials</option>
                        <option v-for="r in matrix.rows" :key="r.definition_id" :value="r.definition_id">{{ r.title }}</option>
                    </select>
                </label>
                <label class="text-xs text-gray-700 dark:text-slate-300 flex items-center gap-1.5">
                    Department
                    <select v-model="filterDept" class="px-2 py-1.5 text-sm rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                        <option value="all">All departments</option>
                        <option v-for="d in matrix.departments" :key="d" :value="d">{{ deptLabel(d) }}</option>
                    </select>
                </label>
                <span class="text-xs text-gray-500 dark:text-slate-400 ml-auto">
                    Generated {{ new Date(matrix.generated_at).toLocaleString() }}
                </span>
            </div>

            <!-- Help banner -->
            <div class="rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/30 px-5 py-3 mb-6 flex items-start gap-3 text-xs">
                <InformationCircleIcon class="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div class="text-blue-900 dark:text-blue-100">
                    Each cell shows <code>compliant / required</code>. Click any non-empty cell to see the users.
                    Empty cells mean no one in that department is required to hold that credential.
                </div>
            </div>

            <!-- Matrix -->
            <div class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 dark:bg-slate-800 text-gray-700 dark:text-slate-300 sticky top-0">
                        <tr>
                            <th class="text-left px-3 py-2 font-medium sticky left-0 bg-gray-50 dark:bg-slate-800 z-10 min-w-[260px]">Credential</th>
                            <th v-for="d in visibleDepts" :key="d" class="px-2 py-2 font-medium text-center whitespace-nowrap">{{ deptLabel(d) }}</th>
                            <th class="px-2 py-2 font-medium text-center bg-gray-100 dark:bg-slate-700/50">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800">
                        <tr v-for="row in filteredRows" :key="row.definition_id" class="hover:bg-gray-50/50 dark:hover:bg-slate-800/30">
                            <td class="px-3 py-2 sticky left-0 bg-white dark:bg-slate-900 z-10">
                                <div class="font-medium text-gray-900 dark:text-slate-100 flex items-center gap-1.5">
                                    {{ row.title }}
                                    <LockClosedIcon v-if="row.is_cms_mandatory" class="w-3 h-3 text-rose-500" title="CMS-mandatory" />
                                </div>
                            </td>
                            <td v-for="d in visibleDepts" :key="d"
                                :class="[cellClass(row.cells[d]), 'px-2 py-2 text-center cursor-pointer transition-colors']"
                                @click="(row.cells[d]?.users_required ?? 0) > 0 ? openDrilldown(row, d, 'users_required') : null"
                                :title="(row.cells[d]?.users_required ?? 0) > 0 ? `${row.cells[d].users_compliant}/${row.cells[d].users_required} compliant` : 'N/A'">
                                <template v-if="(row.cells[d]?.users_required ?? 0) > 0">
                                    <div class="text-sm font-semibold tabular-nums text-gray-900 dark:text-slate-100">
                                        {{ row.cells[d].users_compliant }}/{{ row.cells[d].users_required }}
                                    </div>
                                    <div class="text-[10px] text-gray-500 dark:text-slate-400 flex items-center justify-center gap-1">
                                        <span v-if="row.cells[d].users_missing">{{ row.cells[d].users_missing }} missing</span>
                                        <span v-if="row.cells[d].users_expired" class="text-rose-600 dark:text-rose-400">{{ row.cells[d].users_expired }} exp</span>
                                        <span v-if="row.cells[d].users_invalid" class="text-rose-700 dark:text-rose-300">{{ row.cells[d].users_invalid }} inv</span>
                                    </div>
                                </template>
                                <span v-else class="text-gray-300 dark:text-slate-600">-</span>
                            </td>
                            <td class="px-2 py-2 text-center bg-gray-100 dark:bg-slate-700/50">
                                <div class="text-sm font-bold tabular-nums">{{ row.totals.users_compliant }}/{{ row.totals.users_required }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Drilldown modal -->
            <div v-if="drilldown" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4 overflow-y-auto" @click.self="drilldown = null">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-200 dark:border-slate-700 max-w-2xl w-full p-6 my-8 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100">{{ drilldown.title }}</h2>
                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                                {{ deptLabel(drilldown.dept) }} ·
                                {{ bucketLabels[drilldown.bucket] ?? drilldown.bucket.replace('users_','') }}
                            </p>
                        </div>
                        <button @click="drilldown = null" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-200"><XMarkIcon class="w-5 h-5" /></button>
                    </div>
                    <div v-if="drilldownLoading" class="text-center py-6 text-gray-500 dark:text-slate-400">Loading...</div>
                    <div v-else-if="drilldown.users.length === 0" class="text-center py-6 text-gray-500 dark:text-slate-400">No users in this bucket.</div>
                    <table v-else class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-800 text-xs text-gray-700 dark:text-slate-300">
                            <tr>
                                <th class="text-left px-3 py-2 font-medium">User</th>
                                <th class="text-left px-3 py-2 font-medium">Job Title</th>
                                <th class="text-left px-3 py-2 font-medium">Expires</th>
                                <th class="text-left px-3 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-800">
                            <tr v-for="u in drilldown.users" :key="u.user_id">
                                <td class="px-3 py-2 text-gray-900 dark:text-slate-100">{{ u.name }}</td>
                                <td class="px-3 py-2 text-gray-600 dark:text-slate-300">{{ u.job_title ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-600 dark:text-slate-300 text-xs">
                                    {{ u.expires_at ?? '-' }}
                                    <span v-if="u.days_remaining !== null && u.days_remaining !== undefined" class="block text-gray-400">
                                        {{ u.days_remaining < 0 ? `${Math.abs(u.days_remaining)}d overdue` : u.days_remaining === 0 ? 'today' : `in ${u.days_remaining}d` }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-gray-600 dark:text-slate-300 text-xs">{{ u.cms_status ?? '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppShell>
</template>
