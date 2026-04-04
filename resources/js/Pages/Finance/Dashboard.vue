<script setup lang="ts">
// ─── Finance/Dashboard.vue ────────────────────────────────────────────────────
// Finance department overview: 6 KPI cards, recent encounters table, and
// pending authorizations table.  All data is injected as Inertia props from
// FinanceDashboardController.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import {
    BanknotesIcon,
    ClipboardDocumentListIcon,
    ShieldCheckIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'

// ── Types ──────────────────────────────────────────────────────────────────────

interface KPIs {
    current_month_capitation: number
    ytd_capitation: number
    open_encounters: number
    pending_authorizations: number
    open_denials: number
    revenue_at_risk: number
}

interface Encounter {
    id: number
    participant_name: string
    service_date: string | null
    service_type: string | null
    amount: number | null
    status: string | null
}

interface Auth {
    id: number
    participant_name: string
    service: string | null
    requested_date: string | null
    status: string | null
}

const props = defineProps<{
    kpis: KPIs
    recentEncounters: Encounter[]
    pendingAuths: Auth[]
}>()

// ── Formatting helpers ─────────────────────────────────────────────────────────

function fmtCurrency(cents: number): string {
    return '$' + Number(cents).toLocaleString()
}

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

// ── Status badge ───────────────────────────────────────────────────────────────

function statusClass(status: string | null): string {
    switch (status) {
        case 'approved':
            return 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300'
        case 'pending':
            return 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300'
        case 'denied':
            return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300'
        default:
            return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'
    }
}
</script>

<template>
    <Head title="Finance Dashboard" />

    <AppShell>
        <template #header>
            <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                Finance Dashboard
            </h1>
        </template>

        <div class="px-6 py-5 space-y-8">

            <!-- ── KPI grid ── -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

                <!-- Current Month Capitation -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3">
                    <div class="shrink-0 p-2 rounded-lg bg-blue-50 dark:bg-blue-900/30">
                        <BanknotesIcon class="w-5 h-5 text-blue-600 dark:text-blue-400" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">Current Month Capitation</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{ fmtCurrency(kpis.current_month_capitation) }}
                        </p>
                    </div>
                </div>

                <!-- YTD Capitation -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3">
                    <div class="shrink-0 p-2 rounded-lg bg-indigo-50 dark:bg-indigo-900/30">
                        <BanknotesIcon class="w-5 h-5 text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">YTD Capitation</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{ fmtCurrency(kpis.ytd_capitation) }}
                        </p>
                    </div>
                </div>

                <!-- Open Encounters -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3">
                    <div class="shrink-0 p-2 rounded-lg bg-slate-50 dark:bg-slate-700/50">
                        <ClipboardDocumentListIcon class="w-5 h-5 text-slate-600 dark:text-slate-400" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">Open Encounters</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{ kpis.open_encounters.toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- Pending Authorizations -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3">
                    <div class="shrink-0 p-2 rounded-lg bg-amber-50 dark:bg-amber-900/30">
                        <ShieldCheckIcon class="w-5 h-5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">Pending Authorizations</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{ kpis.pending_authorizations.toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- Open Denials -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3">
                    <div
                        :class="[
                            'shrink-0 p-2 rounded-lg',
                            kpis.open_denials > 0 ? 'bg-red-50 dark:bg-red-900/30' : 'bg-slate-50 dark:bg-slate-700/50',
                        ]"
                    >
                        <ExclamationTriangleIcon
                            :class="[
                                'w-5 h-5',
                                kpis.open_denials > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-400 dark:text-slate-500',
                            ]"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">Open Denials</p>
                        <p
                            :class="[
                                'text-xl font-bold mt-0.5',
                                kpis.open_denials > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-slate-100',
                            ]"
                        >
                            {{ kpis.open_denials.toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- Revenue at Risk -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3">
                    <div
                        :class="[
                            'shrink-0 p-2 rounded-lg',
                            kpis.revenue_at_risk > 0 ? 'bg-red-50 dark:bg-red-900/30' : 'bg-slate-50 dark:bg-slate-700/50',
                        ]"
                    >
                        <BanknotesIcon
                            :class="[
                                'w-5 h-5',
                                kpis.revenue_at_risk > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-400 dark:text-slate-500',
                            ]"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">Revenue at Risk</p>
                        <p
                            :class="[
                                'text-xl font-bold mt-0.5',
                                kpis.revenue_at_risk > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-slate-100',
                            ]"
                        >
                            {{ fmtCurrency(kpis.revenue_at_risk) }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── Recent Encounters ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700">
                <div class="px-5 py-3.5 border-b border-gray-200 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-slate-100">Recent Encounters</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-700/50">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Participant</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Service Date</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Type</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Amount</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            <tr v-if="recentEncounters.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">
                                    No recent encounters.
                                </td>
                            </tr>
                            <tr
                                v-for="enc in recentEncounters"
                                :key="enc.id"
                                class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors"
                            >
                                <td class="px-4 py-3 text-gray-800 dark:text-slate-200">{{ enc.participant_name }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ fmtDate(enc.service_date) }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ enc.service_type ?? '-' }}</td>
                                <td class="px-4 py-3 text-right text-gray-800 dark:text-slate-200 tabular-nums">
                                    {{ enc.amount != null ? fmtCurrency(enc.amount) : '-' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="enc.status"
                                        :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize', statusClass(enc.status)]"
                                    >
                                        {{ enc.status }}
                                    </span>
                                    <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Pending Authorizations ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700">
                <div class="px-5 py-3.5 border-b border-gray-200 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-slate-100">Pending Authorizations</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-700/50">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Participant</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Service</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Requested Date</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            <tr v-if="pendingAuths.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">
                                    No pending authorizations.
                                </td>
                            </tr>
                            <tr
                                v-for="auth in pendingAuths"
                                :key="auth.id"
                                class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors"
                            >
                                <td class="px-4 py-3 text-gray-800 dark:text-slate-200">{{ auth.participant_name }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ auth.service ?? '-' }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ fmtDate(auth.requested_date) }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="auth.status"
                                        :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize', statusClass(auth.status)]"
                                    >
                                        {{ auth.status }}
                                    </span>
                                    <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </AppShell>
</template>
