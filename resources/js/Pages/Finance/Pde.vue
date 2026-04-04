<script setup lang="ts">
// ─── Finance/Pde.vue ──────────────────────────────────────────────────────────
// Part D Events (PDE) list. Data loaded client-side via axios on mount.
// Supports status filter dropdown.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { FunnelIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'

// ── No server-side props (all data loaded client-side via axios) ───────────────

// ── Types ──────────────────────────────────────────────────────────────────────

interface PdeRecord {
    id: number
    mrn: string | null
    participant_name: string | null
    drug_name: string | null
    dispense_date: string | null
    days_supply: number | null
    gross_drug_cost: number | null
    patient_pay: number | null
    status: string | null
}

// ── State ──────────────────────────────────────────────────────────────────────

const records = ref<PdeRecord[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const statusFilter = ref('')

// ── Fetch ──────────────────────────────────────────────────────────────────────

function loadRecords() {
    loading.value = true
    error.value = null
    axios
        .get('/billing/pde')
        .then((res) => {
            records.value = res.data?.data ?? res.data ?? []
        })
        .catch(() => {
            error.value = 'Failed to load PDE records.'
        })
        .finally(() => {
            loading.value = false
        })
}

onMounted(loadRecords)

// ── Filtered rows ──────────────────────────────────────────────────────────────

const filtered = computed<PdeRecord[]>(() => {
    if (!statusFilter.value) return records.value
    return records.value.filter((r) => r.status === statusFilter.value)
})

// ── Helpers ────────────────────────────────────────────────────────────────────

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function fmtCurrency(cents: number | null): string {
    if (cents == null) return '-'
    return '$' + Number(cents).toLocaleString()
}

function statusClass(status: string | null): string {
    switch (status) {
        case 'pending':
            return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'
        case 'submitted':
            return 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300'
        case 'accepted':
            return 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300'
        case 'rejected':
            return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300'
        default:
            return 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'
    }
}
</script>

<template>
    <Head title="Part D Events (PDE)" />

    <AppShell>
        <template #header>
            <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                Part D Events (PDE)
            </h1>
        </template>

        <div class="px-6 py-5 space-y-4">
            <!-- ── Filter bar ── -->
            <div class="flex items-center gap-2">
                <FunnelIcon
                    class="w-4 h-4 text-gray-400 dark:text-slate-500 shrink-0"
                    aria-hidden="true"
                />
                <select
                    v-model="statusFilter"
                    class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                    aria-label="Filter by status"
                >
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="submitted">Submitted</option>
                    <option value="accepted">Accepted</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>

            <!-- ── Table ── -->
            <div
                class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden"
            >
                <!-- Loading -->
                <div
                    v-if="loading"
                    class="py-12 text-center text-sm text-gray-400 dark:text-slate-500"
                >
                    Loading PDE records...
                </div>

                <!-- Error -->
                <div
                    v-else-if="error"
                    class="py-12 text-center text-sm text-red-500 dark:text-red-400"
                >
                    {{ error }}
                </div>

                <!-- Table content -->
                <div v-else class="overflow-x-auto">
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
                                    Drug Name
                                </th>
                                <th
                                    class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                >
                                    Dispense Date
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                >
                                    Days Supply
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                >
                                    Gross Drug Cost
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                >
                                    Patient Pay
                                </th>
                                <th
                                    class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                                >
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            <!-- Empty state -->
                            <tr v-if="filtered.length === 0">
                                <td
                                    colspan="7"
                                    class="px-4 py-10 text-center text-sm text-gray-400 dark:text-slate-500"
                                >
                                    No PDE records found.
                                </td>
                            </tr>

                            <!-- Data rows -->
                            <tr
                                v-for="rec in filtered"
                                :key="rec.id"
                                class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors"
                            >
                                <td class="px-4 py-3">
                                    <span class="font-medium text-gray-800 dark:text-slate-200">
                                        {{ rec.participant_name ?? '-' }}
                                    </span>
                                    <span
                                        v-if="rec.mrn"
                                        class="ml-1.5 text-xs text-gray-400 dark:text-slate-500"
                                    >
                                        {{ rec.mrn }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-slate-300">
                                    {{ rec.drug_name ?? '-' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-gray-600 dark:text-slate-400 whitespace-nowrap"
                                >
                                    {{ fmtDate(rec.dispense_date) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-gray-600 dark:text-slate-400 tabular-nums"
                                >
                                    {{ rec.days_supply?.toLocaleString() ?? '-' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-gray-800 dark:text-slate-200 tabular-nums"
                                >
                                    {{ fmtCurrency(rec.gross_drug_cost) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-gray-800 dark:text-slate-200 tabular-nums"
                                >
                                    {{ fmtCurrency(rec.patient_pay) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="rec.status"
                                        :class="[
                                            'inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize',
                                            statusClass(rec.status),
                                        ]"
                                    >
                                        {{ rec.status }}
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
