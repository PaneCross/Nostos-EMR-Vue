<script setup lang="ts">
// ─── Clinical/Orders ────────────────────────────────────────────────────────
// CPOE (Computerized Provider Order Entry) worklist: every clinical order
// (labs, imaging, referrals, durable medical equipment, etc.) across the
// enrolled roster. KPIs for pending / active / STAT.
//
// Audience: Prescribers, nursing, ancillary departments fulfilling orders.
//
// Notable rules:
//   - Filters use Inertia router.get with preserveState so URLs are
//     bookmarkable / shareable while staying SPA-fast.
//   - STAT orders raise a critical alert to the assigned department.
//   - Append-only audit trail: status changes are timestamped + signed.
// ────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import {
    ClipboardDocumentCheckIcon,
    BoltIcon,
    ClockIcon,
    CheckCircleIcon,
    FunnelIcon,
} from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface ClinicalOrder {
    id: number
    order_type: string
    description: string | null
    status: 'pending' | 'active' | 'completed' | 'cancelled' | 'acknowledged'
    priority: 'routine' | 'urgent' | 'stat' | null
    created_at: string
    ordered_by: { id: number; first_name: string; last_name: string } | null
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null
    target_department: string | null
}

interface OrderKPIs {
    total_pending: number
    total_active: number
    stat_orders: number
}

const props = defineProps<{
    orders: { data: ClinicalOrder[] }
    kpis?: OrderKPIs
    filters?: { status?: string; priority?: string }
}>()

// ── Filter state ───────────────────────────────────────────────────────────────

const statusFilter = ref(props.filters?.status ?? '')
const priorityFilter = ref(props.filters?.priority ?? '')

function applyFilters() {
    router.get(
        '/clinical/orders',
        {
            status: statusFilter.value,
            priority: priorityFilter.value,
        },
        { preserveState: true, replace: true },
    )
}

function onStatusChange(e: Event) {
    statusFilter.value = (e.target as HTMLSelectElement).value
    applyFilters()
}

function onPriorityChange(e: Event) {
    priorityFilter.value = (e.target as HTMLSelectElement).value
    applyFilters()
}

function clearFilters() {
    statusFilter.value = ''
    priorityFilter.value = ''
    router.get('/clinical/orders', {}, { preserveState: false })
}

const hasActiveFilters = computed(() => statusFilter.value || priorityFilter.value)

// ── Helpers ────────────────────────────────────────────────────────────────────

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val)
    return isNaN(d.getTime())
        ? val
        : d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function truncate(str: string | null | undefined, len = 50): string {
    if (!str) return '-'
    return str.length > len ? str.slice(0, len) + '...' : str
}

function fmtDept(dept: string | null): string {
    if (!dept) return '-'
    return dept
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase())
}

// ── Badge maps ─────────────────────────────────────────────────────────────────

const PRIORITY_BADGE: Record<string, string> = {
    stat: 'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
    urgent: 'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    routine: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
}

const STATUS_BADGE: Record<string, string> = {
    pending: 'bg-yellow-100 dark:bg-yellow-900/50 text-yellow-800 dark:text-yellow-300',
    active: 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300',
    completed: 'bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300',
    cancelled: 'bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500',
    acknowledged: 'bg-indigo-100 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300',
}
</script>

