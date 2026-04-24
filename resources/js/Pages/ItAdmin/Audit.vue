<!-- ItAdmin/Audit.vue -->
<!-- HIPAA audit log viewer for IT Admins. Lazy-loads audit entries from the server with
     filter controls for action type, resource type, and date range. Supports CSV export
     and pagination for the full append-only shared_audit_logs table. -->

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    DocumentMagnifyingGlassIcon,
    ArrowDownTrayIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
} from '@heroicons/vue/24/outline'

interface AuditEntry {
    id: number
    action: string
    resource_type: string | null
    resource_id: number | null
    user_name: string | null
    ip_address: string | null
    created_at: string
}

interface Props {
    initialCount: number
}

const props = defineProps<Props>()

const entries = ref<AuditEntry[]>([])
const loading = ref(false)
const totalCount = ref(props.initialCount)
const page = ref(1)
const perPage = 25

const filterAction = ref('')
const filterResType = ref('')
const filterDateFrom = ref('')
const filterDateTo = ref('')

const totalPages = ref(Math.ceil(props.initialCount / perPage))

const load = async () => {
    loading.value = true
    try {
        const params = new URLSearchParams()
        params.set('page', String(page.value))
        if (filterAction.value) params.set('action', filterAction.value)
        if (filterResType.value) params.set('resource_type', filterResType.value)
        if (filterDateFrom.value) params.set('date_from', filterDateFrom.value)
        if (filterDateTo.value) params.set('date_to', filterDateTo.value)

        const res = await axios.get(`/it-admin/audit/log?${params.toString()}`)
        entries.value = res.data.entries ?? []
        totalCount.value = res.data.total ?? 0
        totalPages.value = Math.ceil(totalCount.value / perPage)
    } catch {
        // silently handle
    } finally {
        loading.value = false
    }
}

const applyFilters = () => {
    page.value = 1
    load()
}

const goPage = (p: number) => {
    page.value = p
    load()
}

const exportCsv = () => {
    window.location.href = '/it-admin/audit/export'
}

const formatDate = (iso: string) =>
    new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })

onMounted(() => load())

// Phase O5 — detail modal
const detailOpen = ref(false)
const detailLoading = ref(false)
const detail = ref<any | null>(null)

async function showDetail(entry: AuditEntry) {
    detailOpen.value = true
    detailLoading.value = true
    detail.value = null
    try {
        const r = await axios.get(`/it-admin/audit/log/${entry.id}`)
        detail.value = r.data.log ?? null
    } finally { detailLoading.value = false }
}
function closeDetail() { detailOpen.value = false; detail.value = null }

function diffRows(oldVals: any, newVals: any): Array<{ key: string; old: any; new: any; changed: boolean }> {
    const a = typeof oldVals === 'string' ? JSON.parse(oldVals || '{}') : (oldVals ?? {})
    const b = typeof newVals === 'string' ? JSON.parse(newVals || '{}') : (newVals ?? {})
    const keys = Array.from(new Set([...Object.keys(a ?? {}), ...Object.keys(b ?? {})])).sort()
    return keys.map(k => ({
        key: k,
        old: a?.[k] ?? null,
        new: b?.[k] ?? null,
        changed: JSON.stringify(a?.[k] ?? null) !== JSON.stringify(b?.[k] ?? null),
    }))
}
</script>

