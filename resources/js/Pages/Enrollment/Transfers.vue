<script setup lang="ts">
// ─── Enrollment/Transfers.vue ─────────────────────────────────────────────────
// Admin view for participant site transfers. Lists all transfers with status
// filtering and pagination. Clicking a row navigates to the participant profile.
// ─────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { ArrowRightIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Transfer {
    id: number
    participant: { id: number; name: string; mrn: string }
    from_site: { id: number; name: string } | null
    to_site: { id: number; name: string } | null
    transfer_reason_label: string
    requested_by: string | null
    approved_by: string | null
    requested_at: string | null
    effective_date: string | null
    status: 'pending' | 'approved' | 'completed' | 'cancelled'
}

interface Site {
    id: number
    name: string
}

interface Paginator {
    data: Transfer[]
    current_page: number
    last_page: number
    total: number
}

const props = defineProps<{
    transfers: Paginator
    sites: Site[]
    transferReasons: Record<string, string>
    filters: { status?: string }
}>()

// ── Filter state ───────────────────────────────────────────────────────────────

const statusFilter = ref(props.filters.status ?? '')

function applyStatusFilter(e: Event) {
    const val = (e.target as HTMLSelectElement).value
    statusFilter.value = val
    router.get(
        '/enrollment/transfers',
        { status: val },
        { preserveState: true, replace: true },
    )
}

// ── Pagination ─────────────────────────────────────────────────────────────────

function goToPage(page: number) {
    router.get(
        '/enrollment/transfers',
        { status: statusFilter.value, page },
        { preserveState: true, replace: true },
    )
}

// ── Date formatting ────────────────────────────────────────────────────────────

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

// ── Status badge ───────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
    pending: 'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    approved: 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300',
    completed: 'bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300',
    cancelled: 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
}

const STATUS_LABELS: Record<string, string> = {
    pending: 'Pending',
    approved: 'Approved',
    completed: 'Completed',
    cancelled: 'Cancelled',
}
</script>

<template>
    <Head title="Site Transfers" />

    <AppShell>
        <template #header>
            <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                Site Transfers
            </h1>
        </template>

        <div class="px-6 py-5">
            <!-- ── Page header ── -->
            <div class="flex items-center justify-between mb-5">
                <p class="text-sm text-gray-500 dark:text-slate-400">
                    {{ transfers.total.toLocaleString() }}
                    transfer{{ transfers.total !== 1 ? 's' : '' }} total
                </p>
            </div>

            <!-- ── Filter bar ── -->
            <div class="flex items-center gap-3 mb-4">
                <label
                    for="status-filter"
                    class="text-sm text-gray-600 dark:text-slate-400 font-medium"
                >
                    Status:
                </label>
                <select
                    id="status-filter"
                    :value="statusFilter"
                    class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                    @change="applyStatusFilter"
                >
                    <option value="">All</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <!-- ── Table ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="w-full text-sm" aria-label="Site transfers">
                    <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
                        <tr>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                Participant
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                Transfer
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                Reason
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                Status
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                Requested By
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                Requested
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                Effective
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <!-- Empty state -->
                        <tr v-if="transfers.data.length === 0">
                            <td
                                colspan="7"
                                class="px-4 py-10 text-center text-gray-400 dark:text-slate-500"
                            >
                                No transfers found.
                            </td>
                        </tr>

                        <!-- Transfer rows -->
                        <tr
                            v-for="transfer in transfers.data"
                            :key="transfer.id"
                            class="bg-white dark:bg-slate-800 hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors"
                            tabindex="0"
                            :aria-label="`Open profile for ${transfer.participant.name}`"
                            @click="router.visit(`/participants/${transfer.participant.id}`)"
                            @keydown.enter="router.visit(`/participants/${transfer.participant.id}`)"
                        >
                            <!-- Participant: MRN + name -->
                            <td class="px-4 py-3">
                                <div class="font-mono text-xs font-semibold text-gray-500 dark:text-slate-400">
                                    {{ transfer.participant.mrn }}
                                </div>
                                <div class="font-medium text-gray-900 dark:text-slate-100 text-sm">
                                    {{ transfer.participant.name }}
                                </div>
                            </td>

                            <!-- From site + arrow + To site -->
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-slate-300">
                                    <span class="truncate max-w-[100px]">
                                        {{ transfer.from_site?.name ?? '-' }}
                                    </span>
                                    <ArrowRightIcon class="w-3.5 h-3.5 text-gray-400 dark:text-slate-500 shrink-0" aria-hidden="true" />
                                    <span class="truncate max-w-[100px]">
                                        {{ transfer.to_site?.name ?? '-' }}
                                    </span>
                                </div>
                            </td>

                            <!-- Reason -->
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 text-sm">
                                {{ transfer.transfer_reason_label }}
                            </td>

                            <!-- Status badge -->
                            <td class="px-4 py-3">
                                <span
                                    :class="[
                                        'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                        STATUS_COLORS[transfer.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
                                    ]"
                                >
                                    {{ STATUS_LABELS[transfer.status] ?? transfer.status }}
                                </span>
                            </td>

                            <!-- Requested by -->
                            <td class="px-4 py-3 text-gray-500 dark:text-slate-400 text-sm">
                                {{ transfer.requested_by ?? '-' }}
                            </td>

                            <!-- Requested date -->
                            <td class="px-4 py-3 text-gray-500 dark:text-slate-400 text-sm">
                                {{ fmtDate(transfer.requested_at) }}
                            </td>

                            <!-- Effective date -->
                            <td class="px-4 py-3 text-gray-500 dark:text-slate-400 text-sm">
                                {{ fmtDate(transfer.effective_date) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Pagination ── -->
            <div
                v-if="transfers.last_page > 1"
                class="flex items-center justify-between mt-4 text-sm"
            >
                <span class="text-gray-500 dark:text-slate-400">
                    Page {{ transfers.current_page }} of {{ transfers.last_page }}
                </span>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        :disabled="transfers.current_page <= 1"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md border text-xs font-medium transition-colors border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/50 disabled:opacity-40 disabled:cursor-not-allowed"
                        @click="goToPage(transfers.current_page - 1)"
                    >
                        <ChevronLeftIcon class="w-3.5 h-3.5" aria-hidden="true" />
                        Prev
                    </button>
                    <button
                        type="button"
                        :disabled="transfers.current_page >= transfers.last_page"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md border text-xs font-medium transition-colors border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/50 disabled:opacity-40 disabled:cursor-not-allowed"
                        @click="goToPage(transfers.current_page + 1)"
                    >
                        Next
                        <ChevronRightIcon class="w-3.5 h-3.5" aria-hidden="true" />
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
