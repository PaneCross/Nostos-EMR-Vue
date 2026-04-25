<script setup lang="ts">
// ─── Finance/Encounters.vue ───────────────────────────────────────────────────
// Billing encounters list. Data loaded client-side via axios on mount.
// Supports status filter and row selection highlight.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { PlusIcon, FunnelIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'

// ── No server-side props (controller renders empty page, data loaded via axios) ──

// ── Types ──────────────────────────────────────────────────────────────────────

interface Encounter {
    id: number
    mrn: string | null
    participant_name: string | null
    service_date: string | null
    service_type: string | null
    amount: number | null
    status: string | null
    claim_id: string | null
}

// ── State ──────────────────────────────────────────────────────────────────────

const encounters = ref<Encounter[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const statusFilter = ref('')
const selectedId = ref<number | null>(null)

// ── Fetch ──────────────────────────────────────────────────────────────────────

function loadEncounters() {
    loading.value = true
    error.value = null
    axios
        .get('/billing/encounters')
        .then((res) => {
            encounters.value = res.data?.data ?? res.data ?? []
        })
        .catch(() => {
            error.value = 'Failed to load encounters.'
        })
        .finally(() => {
            loading.value = false
        })
}

onMounted(loadEncounters)

// ── Filtered rows ──────────────────────────────────────────────────────────────

const filtered = computed<Encounter[]>(() => {
    if (!statusFilter.value) return encounters.value
    return encounters.value.filter((e) => e.status === statusFilter.value)
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
        case 'paid':
            return 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300'
        case 'denied':
            return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300'
        default:
            return 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'
    }
}

function selectRow(id: number) {
    selectedId.value = selectedId.value === id ? null : id
}
</script>

<template>
    <Head title="Encounters" />

    <AppShell>
        <template #header>
            <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">Encounters</h1>
        </template>

        <div class="px-6 py-5 space-y-4">

            <!-- ── Toolbar ── -->
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <!-- Status filter -->
                <div class="flex items-center gap-2">
                    <FunnelIcon class="w-4 h-4 text-gray-400 dark:text-slate-500 shrink-0" aria-hidden="true" />
                    <select name="statusFilter"
                        v-model="statusFilter"
                        class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                        aria-label="Filter by status"
                    >
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="submitted">Submitted</option>
                        <option value="paid">Paid</option>
                        <option value="denied">Denied</option>
                    </select>
                </div>

                <!-- Phase U1 — encounter creation happens via clinical flow
                     (Appointment → Encounter auto-create); no standalone create UI.
                     Manual entry is via POST /billing/encounters with full 837P fields,
                     used by integration tests + API consumers, not finance staff. -->
                <span class="text-xs text-gray-500 dark:text-slate-400 italic px-3 py-1.5">
                    Encounters auto-generate from clinical visits. Manual entry via API.
                </span>
            </div>

            <!-- ── Table ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
                <!-- Loading state -->
                <div v-if="loading" class="py-12 text-center text-sm text-gray-400 dark:text-slate-500">
                    Loading encounters...
                </div>

                <!-- Error state -->
                <div v-else-if="error" class="py-12 text-center text-sm text-red-500 dark:text-red-400">
                    {{ error }}
                </div>

                <!-- Table -->
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-700/50">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Participant</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Service Date</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Service Type</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Amount</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Status</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Claim ID</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            <!-- Empty state -->
                            <tr v-if="filtered.length === 0">
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-400 dark:text-slate-500">
                                    No encounters found.
                                </td>
                            </tr>

                            <!-- Data rows -->
                            <tr
                                v-for="enc in filtered"
                                :key="enc.id"
                                :class="[
                                    'cursor-pointer transition-colors',
                                    selectedId === enc.id
                                        ? 'bg-blue-50 dark:bg-blue-900/20'
                                        : 'hover:bg-gray-50 dark:hover:bg-slate-700/30',
                                ]"
                                tabindex="0"
                                :aria-selected="selectedId === enc.id"
                                @click="selectRow(enc.id)"
                                @keydown.enter="selectRow(enc.id)"
                            >
                                <td class="px-4 py-3">
                                    <span class="text-gray-800 dark:text-slate-200 font-medium">
                                        {{ enc.participant_name ?? '-' }}
                                    </span>
                                    <span
                                        v-if="enc.mrn"
                                        class="ml-1.5 text-xs text-gray-400 dark:text-slate-500"
                                    >
                                        {{ enc.mrn }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400 whitespace-nowrap">
                                    {{ fmtDate(enc.service_date) }}
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                    {{ enc.service_type ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-800 dark:text-slate-200 tabular-nums">
                                    {{ fmtCurrency(enc.amount) }}
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
                                <td class="px-4 py-3 text-gray-500 dark:text-slate-400 font-mono text-xs">
                                    {{ enc.claim_id ?? '-' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppShell>
</template>
