<script setup lang="ts">
// ─── Billing/Reconciliation ─────────────────────────────────────────────────
// Enrollment reconciliation against CMS files: ingest MMR (Monthly Membership
// Report: who CMS thinks is enrolled) + TRR (Transaction Reply Report:
// CMS's response to enrollment transactions submitted) and surface every
// discrepancy as a worklist item.
//
// Audience: Enrollment + Finance.
//
// Notable rules:
//   - Capitation is paid only for participants CMS shows as enrolled; an
//     unresolved MMR discrepancy is a directly-revenue-impacting bug.
//   - Resolution actions are append-only audit-logged for CMS surveyors.
// ────────────────────────────────────────────────────────────────────────────

import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    ScaleIcon,
    DocumentArrowUpIcon,
    ArrowPathIcon,
    CheckBadgeIcon,
    ExclamationTriangleIcon,
    InformationCircleIcon,
} from '@heroicons/vue/24/outline'

interface MmrFileRow {
    id: number
    label: string
    period_year: number
    period_month: number
    original_filename: string
    status: 'received' | 'parsing' | 'parsed' | 'parse_error'
    received_at: string | null
    parsed_at: string | null
    uploaded_by: string | null
    record_count: number
    discrepancy_count: number
    total_capitation_amount: number
    parse_error_message: string | null
}

interface TrrFileRow {
    id: number
    original_filename: string
    status: 'received' | 'parsing' | 'parsed' | 'parse_error'
    received_at: string | null
    parsed_at: string | null
    uploaded_by: string | null
    record_count: number
    accepted_count: number
    rejected_count: number
    parse_error_message: string | null
}

interface Discrepancy {
    id: number
    discrepancy_type: string
    discrepancy_label: string
    discrepancy_note: string | null
    medicare_id: string
    member_name: string | null
    capitation_amount: number
    adjustment_amount: number
    period: string | null
    participant: { id: number; mrn: string; name: string } | null
}

const props = defineProps<{
    mmrFiles: MmrFileRow[]
    trrFiles: TrrFileRow[]
    openDiscrepancies: Discrepancy[]
    discrepancyLabels: Record<string, string>
}>()

// ── MMR upload form ──────────────────────────────────────────────────────────

const now = new Date()
const mmrForm = ref({
    period_year: now.getFullYear(),
    period_month: now.getMonth() + 1,
    file: null as File | null,
})
const mmrUploading = ref(false)

function onMmrFileChange(e: Event) {
    const input = e.target as HTMLInputElement
    mmrForm.value.file = input.files?.[0] ?? null
}

async function uploadMmr() {
    if (!mmrForm.value.file) { alert('Choose a file first.'); return }
    mmrUploading.value = true
    try {
        const fd = new FormData()
        fd.append('period_year', String(mmrForm.value.period_year))
        fd.append('period_month', String(mmrForm.value.period_month))
        fd.append('file', mmrForm.value.file)
        await axios.post('/billing/reconciliation/mmr', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'MMR upload failed.')
    } finally {
        mmrUploading.value = false
    }
}

// ── TRR upload ───────────────────────────────────────────────────────────────

const trrFile = ref<File | null>(null)
const trrUploading = ref(false)

function onTrrFileChange(e: Event) {
    const input = e.target as HTMLInputElement
    trrFile.value = input.files?.[0] ?? null
}

async function uploadTrr() {
    if (!trrFile.value) { alert('Choose a file first.'); return }
    trrUploading.value = true
    try {
        const fd = new FormData()
        fd.append('file', trrFile.value)
        await axios.post('/billing/reconciliation/trr', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'TRR upload failed.')
    } finally {
        trrUploading.value = false
    }
}

// ── Discrepancy resolution ───────────────────────────────────────────────────

const resolvingId = ref<number | null>(null)
const resolutionNotes = ref('')

function openResolve(d: Discrepancy) {
    resolvingId.value = d.id
    resolutionNotes.value = ''
}

