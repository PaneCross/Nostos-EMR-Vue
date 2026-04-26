<!-- ItAdmin/Integrations.vue -->
<!-- IT Admin integrations health panel. Shows a status card grid for each connector type
     (HL7 ADT, Lab Results) and a filterable log table of recent integration events.
     Failed events can be retried directly from the log table. -->

<script setup lang="ts">
// ─── ItAdmin/Integrations ───────────────────────────────────────────────────
// Health panel for inbound/outbound integrations: HL7 (Health Level 7)
// ADT (Admission/Discharge/Transfer) feeds, lab-results feeds, eligibility,
// transport, etc. Status cards + filterable event log; failed events can
// be retried inline.
//
// Audience: IT Admin only.
//
// Notable rules:
//   - Adapter pattern: each connector type has a vendor adapter. Most
//     non-default vendors are PAYWALL-DEFERRED (see paywall report).
//   - Retry is idempotent — duplicate ADT messages are de-duped server-side
//     by message control ID.
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    SignalIcon,
    ArrowPathIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    ClockIcon,
    XCircleIcon,
} from '@heroicons/vue/24/outline'

interface ConnectorSummary {
    total: number
    processed: number
    failed: number
    last_received_at: string | null
}

interface LogEntry {
    id: number
    connector_type: string
    direction: string
    status: string
    retry_count: number
    created_at: string
    error_message: string | null
}

interface EligibilityConfig {
    driver: string
    driver_label: string
    is_real_vendor: boolean
    recent_checks_30d: number
    config_note: string
}

interface Props {
    summary: Record<string, ConnectorSummary>
    recentLog: LogEntry[]
    connectorTypes: string[]
    eligibility?: EligibilityConfig
}

const props = defineProps<Props>()

const log = ref<LogEntry[]>(props.recentLog)
const retryingId = ref<number | null>(null)
const filterType = ref('all')
const filterStatus = ref('all')
const loadingLog = ref(false)

const CONNECTOR_LABELS: Record<string, string> = {
    hl7_adt: 'HL7 ADT',
    lab_result: 'Lab Results',
}

const statusColor = (status: string): string => {
    if (status === 'processed') return 'text-green-600 dark:text-green-400'
    if (status === 'failed') return 'text-red-600 dark:text-red-400'
    if (status === 'retried') return 'text-blue-600 dark:text-blue-400'
    return 'text-yellow-600 dark:text-yellow-400'
}

const statusBg = (status: string): string => {
    if (status === 'processed') return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300'
    if (status === 'failed') return 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300'
    if (status === 'retried') return 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300'
    return 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300'
}

const connectorEntries = computed(() =>
    Object.entries(props.summary).map(([key, val]) => ({ key, label: CONNECTOR_LABELS[key] ?? key, ...val }))
)

const filteredLog = computed(() =>
    log.value.filter(e =>
        (filterType.value === 'all' || e.connector_type === filterType.value) &&
        (filterStatus.value === 'all' || e.status === filterStatus.value)
    )
)

const loadLog = async () => {
    loadingLog.value = true
    try {
        const params = new URLSearchParams()
        if (filterType.value !== 'all') params.set('connector_type', filterType.value)
        if (filterStatus.value !== 'all') params.set('status', filterStatus.value)
        const res = await axios.get(`/it-admin/integrations/log?${params.toString()}`)
        log.value = res.data.log ?? []
    } catch {
        // silently handle
    } finally {
        loadingLog.value = false
    }
}

const retry = async (entry: LogEntry) => {
    retryingId.value = entry.id
    try {
        await axios.post(`/it-admin/integrations/${entry.id}/retry`)
        log.value = log.value.map(e => e.id === entry.id ? { ...e, status: 'retried' } : e)
    } catch {
        // silently handle
    } finally {
        retryingId.value = null
    }
}

const applyFilter = () => loadLog()

const formatDate = (iso: string | null) => iso
    ? new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })
    : '-'

onMounted(() => {}) // log already loaded from props
</script>

