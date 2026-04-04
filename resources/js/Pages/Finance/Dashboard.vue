<script setup lang="ts">
// ─── Finance/Dashboard.vue ────────────────────────────────────────────────────
// Finance department overview: KPI cards, recent capitation table, and
// expiring authorizations table. Data injected from FinanceDashboardController.
// ─────────────────────────────────────────────────────────────────────────────

import { Head } from '@inertiajs/vue3'
import {
    BanknotesIcon,
    ClipboardDocumentListIcon,
    ShieldCheckIcon,
    UsersIcon,
} from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface KPIs {
    capitation_this_month: number
    auths_expiring_30d: number
    encounters_this_month: number
    active_participants: number
}

interface CapitationRow {
    month_year: string
    total: number
    participant_count: number
}

interface ParticipantStub {
    id: number
    mrn: string
    first_name: string
    last_name: string
}

interface ExpiringAuth {
    id: number
    service_type: string | null
    authorized_end: string | null
    status: string | null
    participant: ParticipantStub | null
}

const props = defineProps<{
    kpis: KPIs
    recentCapitation: CapitationRow[]
    expiringAuths: ExpiringAuth[]
    currentMonthYear: string
    serviceTypeLabels: Record<string, string>
}>()

// ── Formatting helpers ─────────────────────────────────────────────────────────

function fmtCurrency(cents: number | null | undefined): string {
    if (cents == null) return '-'
    return '$' + Number(cents).toLocaleString()
}

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function fmtMonth(ym: string): string {
    const [year, month] = ym.split('-')
    const d = new Date(Number(year), Number(month) - 1, 1)
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'long' })
}

function participantName(auth: ExpiringAuth): string {
    if (!auth.participant) return '-'
    return `${auth.participant.last_name}, ${auth.participant.first_name}`
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
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

                <!-- Current Month Capitation -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3">
                    <div class="shrink-0 p-2 rounded-lg bg-blue-50 dark:bg-blue-900/30">
                        <BanknotesIcon class="w-5 h-5 text-blue-600 dark:text-blue-400" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">Capitation This Month</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{ fmtCurrency(kpis.capitation_this_month) }}
                        </p>
                    </div>
                </div>

                <!-- Encounters This Month -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3">
                    <div class="shrink-0 p-2 rounded-lg bg-slate-50 dark:bg-slate-700/50">
                        <ClipboardDocumentListIcon class="w-5 h-5 text-slate-600 dark:text-slate-400" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">Encounters This Month</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{ (kpis.encounters_this_month ?? 0).toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- Auths Expiring 30d -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3">
                    <div class="shrink-0 p-2 rounded-lg bg-amber-50 dark:bg-amber-900/30">
                        <ShieldCheckIcon class="w-5 h-5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">Auths Expiring (30d)</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{ (kpis.auths_expiring_30d ?? 0).toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- Active Participants -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3">
                    <div class="shrink-0 p-2 rounded-lg bg-green-50 dark:bg-green-900/30">
                        <UsersIcon class="w-5 h-5 text-green-600 dark:text-green-400" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">Active Participants</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{ (kpis.active_participants ?? 0).toLocaleString() }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── Recent Capitation ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700">
                <div class="px-5 py-3.5 border-b border-gray-200 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-slate-100">Recent Capitation</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-700/50">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Month</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Total</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Participants</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            <tr v-if="recentCapitation.length === 0">
                                <td colspan="3" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">
                                    No capitation records found.
                                </td>
                            </tr>
                            <tr
                                v-for="row in recentCapitation"
                                :key="row.month_year"
                                class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors"
                            >
                                <td class="px-4 py-3 text-gray-800 dark:text-slate-200">{{ fmtMonth(row.month_year) }}</td>
                                <td class="px-4 py-3 text-right text-gray-800 dark:text-slate-200 tabular-nums">{{ fmtCurrency(row.total) }}</td>
                                <td class="px-4 py-3 text-right text-gray-600 dark:text-slate-400 tabular-nums">{{ row.participant_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Expiring Authorizations ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700">
                <div class="px-5 py-3.5 border-b border-gray-200 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-slate-100">Authorizations Expiring Soon</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-700/50">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Participant</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Service Type</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Expires</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            <tr v-if="expiringAuths.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">
                                    No authorizations expiring within 30 days.
                                </td>
                            </tr>
                            <tr
                                v-for="auth in expiringAuths"
                                :key="auth.id"
                                class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors"
                            >
                                <td class="px-4 py-3 text-gray-800 dark:text-slate-200">{{ participantName(auth) }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                    {{ serviceTypeLabels[auth.service_type ?? ''] ?? auth.service_type ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ fmtDate(auth.authorized_end) }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="auth.status"
                                        :class="[
                                            'inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize',
                                            auth.status === 'approved' ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300' :
                                            auth.status === 'pending'  ? 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300' :
                                            'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'
                                        ]"
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