async function submitResolution(action: 'resolved' | 'ignored') {
    if (resolvingId.value === null) return
    try {
        await axios.post(`/billing/reconciliation/discrepancies/${resolvingId.value}/resolve`, {
            action, notes: resolutionNotes.value,
        })
        resolvingId.value = null
        resolutionNotes.value = ''
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to resolve discrepancy.')
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function fmt(d: string | null): string {
    return d ? new Date(d).toLocaleString() : '-'
}

function money(n: number): string {
    return '$' + (n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const STATUS_CLASSES: Record<string, string> = {
    received:    'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    parsing:     'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    parsed:      'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    parse_error: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
}

const discrepancyCountsByType = computed(() => {
    const counts: Record<string, number> = {}
    for (const d of props.openDiscrepancies) {
        counts[d.discrepancy_type] = (counts[d.discrepancy_type] ?? 0) + 1
    }
    return counts
})
</script>

<template>
    <AppShell>
        <Head title="CMS Enrollment Reconciliation" />

        <div class="px-6 py-6 max-w-7xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex items-start gap-3">
                <ScaleIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                <div>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">CMS Enrollment Reconciliation</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Monthly Membership Report (MMR) + Transaction Reply Report (TRR) ingest &amp; discrepancy worklist
                    </p>
                </div>
            </div>

            <!-- Honest-labeling banner -->
            <div class="flex items-start gap-3 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-5 py-4">
                <InformationCircleIcon class="w-5 h-5 shrink-0 mt-0.5 text-amber-700 dark:text-amber-300" />
                <div class="text-sm text-amber-800 dark:text-amber-200">
                    <p class="font-semibold mb-0.5">Manual Ingest</p>
                    <p>
                        Upload MMR and TRR files downloaded from the HPMS portal. The parser expects the documented
                        pipe-delimited format (see memory file). Real CMS HPMS file layouts live behind the portal;
                        when automated retrieval is wired, the adapter will transform CMS-native format into what these
                        parsers consume. Every upload, parse, and discrepancy resolution is logged to the audit trail.
                    </p>
                </div>
            </div>

            <!-- Upload cards -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- MMR -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-5 space-y-3">
                    <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Upload MMR</h2>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Year</label>
                            <input v-model.number="mmrForm.period_year" type="number" min="2000" max="2100"
                                class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Month</label>
                            <input v-model.number="mmrForm.period_month" type="number" min="1" max="12"
                                class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                        </div>
                        <div class="col-span-3">
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">File (.txt)</label>
                            <input type="file" @change="onMmrFileChange" accept=".txt,text/plain"
                                class="w-full text-sm text-slate-700 dark:text-slate-300" />
                        </div>
                    </div>
                    <div class="text-right">
                        <button :disabled="mmrUploading" @click="uploadMmr"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
                            <DocumentArrowUpIcon class="w-4 h-4" />
                            {{ mmrUploading ? 'Uploading...' : 'Upload MMR' }}
                        </button>
                    </div>
                </div>

                <!-- TRR -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-5 space-y-3">
                    <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Upload TRR</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Transaction Reply Report: CMS's per-transaction accept/reject response.
                    </p>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">File (.txt)</label>
                        <input type="file" @change="onTrrFileChange" accept=".txt,text/plain"
                            class="w-full text-sm text-slate-700 dark:text-slate-300" />
                    </div>
                    <div class="text-right">
                        <button :disabled="trrUploading" @click="uploadTrr"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
                            <DocumentArrowUpIcon class="w-4 h-4" />
                            {{ trrUploading ? 'Uploading...' : 'Upload TRR' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Discrepancy summary chips -->
            <div v-if="Object.keys(discrepancyCountsByType).length > 0"
                 class="flex flex-wrap gap-2">
                <span v-for="(count, type) in discrepancyCountsByType" :key="type"
                    class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300">
                    <ExclamationTriangleIcon class="w-3.5 h-3.5" />
                    {{ discrepancyLabels[type] ?? type }}: {{ count }}
                </span>
            </div>

            <!-- Open discrepancies table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Open Discrepancies</h2>
                    <span class="text-xs text-slate-500 dark:text-slate-400 tabular-nums">
                        {{ openDiscrepancies.length }} open
                    </span>
                </div>
                <div v-if="openDiscrepancies.length === 0" class="py-16 text-center text-sm text-slate-400">
                    No open discrepancies. Upload an MMR to begin.
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/30 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left">Discrepancy</th>
                            <th class="px-4 py-3 text-left">MBI</th>
                            <th class="px-4 py-3 text-left">Participant</th>
                            <th class="px-4 py-3 text-left">Period</th>
                            <th class="px-4 py-3 text-right">Capitation</th>
                            <th class="px-4 py-3 text-right">Adjustment</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr v-for="d in openDiscrepancies" :key="d.id">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-red-700 dark:text-red-300">{{ d.discrepancy_label }}</p>
                                <p v-if="d.discrepancy_note" class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ d.discrepancy_note }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">{{ d.medicare_id }}</td>
                            <td class="px-4 py-3 text-xs text-slate-700 dark:text-slate-200">
                                <template v-if="d.participant">
                                    {{ d.participant.name }}
                                    <span class="block text-slate-400 dark:text-slate-500">MRN {{ d.participant.mrn }}</span>
                                </template>
                                <template v-else>
                                    <span class="italic text-slate-400">{{ d.member_name ?? 'unknown' }}</span>
                                </template>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">{{ d.period ?? '-' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ money(d.capitation_amount) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums"
                                :class="d.adjustment_amount !== 0 ? 'text-amber-700 dark:text-amber-300 font-semibold' : 'text-slate-400'">
                                {{ d.adjustment_amount !== 0 ? money(d.adjustment_amount) : '-' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button @click="openResolve(d)"
                                    class="text-xs px-2 py-1 rounded border border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30">
                                    Resolve
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Ingested files tables -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700">
                        <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">MMR Files</h2>
                    </div>
                    <div v-if="mmrFiles.length === 0" class="py-10 text-center text-sm text-slate-400">None</div>
                    <table v-else class="w-full text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-900/30 text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-2 text-left">Period</th>
                                <th class="px-4 py-2 text-left">Records</th>
                                <th class="px-4 py-2 text-left">Discrepancies</th>
                                <th class="px-4 py-2 text-right">Total Cap</th>
                                <th class="px-4 py-2 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <tr v-for="f in mmrFiles" :key="f.id">
                                <td class="px-4 py-2 font-semibold text-slate-700 dark:text-slate-200">{{ f.label }}</td>
                                <td class="px-4 py-2 tabular-nums">{{ f.record_count }}</td>
                                <td class="px-4 py-2 tabular-nums"
                                    :class="f.discrepancy_count > 0 ? 'text-red-600 dark:text-red-400 font-semibold' : ''">
                                    {{ f.discrepancy_count }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ money(f.total_capitation_amount) }}</td>
                                <td class="px-4 py-2">
                                    <span :class="['inline-flex px-2 py-0.5 rounded-full font-medium', STATUS_CLASSES[f.status]]">
                                        {{ f.status }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700">
                        <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">TRR Files</h2>
                    </div>
                    <div v-if="trrFiles.length === 0" class="py-10 text-center text-sm text-slate-400">None</div>
                    <table v-else class="w-full text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-900/30 text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-2 text-left">File</th>
                                <th class="px-4 py-2 text-left">Records</th>
                                <th class="px-4 py-2 text-left">Accepted</th>
                                <th class="px-4 py-2 text-left">Rejected</th>
                                <th class="px-4 py-2 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <tr v-for="f in trrFiles" :key="f.id">
                                <td class="px-4 py-2 truncate text-slate-700 dark:text-slate-200">{{ f.original_filename }}</td>
                                <td class="px-4 py-2 tabular-nums">{{ f.record_count }}</td>
                                <td class="px-4 py-2 tabular-nums text-emerald-700 dark:text-emerald-300">{{ f.accepted_count }}</td>
                                <td class="px-4 py-2 tabular-nums"
                                    :class="f.rejected_count > 0 ? 'text-red-600 dark:text-red-400 font-semibold' : ''">
                                    {{ f.rejected_count }}
                                </td>
                                <td class="px-4 py-2">
                                    <span :class="['inline-flex px-2 py-0.5 rounded-full font-medium', STATUS_CLASSES[f.status]]">
                                        {{ f.status }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Resolve modal -->
            <div v-if="resolvingId !== null"
                class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
                @click.self="resolvingId = null">
                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md">
                    <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                        <h3 class="font-semibold text-slate-900 dark:text-slate-100">Resolve Discrepancy</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            Mark as resolved (you've corrected it locally or with CMS) or ignored (intentional drift).
                        </p>
                    </div>
                    <div class="px-6 py-5 space-y-3">
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Notes (optional)</label>
                        <textarea v-model="resolutionNotes" rows="4"
                            placeholder="Action taken, CMS ticket #, etc."
                            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                    </div>
                    <div class="px-6 py-3 border-t border-slate-200 dark:border-slate-700 flex justify-between gap-2">
                        <button @click="resolvingId = null" class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 text-sm">Cancel</button>
                        <div class="flex gap-2">
                            <button @click="submitResolution('ignored')"
                                class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 text-sm">Mark Ignored</button>
                            <button @click="submitResolution('resolved')"
                                class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700">
                                <CheckBadgeIcon class="w-4 h-4 inline -mt-1" /> Mark Resolved
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