<template>
    <AppShell>
        <Head title="IT Admin: Audit Log" />

        <div class="max-w-6xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <DocumentMagnifyingGlassIcon class="w-7 h-7 text-blue-600 dark:text-blue-400" />
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">HIPAA Audit Log</h1>
                        <p class="text-sm text-gray-500 dark:text-slate-400">
                            {{ totalCount.toLocaleString() }} total entries - 6-year retention
                        </p>
                    </div>
                </div>
                <button
                    @click="exportCsv"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 text-sm transition-colors"
                    aria-label="Export audit log as CSV"
                >
                    <ArrowDownTrayIcon class="w-4 h-4" />
                    Export CSV
                </button>
            </div>

            <!-- Filter bar -->
            <div class="flex flex-wrap gap-3 mb-5 p-4 bg-gray-50 dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1" for="filter-action">Action</label>
                    <input
                        id="filter-action"
                        v-model="filterAction"
                        type="text"
                        placeholder="e.g. participant.view"
                        class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm w-48"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1" for="filter-res">Resource Type</label>
                    <input
                        id="filter-res"
                        v-model="filterResType"
                        type="text"
                        placeholder="e.g. Participant"
                        class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm w-40"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1" for="filter-from">From</label>
                    <input
                        id="filter-from"
                        v-model="filterDateFrom"
                        type="date"
                        class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1" for="filter-to">To</label>
                    <input
                        id="filter-to"
                        v-model="filterDateTo"
                        type="date"
                        class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"
                    />
                </div>
                <div class="flex items-end">
                    <button
                        @click="applyFilters"
                        class="px-4 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition-colors"
                    >
                        Apply
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <div v-if="loading" class="py-16 text-center text-gray-500 dark:text-slate-400 text-sm">Loading...</div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Action</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Resource</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">User</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">IP Address</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr
                            v-for="entry in entries"
                            :key="entry.id"
                            class="hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer"
                            data-testid="audit-row"
                            @click="showDetail(entry)"
                            @keydown.enter="showDetail(entry)"
                            tabindex="0"
                        >
                            <td class="px-4 py-3 font-mono text-xs text-gray-800 dark:text-slate-200">{{ entry.action }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                <span v-if="entry.resource_type">{{ entry.resource_type }}<span v-if="entry.resource_id"> #{{ entry.resource_id }}</span></span>
                                <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ entry.user_name ?? '-' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-500 dark:text-slate-400">{{ entry.ip_address ?? '-' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-slate-400">{{ formatDate(entry.created_at) }}</td>
                        </tr>
                        <tr v-if="entries.length === 0">
                            <td colspan="5" class="py-12 text-center text-gray-500 dark:text-slate-400">No entries found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="flex items-center justify-between mt-4">
                <p class="text-sm text-gray-500 dark:text-slate-400">
                    Page {{ page }} of {{ totalPages }}
                </p>
                <div class="flex gap-2">
                    <button
                        @click="goPage(page - 1)"
                        :disabled="page <= 1"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-sm text-gray-700 dark:text-slate-300 disabled:opacity-40 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                        aria-label="Previous page"
                    >
                        <ChevronLeftIcon class="w-4 h-4" />
                        Previous
                    </button>
                    <button
                        @click="goPage(page + 1)"
                        :disabled="page >= totalPages"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-sm text-gray-700 dark:text-slate-300 disabled:opacity-40 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                        aria-label="Next page"
                    >
                        Next
                        <ChevronRightIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Phase O5 — audit detail modal -->
        <Teleport to="body">
            <div
                v-if="detailOpen"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                data-testid="audit-detail-modal"
                @click.self="closeDetail"
                @keydown.esc="closeDetail"
                tabindex="0"
            >
                <div class="max-w-3xl w-full max-h-[90vh] overflow-y-auto rounded-xl bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 shadow-xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Audit entry detail</h3>
                        <button class="text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200" @click="closeDetail">✕</button>
                    </div>
                    <div class="p-6 space-y-4">
                        <p v-if="detailLoading" class="text-sm text-gray-500 dark:text-slate-400">Loading…</p>
                        <template v-else-if="detail">
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <dt class="text-gray-500 dark:text-slate-400">Action</dt>
                                <dd class="font-mono text-gray-900 dark:text-slate-100">{{ detail.action }}</dd>
                                <dt class="text-gray-500 dark:text-slate-400">Resource</dt>
                                <dd class="text-gray-900 dark:text-slate-100">{{ detail.resource_type ?? '—' }} #{{ detail.resource_id ?? '—' }}</dd>
                                <dt class="text-gray-500 dark:text-slate-400">User</dt>
                                <dd class="text-gray-900 dark:text-slate-100">
                                    {{ detail.user ? `${detail.user.first_name} ${detail.user.last_name}` : '—' }}
                                    <span v-if="detail.user?.department" class="text-xs text-gray-500 dark:text-slate-400"> · {{ detail.user.department }}</span>
                                </dd>
                                <dt class="text-gray-500 dark:text-slate-400">IP / UA</dt>
                                <dd class="font-mono text-xs text-gray-700 dark:text-slate-300 truncate">{{ detail.ip_address ?? '—' }} · {{ detail.user_agent ?? '—' }}</dd>
                                <dt class="text-gray-500 dark:text-slate-400">When</dt>
                                <dd class="text-gray-900 dark:text-slate-100">{{ formatDate(detail.created_at) }}</dd>
                            </dl>
                            <div v-if="detail.description" class="rounded bg-gray-50 dark:bg-slate-900 p-3 text-sm text-gray-800 dark:text-slate-200">
                                {{ detail.description }}
                            </div>
                            <div v-if="detail.old_values || detail.new_values">
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-1">Before / after</h4>
                                <table class="min-w-full text-xs">
                                    <thead>
                                        <tr class="text-left text-gray-500 dark:text-slate-400">
                                            <th class="py-1 pr-4">Key</th>
                                            <th class="py-1 pr-4">Old</th>
                                            <th class="py-1">New</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                                        <tr
                                            v-for="row in diffRows(detail.old_values, detail.new_values)"
                                            :key="row.key"
                                            :class="row.changed ? 'bg-amber-50 dark:bg-amber-950/20' : ''"
                                        >
                                            <td class="py-1 pr-4 font-mono text-gray-700 dark:text-slate-300">{{ row.key }}</td>
                                            <td class="py-1 pr-4 font-mono text-gray-600 dark:text-slate-400">{{ row.old ?? '—' }}</td>
                                            <td class="py-1 font-mono text-gray-900 dark:text-slate-100">{{ row.new ?? '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppShell>
</template>