<template>
    <AppShell>
        <Head title="IT Admin: Integrations" />

        <div class="max-w-6xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-center gap-3 mb-6">
                <SignalIcon class="w-7 h-7 text-blue-600 dark:text-blue-400" />
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Integrations</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400">Inbound HL7 and lab result connector health</p>
                </div>
            </div>

            <!-- Phase Q4 — Eligibility driver card -->
            <div v-if="eligibility" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 shadow-sm mb-6" data-testid="eligibility-driver-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold text-gray-900 dark:text-slate-100">X12 270/271 Eligibility (Phase P5)</span>
                    <span :class="[
                        'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                        eligibility.is_real_vendor
                            ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300'
                            : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'
                    ]">
                        {{ eligibility.is_real_vendor ? 'Vendor active' : 'Null gateway (no real verification)' }}
                    </span>
                </div>
                <div class="text-sm text-gray-700 dark:text-slate-300">
                    <div class="flex justify-between">
                        <span>Active driver</span>
                        <span class="font-medium">{{ eligibility.driver_label }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Checks (last 30d)</span>
                        <span class="font-medium">{{ eligibility.recent_checks_30d }}</span>
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-slate-400 italic">{{ eligibility.config_note }}</p>
            </div>

            <!-- Connector cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
                <div
                    v-for="c in connectorEntries"
                    :key="c.key"
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 shadow-sm"
                >
                    <div class="flex items-center justify-between mb-3">
                        <span class="font-semibold text-gray-900 dark:text-slate-100">{{ c.label }}</span>
                        <span v-if="c.failed > 0"
                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300">
                            <XCircleIcon class="w-3.5 h-3.5" />
                            {{ c.failed }} failed
                        </span>
                        <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300">
                            <CheckCircleIcon class="w-3.5 h-3.5" />
                            Healthy
                        </span>
                    </div>
                    <div class="text-sm text-gray-600 dark:text-slate-400 space-y-1">
                        <div class="flex justify-between">
                            <span>Total received</span>
                            <span class="font-medium text-gray-900 dark:text-slate-100">{{ c.total }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Processed</span>
                            <span class="font-medium text-green-600 dark:text-green-400">{{ c.processed }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Last received</span>
                            <span class="font-medium">{{ formatDate(c.last_received_at) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Log filter -->
            <div class="flex flex-wrap gap-3 mb-4">
                <select name="filterType"
                    v-model="filterType"
                    @change="applyFilter"
                    class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm text-gray-700 dark:text-slate-300"
                    aria-label="Filter by connector type"
                >
                    <option value="all">All Connectors</option>
                    <option v-for="t in props.connectorTypes" :key="t" :value="t">{{ CONNECTOR_LABELS[t] ?? t }}</option>
                </select>
                <select name="filterStatus"
                    v-model="filterStatus"
                    @change="applyFilter"
                    class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm text-gray-700 dark:text-slate-300"
                    aria-label="Filter by status"
                >
                    <option value="all">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="processed">Processed</option>
                    <option value="failed">Failed</option>
                    <option value="retried">Retried</option>
                </select>
            </div>

            <!-- Log table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <div v-if="loadingLog" class="py-12 text-center text-gray-500 dark:text-slate-400 text-sm">Loading...</div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Connector</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Status</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Retries</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Received</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr v-for="entry in filteredLog" :key="entry.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                            <td class="px-4 py-3 text-gray-900 dark:text-slate-100 font-medium">
                                {{ CONNECTOR_LABELS[entry.connector_type] ?? entry.connector_type }}
                            </td>
                            <td class="px-4 py-3">
                                <span :class="statusBg(entry.status)"
                                    class="inline-block px-2 py-0.5 rounded-full text-xs font-medium capitalize">
                                    {{ entry.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ entry.retry_count }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 whitespace-nowrap">{{ formatDate(entry.created_at) }}</td>
                            <td class="px-4 py-3">
                                <button
                                    v-if="entry.status === 'failed'"
                                    @click="retry(entry)"
                                    :disabled="retryingId === entry.id"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 disabled:opacity-50 transition-colors"
                                    aria-label="Retry this integration event"
                                >
                                    <ArrowPathIcon class="w-3.5 h-3.5" />
                                    {{ retryingId === entry.id ? 'Retrying...' : 'Retry' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="filteredLog.length === 0">
                            <td colspan="5" class="py-12 text-center text-gray-500 dark:text-slate-400">No log entries found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
