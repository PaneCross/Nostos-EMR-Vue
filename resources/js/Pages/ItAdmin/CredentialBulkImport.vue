<script setup lang="ts">
// ─── ItAdmin / Credentials Bulk Import ───────────────────────────────────────
// Upload a CSV mapping email + credential_code (or free-form type+title) +
// dates, and the system creates StaffCredential rows in bulk. Returns a
// per-row outcome report so the user can fix mistakes and re-upload.
//
// Useful at go-live when migrating staff credential data from a spreadsheet.
// ─────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    DocumentArrowUpIcon, CheckCircleIcon, ExclamationTriangleIcon,
    InformationCircleIcon, ArrowDownTrayIcon, ClipboardDocumentListIcon,
} from '@heroicons/vue/24/outline'

interface RowReport {
    row: number
    email: string
    outcome: 'created' | 'skipped' | 'error'
    reason?: string
    title?: string
    credential_id?: number
}
interface Report {
    created: number
    skipped: number
    errors: string[]
    rows: RowReport[]
}

const file = ref<File | null>(null)
const uploading = ref(false)
const report = ref<Report | null>(null)
const error = ref<string | null>(null)

function onPick(e: Event) {
    const input = e.target as HTMLInputElement
    file.value = input.files?.[0] ?? null
    report.value = null
    error.value = null
}

async function upload() {
    if (!file.value) return
    uploading.value = true
    error.value = null
    report.value = null
    try {
        const fd = new FormData()
        fd.append('csv', file.value)
        const { data } = await axios.post('/it-admin/credentials/bulk-import', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        report.value = data
    } catch (e: any) {
        error.value = e?.response?.data?.error ?? e?.response?.data?.message ?? 'Upload failed.'
    } finally {
        uploading.value = false
    }
}

function downloadTemplate() {
    const headers = ['email','credential_code','credential_type','title','license_state','license_number','issued_at','expires_at','verification_source','dot_medical_card_expires_at','mvr_check_date','vehicle_class_endorsements','notes']
    const example = [
        'jane.doe@example.com,rn_license,license,RN License,CA,RN12345,2024-06-01,2026-06-01,state_board,,,,',
        'john.smith@example.com,bls_certification,certification,BLS,,,2025-01-15,2027-01-15,uploaded_doc,,,,',
        'driver.alice@example.com,cdl,driver_record,CDL Class B,CA,DL98765,2023-08-10,2028-08-10,state_board,2026-08-10,2026-04-01,Class B + P (passenger),Annual MVR completed',
    ]
    const blob = new Blob([headers.join(',') + '\n' + example.join('\n')], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'credentials-bulk-template.csv'
    a.click()
    URL.revokeObjectURL(url)
}
</script>

<template>
    <AppShell>
        <Head title="Bulk import credentials" />

        <div class="max-w-4xl mx-auto px-6 py-8">
            <div class="flex items-start justify-between mb-6 flex-wrap gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100 flex items-center gap-2">
                        <DocumentArrowUpIcon class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                        Bulk Import Credentials
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                        Upload a CSV to create many staff credential records at once. One row per credential.
                    </p>
                </div>
                <button @click="downloadTemplate" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 text-sm font-medium text-gray-700 dark:text-slate-200">
                    <ArrowDownTrayIcon class="w-4 h-4" /> Download CSV template
                </button>
            </div>

            <div class="rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/30 px-5 py-4 mb-6 flex items-start gap-3 text-sm">
                <InformationCircleIcon class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div class="text-blue-900 dark:text-blue-100 space-y-1">
                    <p><strong>Required columns</strong> : <code class="text-xs">email</code>, plus either <code class="text-xs">credential_code</code> (matching a catalog entry) OR both <code class="text-xs">credential_type</code> + <code class="text-xs">title</code> for free-form rows.</p>
                    <p><strong>Optional</strong> : <code class="text-xs">license_state, license_number, issued_at, expires_at, verification_source, notes</code>. Dates use ISO format (YYYY-MM-DD).</p>
                    <p>Rows where the email doesn't match an existing user are skipped with a reason.</p>
                </div>
            </div>

            <!-- Upload -->
            <div class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-6 mb-6">
                <label class="block">
                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300">CSV file (max 5 MB)</span>
                    <input type="file" accept=".csv,text/csv" @change="onPick"
                           class="mt-2 block w-full text-sm text-slate-500 dark:text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-indigo-900/40 dark:file:text-indigo-300" />
                </label>
                <p v-if="file" class="text-xs text-slate-600 dark:text-slate-300 mt-2">Selected : {{ file.name }} ({{ Math.round(file.size / 1024) }} KB)</p>
                <div class="mt-4">
                    <button @click="upload" :disabled="!file || uploading"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium disabled:opacity-50">
                        {{ uploading ? 'Uploading...' : 'Upload + import' }}
                    </button>
                </div>
            </div>

            <!-- Error -->
            <div v-if="error" class="rounded-lg bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/40 text-rose-800 dark:text-rose-200 px-4 py-3 mb-4 text-sm">
                {{ error }}
            </div>

            <!-- Report -->
            <div v-if="report" class="space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-xl border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50 dark:bg-emerald-950/30 p-4">
                        <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Created</p>
                        <p class="text-2xl font-bold text-emerald-800 dark:text-emerald-200 tabular-nums">{{ report.created }}</p>
                    </div>
                    <div class="rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50 dark:bg-amber-950/30 p-4">
                        <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Skipped</p>
                        <p class="text-2xl font-bold text-amber-800 dark:text-amber-200 tabular-nums">{{ report.skipped }}</p>
                    </div>
                    <div class="rounded-xl border border-rose-200 dark:border-rose-900/40 bg-rose-50 dark:bg-rose-950/30 p-4">
                        <p class="text-xs uppercase tracking-wide text-rose-700 dark:text-rose-300">Errors</p>
                        <p class="text-2xl font-bold text-rose-800 dark:text-rose-200 tabular-nums">{{ report.errors.length }}</p>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-200 dark:border-slate-700 flex items-center gap-2">
                        <ClipboardDocumentListIcon class="w-4 h-4 text-gray-500" />
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-200 uppercase tracking-wide">Per-row outcome</h2>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-800 text-xs text-gray-700 dark:text-slate-200">
                            <tr>
                                <th class="text-left px-4 py-2 font-medium">Row</th>
                                <th class="text-left px-4 py-2 font-medium">Email</th>
                                <th class="text-left px-4 py-2 font-medium">Outcome</th>
                                <th class="text-left px-4 py-2 font-medium">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-800">
                            <tr v-for="r in report.rows" :key="r.row">
                                <td class="px-4 py-2 text-gray-500 dark:text-slate-400 tabular-nums">{{ r.row }}</td>
                                <td class="px-4 py-2 text-gray-900 dark:text-slate-100">{{ r.email }}</td>
                                <td class="px-4 py-2">
                                    <span v-if="r.outcome === 'created'" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">
                                        <CheckCircleIcon class="w-3 h-3" /> created
                                    </span>
                                    <span v-else-if="r.outcome === 'skipped'" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">
                                        skipped
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">
                                        <ExclamationTriangleIcon class="w-3 h-3" /> error
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-gray-600 dark:text-slate-300 text-xs">
                                    {{ r.reason ?? r.title ?? '' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppShell>
</template>