<template>
    <Head title="Clinical Orders" />

    <AppShell>
        <template #header>
            <div class="flex items-center gap-2">
                <ClipboardDocumentCheckIcon class="w-5 h-5 text-gray-500 dark:text-slate-400" aria-hidden="true" />
                <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">Clinical Orders</h1>
            </div>
        </template>

        <div class="px-6 py-5">
            <!-- ── KPI cards ── -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
                <!-- Pending -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 shadow-sm flex items-start gap-3">
                    <div class="p-2 bg-yellow-50 dark:bg-yellow-900/40 rounded-lg mt-0.5 flex-shrink-0">
                        <ClockIcon class="w-4 h-4 text-yellow-600 dark:text-yellow-400" aria-hidden="true" />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">Pending</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-slate-100 mt-0.5">
                            {{ (kpis?.total_pending ?? 0).toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- Active -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 shadow-sm flex items-start gap-3">
                    <div class="p-2 bg-blue-50 dark:bg-blue-900/40 rounded-lg mt-0.5 flex-shrink-0">
                        <CheckCircleIcon class="w-4 h-4 text-blue-600 dark:text-blue-400" aria-hidden="true" />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">Active</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-slate-100 mt-0.5">
                            {{ (kpis?.total_active ?? 0).toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- STAT orders -->
                <div
                    :class="[
                        'bg-white dark:bg-slate-800 rounded-xl border px-4 py-3 shadow-sm flex items-start gap-3',
                        (kpis?.stat_orders ?? 0) > 0
                            ? 'border-red-300 dark:border-red-700'
                            : 'border-gray-200 dark:border-slate-700',
                    ]"
                >
                    <div
                        :class="[
                            'p-2 rounded-lg mt-0.5 flex-shrink-0',
                            (kpis?.stat_orders ?? 0) > 0
                                ? 'bg-red-50 dark:bg-red-900/40'
                                : 'bg-gray-50 dark:bg-slate-700/50',
                        ]"
                    >
                        <BoltIcon
                            :class="[
                                'w-4 h-4',
                                (kpis?.stat_orders ?? 0) > 0
                                    ? 'text-red-600 dark:text-red-400'
                                    : 'text-gray-400 dark:text-slate-500',
                            ]"
                            aria-hidden="true"
                        />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">STAT Orders</p>
                        <p
                            :class="[
                                'text-2xl font-bold mt-0.5',
                                (kpis?.stat_orders ?? 0) > 0
                                    ? 'text-red-600 dark:text-red-400'
                                    : 'text-gray-900 dark:text-slate-100',
                            ]"
                        >
                            {{ (kpis?.stat_orders ?? 0).toLocaleString() }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── Filter bar ── -->
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <FunnelIcon class="w-4 h-4 text-gray-400 dark:text-slate-500 flex-shrink-0" aria-hidden="true" />

                <!-- Status filter -->
                <select name="select"
                    :value="statusFilter"
                    aria-label="Filter by status"
                    class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                    @change="onStatusChange"
                >
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="active">Active</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="acknowledged">Acknowledged</option>
                </select>

                <!-- Priority filter -->
                <select name="select"
                    :value="priorityFilter"
                    aria-label="Filter by priority"
                    class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                    @change="onPriorityChange"
                >
                    <option value="">All Priorities</option>
                    <option value="routine">Routine</option>
                    <option value="urgent">Urgent</option>
                    <option value="stat">STAT</option>
                </select>

                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 underline"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
            </div>

            <!-- ── Table ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="w-full text-sm" aria-label="Clinical orders worklist">
                    <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
                        <tr>
                            <th
                                v-for="col in ['Participant', 'Order Type', 'Description', 'Priority', 'Status', 'Department', 'Ordered By', 'Date']"
                                :key="col"
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap"
                            >
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <!-- Empty state -->
                        <tr v-if="orders.data.length === 0">
                            <td
                                colspan="8"
                                class="px-4 py-10 text-center text-gray-400 dark:text-slate-500"
                            >
                                No orders found for the selected filters.
                            </td>
                        </tr>

                        <!-- Order rows -->
                        <tr
                            v-for="order in orders.data"
                            :key="order.id"
                            class="hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors bg-white dark:bg-slate-800"
                            tabindex="0"
                            :aria-label="order.participant
                                ? `Open profile for ${order.participant.last_name}, ${order.participant.first_name}`
                                : `Order ${order.id}`"
                            @click="order.participant && router.visit(`/participants/${order.participant.id}?tab=orders`)"
                            @keydown.enter="order.participant && router.visit(`/participants/${order.participant.id}?tab=orders`)"
                        >
                            <!-- Participant -->
                            <td class="px-4 py-3">
                                <template v-if="order.participant">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">
                                        {{ order.participant.last_name }}, {{ order.participant.first_name }}
                                    </div>
                                    <div class="text-xs font-mono text-gray-400 dark:text-slate-500">
                                        {{ order.participant.mrn }}
                                    </div>
                                </template>
                                <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                            </td>

                            <!-- Order type -->
                            <td class="px-4 py-3 text-gray-700 dark:text-slate-300 whitespace-nowrap">
                                {{ order.order_type }}
                            </td>

                            <!-- Description (truncated) -->
                            <td
                                class="px-4 py-3 text-gray-600 dark:text-slate-400 max-w-[200px]"
                                :title="order.description ?? undefined"
                            >
                                {{ truncate(order.description) }}
                            </td>

                            <!-- Priority badge -->
                            <td class="px-4 py-3">
                                <span
                                    v-if="order.priority"
                                    :class="[
                                        'inline-flex px-2 py-0.5 rounded-full text-xs font-semibold uppercase',
                                        PRIORITY_BADGE[order.priority] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
                                    ]"
                                >
                                    {{ order.priority }}
                                </span>
                                <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                            </td>

                            <!-- Status badge -->
                            <td class="px-4 py-3">
                                <span
                                    :class="[
                                        'inline-flex px-2 py-0.5 rounded-full text-xs font-medium capitalize',
                                        STATUS_BADGE[order.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
                                    ]"
                                >
                                    {{ order.status }}
                                </span>
                            </td>

                            <!-- Department -->
                            <td class="px-4 py-3 text-gray-500 dark:text-slate-400 text-xs whitespace-nowrap">
                                {{ fmtDept(order.target_department) }}
                            </td>

                            <!-- Ordered by -->
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 whitespace-nowrap">
                                <template v-if="order.ordered_by">
                                    {{ order.ordered_by.last_name }}, {{ order.ordered_by.first_name }}
                                </template>
                                <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                            </td>

                            <!-- Date -->
                            <td class="px-4 py-3 text-gray-500 dark:text-slate-400 text-xs whitespace-nowrap">
                                {{ fmtDate(order.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Row count ── -->
            <p class="mt-3 text-xs text-gray-400 dark:text-slate-500">
                {{ orders.data.length }} order{{ orders.data.length !== 1 ? 's' : '' }} shown
            </p>
        </div>
    </AppShell>
</template>
