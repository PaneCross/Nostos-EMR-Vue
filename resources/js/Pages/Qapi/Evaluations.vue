<script setup lang="ts">
// ─── QAPI Annual Evaluations ──────────────────────────────────────────────────
// 42 CFR §460.200 — annual evaluation artifact reviewed by governing body.
// Authors: QA Compliance + IT Admin + Super Admin.
// ─────────────────────────────────────────────────────────────────────────────

import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    ClipboardDocumentCheckIcon,
    DocumentArrowDownIcon,
    CheckCircleIcon,
    PlusIcon,
} from '@heroicons/vue/24/outline'

interface SummarySnapshot {
    total_projects?: number
    active_count?: number
    completed_count?: number
    incident_count?: number
    grievance_count?: number
    appeal_count?: number
    appeals_overturned?: number
    mortality_count?: number
}

interface Evaluation {
    id: number
    year: number
    generated_at: string | null
    generated_by: string | null
    summary_snapshot: SummarySnapshot | null
    governing_body_reviewed_at: string | null
    governing_body_reviewer: string | null
    governing_body_notes: string | null
    pdf_available: boolean
}

const props = defineProps<{
    evaluations: Evaluation[]
    current_year: number
}>()

const yearInput = ref(props.current_year)
const generating = ref(false)
const reviewingId = ref<number | null>(null)
const reviewNotes = ref('')

async function generate() {
    generating.value = true
    try {
        await axios.post('/qapi/evaluations', { year: yearInput.value })
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to generate evaluation.')
    } finally {
        generating.value = false
    }
}

function openReview(ev: Evaluation) {
    reviewingId.value = ev.id
    reviewNotes.value = ''
}
async function submitReview() {
    if (reviewingId.value === null) return
    try {
        await axios.post(`/qapi/evaluations/${reviewingId.value}/review`, { notes: reviewNotes.value })
        reviewingId.value = null
        reviewNotes.value = ''
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to record review.')
    }
}

function fmt(d: string | null): string {
    if (!d) return '—'
    return new Date(d).toLocaleString()
}
</script>

<template>
    <AppShell>
        <Head title="QAPI Annual Evaluations" />

        <div class="px-6 py-6 max-w-5xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div class="flex items-start gap-3">
                    <ClipboardDocumentCheckIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">QAPI Annual Evaluations</h1>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Annual compliance artifact submitted to governing body — 42 CFR §460.200
                        </p>
                    </div>
                </div>
            </div>

            <!-- Generate card -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">
                    Generate Evaluation
                </h2>
                <div class="flex items-end gap-3 flex-wrap">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Year</label>
                        <input
                            v-model.number="yearInput"
                            type="number"
                            min="2000" max="2100"
                            class="w-24 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2"
                        />
                    </div>
                    <button
                        :disabled="generating"
                        @click="generate"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
                    >
                        <PlusIcon class="w-4 h-4" />
                        {{ generating ? 'Generating...' : 'Generate / Regenerate' }}
                    </button>
                    <p class="text-xs text-slate-500 dark:text-slate-400 flex-1">
                        Regeneration preserves an existing governing body review stamp — only the PDF and metric snapshot refresh.
                    </p>
                </div>
            </div>

            <!-- List -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <div v-if="evaluations.length === 0" class="py-16 text-center text-sm text-slate-400">
                    No annual evaluations generated yet.
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/40 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left">Year</th>
                            <th class="px-4 py-3 text-left">Generated</th>
                            <th class="px-4 py-3 text-left">Projects (total / active / completed)</th>
                            <th class="px-4 py-3 text-left">Governing Body Review</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr v-for="e in evaluations" :key="e.id">
                            <td class="px-4 py-3 font-semibold text-slate-800 dark:text-slate-200">{{ e.year }}</td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">
                                {{ fmt(e.generated_at) }}
                                <span v-if="e.generated_by" class="block text-slate-400">by {{ e.generated_by }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                                <span class="tabular-nums">
                                    {{ e.summary_snapshot?.total_projects ?? 0 }} /
                                    {{ e.summary_snapshot?.active_count ?? 0 }} /
                                    {{ e.summary_snapshot?.completed_count ?? 0 }}
                                </span>
                                <span class="block text-slate-400 dark:text-slate-500 mt-0.5">
                                    {{ e.summary_snapshot?.incident_count ?? 0 }} incidents ·
                                    {{ e.summary_snapshot?.grievance_count ?? 0 }} grievances ·
                                    {{ e.summary_snapshot?.appeal_count ?? 0 }} appeals
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <template v-if="e.governing_body_reviewed_at">
                                    <span class="inline-flex items-center gap-1 text-green-700 dark:text-green-300 font-semibold">
                                        <CheckCircleIcon class="w-4 h-4" /> Reviewed
                                    </span>
                                    <span class="block text-slate-500">
                                        {{ fmt(e.governing_body_reviewed_at) }}
                                        <template v-if="e.governing_body_reviewer">by {{ e.governing_body_reviewer }}</template>
                                    </span>
                                    <p v-if="e.governing_body_notes" class="text-slate-500 mt-1 whitespace-pre-wrap">{{ e.governing_body_notes }}</p>
                                </template>
                                <template v-else>
                                    <button
                                        @click="openReview(e)"
                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 text-xs font-medium hover:bg-indigo-50 dark:hover:bg-indigo-900/30"
                                    >
                                        Record Review
                                    </button>
                                </template>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a
                                    v-if="e.pdf_available"
                                    :href="`/qapi/evaluations/${e.id}/download`"
                                    class="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                                >
                                    <DocumentArrowDownIcon class="w-4 h-4" /> PDF
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Review modal -->
            <div v-if="reviewingId !== null" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" @click.self="reviewingId = null">
                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md">
                    <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                        <h3 class="font-semibold text-slate-900 dark:text-slate-100">Record Governing Body Review</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            Stamps this evaluation as reviewed by the governing body. Your user will be recorded as the reviewer.
                        </p>
                    </div>
                    <div class="px-6 py-5 space-y-3">
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Notes (optional)</label>
                        <textarea
                            v-model="reviewNotes"
                            rows="4"
                            placeholder="Board actions taken, follow-ups, etc."
                            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2"
                        />
                    </div>
                    <div class="px-6 py-3 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-2">
                        <button @click="reviewingId = null" class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 text-sm">Cancel</button>
                        <button @click="submitReview" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">
                            Record Review
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
