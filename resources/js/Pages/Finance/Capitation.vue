<script setup lang="ts">
// ─── Finance/Capitation.vue ───────────────────────────────────────────────────
// Capitation management: 3 KPI cards, participant records table with search.
// Data injected as Inertia props from CapitationController.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import {
    BanknotesIcon,
    UsersIcon,
    ChartBarIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'

// ── Types ──────────────────────────────────────────────────────────────────────

interface CapitationKPIs {
    current_month_total: number
    participant_count: number
    avg_raf_score: number | null
}

interface CapitationRecord {
    id: number
    participant: {
        id: number
        mrn: string
        first_name: string
        last_name: string
    } | null
    month_year: string
    total_capitation: number
    hcc_risk_score: number | null
    payment_status: string | null
}

const props = defineProps<{
    kpis: CapitationKPIs
    records: CapitationRecord[]
    currentMonthYear: string
}>()

// ── Search ─────────────────────────────────────────────────────────────────────

const search = ref('')

const filtered = computed<CapitationRecord[]>(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.records
    return props.records.filter((r) => {
        if (!r.participant) return false
        const name = (r.participant.first_name + ' ' + r.participant.last_name).toLowerCase()
        const mrn = r.participant.mrn.toLowerCase()
        return name.includes(q) || mrn.includes(q)
    })
})

// ── Helpers ────────────────────────────────────────────────────────────────────

function fmtCurrency(cents: number): string {
    return '$' + Number(cents).toLocaleString()
}

function paymentStatusClass(status: string | null): string {
    switch (status) {
        case 'paid':
            return 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300'
        case 'pending':
            return 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300'
        case 'unpaid':
            return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300'
        default:
            return 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'
    }
}

function participantName(record: CapitationRecord): string {
    if (!record.participant) return '-'
    return record.participant.first_name + ' ' + record.participant.last_name
}
</script>

<template>
    <Head title="Capitation" />

    <AppShell>
        <template #header>
            <div class="flex items-center gap-3">
                <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                    Capitation
                </h1>
                <span
                    class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300"
                >
                    {{ currentMonthYear }}
                </span>
            </div>
        </template>

        <div class="px-6 py-5 space-y-6">
            <!-- ── KPI cards ── -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <!-- Current Month Total -->
                <div
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3"
                >
                    <div class="shrink-0 p-2 rounded-lg bg-blue-50 dark:bg-blue-900/30">
                        <BanknotesIcon
                            class="w-5 h-5 text-blue-600 dark:text-blue-400"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">
                            Current Month Total
                        </p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{ fmtCurrency(kpis.current_month_total) }}
                        </p>
                    </div>
                </div>

                <!-- Participant Count -->
                <div
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3"
                >
                    <div class="shrink-0 p-2 rounded-lg bg-indigo-50 dark:bg-indigo-900/30">
                        <UsersIcon
                            class="w-5 h-5 text-indigo-600 dark:text-indigo-400"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">
                            Participant Count
                        </p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{ kpis.participant_count.toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- Avg RAF Score -->
                <div
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 flex items-start gap-3"
                >
                    <div class="shrink-0 p-2 rounded-lg bg-emerald-50 dark:bg-emerald-900/30">
                        <ChartBarIcon
                            class="w-5 h-5 text-emerald-600 dark:text-emerald-400"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-slate-400 truncate">
                            Avg RAF Score
                        </p>
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-100 mt-0.5">
                            {{
                                kpis.avg_raf_score != null
                                    ? Number(kpis.avg_raf_score).toFixed(3)
                                    : '-'
                            }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── Search bar ── -->
            <div class="relative max-w-xs">
                <MagnifyingGlassIcon
                    class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-slate-500 pointer-events-none"
                    aria-hidden="true"
                />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search by name or MRN"
                    class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400"
                />
            </div>

            <!-- ── Table ── -->
            <div
                class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden"
            >
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-700/50">
                            <tr>
                                <th
                                    class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                >
                                    Participant
                                </th>
                                <th
                                    class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                >
                                    Month
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                >
                                    Total
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                >
                                    HCC Risk
                                </th>
                                <th
                                    class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                >
                                    Payment Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            <!-- Empty state -->
                            <tr v-if="filtered.length === 0">
                                <td
                                    colspan="5"
                                    class="px-4 py-10 text-center text-sm text-gray-400 dark:text-slate-500"
                                >
                                    No capitation records.
                                </td>
                            </tr>

                            <!-- Data rows -->
                            <tr
                                v-for="rec in filtered"
                                :key="rec.id"
                                class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors"
                            >
                                <td class="px-4 py-3">
                                    <button
                                        v-if="rec.participant"
                                        type="button"
                                        class="text-left group"
                                        @click="router.visit('/participants/' + rec.participant.id)"
                                    >
                                        <span
                                            class="font-medium text-blue-600 dark:text-blue-400 group-hover:underline"
                                        >
                                            {{ participantName(rec) }}
                                        </span>
                                        <span
                                            class="ml-1.5 text-xs text-gray-400 dark:text-slate-500"
                                        >
                                            {{ rec.participant.mrn }}
                                        </span>
                                    </button>
                                    <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                                </td>
                                <td
                                    class="px-4 py-3 text-gray-600 dark:text-slate-400 whitespace-nowrap"
                                >
                                    {{ rec.month_year }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-gray-800 dark:text-slate-200 tabular-nums"
                                >
                                    {{ fmtCurrency(rec.total_capitation) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-gray-600 dark:text-slate-400 tabular-nums"
                                >
                                    {{
                                        rec.hcc_risk_score != null
                                            ? Number(rec.hcc_risk_score).toFixed(3)
                                            : '-'
                                    }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="rec.payment_status"
                                        :class="[
                                            'inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize',
                                            paymentStatusClass(rec.payment_status),
                                        ]"
                                    >
                                        {{ rec.payment_status }}
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
