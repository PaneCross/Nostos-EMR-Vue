<template>
    <AppShell>
        <Head title="Remittance Advice" />

        <div class="p-6 space-y-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Remittance Advice</h1>

            <!-- Filter bar -->
            <div class="flex items-center gap-3">
                <div class="relative max-w-sm flex-1">
                    <MagnifyingGlassIcon
                        class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"
                    />
                    <input
                        v-model="payerSearch"
                        type="text"
                        placeholder="Search by payer..."
                        class="w-full pl-9 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        @input="applyFilter"
                    />
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                <table
                    v-if="remittances.data.length > 0"
                    class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                >
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Payer
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Check #
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Payment Date
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Total Paid
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Adjustments
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Lines
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700"
                    >
                        <tr
                            v-for="row in remittances.data"
                            :key="row.id"
                            class="hover:bg-gray-50 dark:hover:bg-gray-700"
                        >
                            <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-white">
                                {{ row.payer_name ?? '-' }}
                            </td>
                            <td
                                class="px-6 py-4 text-sm font-mono text-gray-700 dark:text-gray-300"
                            >
                                {{ row.check_number ?? '-' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                {{ row.payment_date ?? '-' }}
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-white">
                                ${{ row.total_paid.toLocaleString() }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                ${{ row.total_adjustment.toLocaleString() }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                {{ row.line_count }}
                            </td>
                            <td class="px-6 py-4">
                                <span
                                    :class="statusClass(row.status)"
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize"
                                >
                                    {{ row.status }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div
                    v-else
                    class="flex flex-col items-center justify-center py-16 text-gray-500 dark:text-gray-400"
                >
                    <BanknotesIcon class="w-10 h-10 mb-3 text-gray-300 dark:text-gray-600" />
                    <p class="text-sm">No remittance records found.</p>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="remittances.last_page > 1" class="flex items-center justify-between">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Page {{ remittances.current_page }} of {{ remittances.last_page }}
                </p>
                <div class="flex gap-2">
                    <button
                        :disabled="remittances.current_page <= 1"
                        class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed"
                        @click="changePage(remittances.current_page - 1)"
                    >
                        <ChevronLeftIcon class="w-4 h-4" />
                    </button>
                    <button
                        :disabled="remittances.current_page >= remittances.last_page"
                        class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed"
                        @click="changePage(remittances.current_page + 1)"
                    >
                        <ChevronRightIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    MagnifyingGlassIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    BanknotesIcon,
} from '@heroicons/vue/24/outline'

interface RemittanceRow {
    id: number
    payer_name: string | null
    check_number: string | null
    payment_date: string | null
    total_paid: number
    total_adjustment: number
    status: string
    line_count: number
}

interface Paginator {
    data: RemittanceRow[]
    current_page: number
    last_page: number
    total: number
}

const props = defineProps<{
    remittances: Paginator
    filters: any
}>()

const payerSearch = ref(props.filters?.payer ?? '')

let searchTimer: ReturnType<typeof setTimeout> | null = null

function applyFilter() {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
        router.get(
            '/billing/remittance',
            { payer: payerSearch.value || undefined },
            { preserveState: true },
        )
    }, 300)
}

function changePage(page: number) {
    router.get(
        '/billing/remittance',
        { payer: payerSearch.value || undefined, page },
        { preserveState: true },
    )
}

function statusClass(status: string): string {
    if (status === 'posted')
        return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
    if (status === 'pending')
        return 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'
    if (status === 'rejected') return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
    return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
}
</script>
