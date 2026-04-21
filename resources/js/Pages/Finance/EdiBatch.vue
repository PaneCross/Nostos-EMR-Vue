<script setup lang="ts">
// ─── Finance/EdiBatch.vue ─────────────────────────────────────────────────────
// EDI batch list. Data loaded client-side via axios on mount.
// Supports generating new batches via POST.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { ArrowPathIcon, DocumentArrowUpIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'

// ── No server-side props (all data loaded client-side via axios) ───────────────

// ── Types ──────────────────────────────────────────────────────────────────────

interface EdiBatch {
    id: number
    batch_id: string | null
    created_at: string | null
    record_count: number | null
    status: string | null
    file_name: string | null
}

// ── State ──────────────────────────────────────────────────────────────────────

const batches = ref<EdiBatch[]>([])
const loading = ref(true)
const generating = ref(false)
const error = ref<string | null>(null)

// ── Fetch ──────────────────────────────────────────────────────────────────────

function loadBatches() {
    loading.value = true
    error.value = null
    axios
        .get('/billing/batches')
        .then((res) => {
            batches.value = res.data?.data ?? res.data ?? []
        })
        .catch(() => {
            error.value = 'Failed to load EDI batches.'
        })
        .finally(() => {
            loading.value = false
        })
}

onMounted(loadBatches)

// ── Generate new batch ─────────────────────────────────────────────────────────

function generateBatch() {
    generating.value = true
    error.value = null
    axios
        .post('/billing/batches')
        .then(() => {
            loadBatches()
        })
        .catch(() => {
            error.value = 'Failed to generate batch.'
        })
        .finally(() => {
            generating.value = false
        })
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val)
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function statusClass(status: string | null): string {
    switch (status) {
        case 'pending':
            return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'
        case 'submitted':
            return 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300'
        case 'acknowledged':
            return 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300'
        case 'rejected':
            return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300'
        default:
            return 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'
    }
}
</script>

<template>
    <Head title="EDI Batches" />

    <AppShell>
        <template #header>
            <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">EDI Batches</h1>
        </template>

        <div class="px-6 py-5 space-y-4">

            <!-- ── Toolbar ── -->
            <div class="flex items-center justify-end">
                <button
                    type="button"
                    :disabled="generating"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    @click="generateBatch"
                >
                    <ArrowPathIcon
                        :class="['w-4 h-4', generating ? 'animate-spin' : '']"
                        aria-hidden="true"
                    />
                    {{ generating ? 'Generating...' : 'Generate Batch' }}
                </button>
            </div>

            <!-- ── Error banner ── -->
            <div
                v-if="error"
                class="px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-sm text-red-700 dark:text-red-300"
            >
                {{ error }}
            </div>

            <!-- ── Table ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
                <!-- Loading -->
                <div v-if="loading" class="py-12 text-center text-sm text-gray-400 dark:text-slate-500">
                    Loading EDI batches...
                </div>

                <!-- Table -->
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-700/50">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Batch ID</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Created Date</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Record Count</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Status</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">File Name</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            <!-- Empty state -->
                            <tr v-if="batches.length === 0">
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-400 dark:text-slate-500">
                                    No EDI batches found.
                                </td>
                            </tr>

                            <!-- Data rows -->
                            <tr
                                v-for="batch in batches"
                                :key="batch.id"
                                class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors"
                            >
                                <td class="px-4 py-3 font-mono text-xs text-gray-700 dark:text-slate-300">
                                    {{ batch.batch_id ?? String(batch.id) }}
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400 whitespace-nowrap">
                                    {{ fmtDate(batch.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-800 dark:text-slate-200 tabular-nums">
                                    {{ batch.record_count?.toLocaleString() ?? '-' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="batch.status"
                                        :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize', statusClass(batch.status)]"
                                    >
                                        {{ batch.status }}
                                    </span>
                                    <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="batch.file_name"
                                        class="inline-flex items-center gap-1 text-gray-600 dark:text-slate-400 text-xs"
                                    >
                                        <DocumentArrowUpIcon class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
                                        {{ batch.file_name }}
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
