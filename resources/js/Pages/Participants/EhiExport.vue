<script setup lang="ts">
// ─── Participants / EHI Export ────────────────────────────────────────────────
// 21st Century Cures Act § 4004: Electronic Health Information export.
// Staff-initiated (at participant or rep request). 24-hour download token.
// Phase 5 (MVP roadmap).
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    ArchiveBoxArrowDownIcon,
    ArrowLeftIcon,
    CheckBadgeIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    InformationCircleIcon,
} from '@heroicons/vue/24/outline'

interface ExportRow {
    id: number
    status: 'pending' | 'ready' | 'downloaded' | 'expired'
    requested_by: string | null
    created_at: string | null
    expires_at: string | null
    downloaded_at: string | null
    downloadable: boolean
    download_url: string | null
}

const props = defineProps<{
    participant: { id: number; mrn: string; first_name: string; last_name: string }
    exports: ExportRow[]
}>()

const generating = ref(false)

async function requestExport() {
    generating.value = true
    try {
        await axios.post(`/participants/${props.participant.id}/ehi-export`)
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to generate EHI export.')
    } finally {
        generating.value = false
    }
}

function fmt(d: string | null): string {
    return d ? new Date(d).toLocaleString() : '-'
}

function hoursRemaining(expiresAt: string | null): string {
    if (!expiresAt) return '-'
    const ms = new Date(expiresAt).getTime() - Date.now()
    if (ms <= 0) return 'expired'
    const hours = Math.floor(ms / 3_600_000)
    if (hours >= 1) return `${hours}h remaining`
    return `${Math.max(1, Math.floor(ms / 60_000))}m remaining`
}
</script>

<template>
    <AppShell>
        <Head :title="`EHI Export: ${participant.first_name} ${participant.last_name}`" />

        <div class="px-6 py-6 max-w-5xl mx-auto space-y-6">
            <!-- Header -->
            <div>
                <Link :href="`/participants/${participant.id}`"
                    class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:underline mb-3">
                    <ArrowLeftIcon class="w-4 h-4" /> Back to Participant
                </Link>
                <div class="flex items-start gap-3">
                    <ArchiveBoxArrowDownIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">
                            EHI Export: {{ participant.last_name }}, {{ participant.first_name }}
                        </h1>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Electronic Health Information export per 21st Century Cures Act § 4004 · MRN {{ participant.mrn }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Info-blocking notice -->
            <div class="flex items-start gap-3 rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30 px-5 py-4">
                <InformationCircleIcon class="w-5 h-5 shrink-0 mt-0.5 text-blue-700 dark:text-blue-300" />
                <div class="text-sm text-blue-800 dark:text-blue-200">
                    <p class="font-semibold mb-0.5">Participant Access to EHI</p>
                    <p>
                        This tool generates a portable archive of the participant's Electronic Health Information (EHI)
                        in FHIR R4 Bundle format plus clinical data in machine-readable JSON. Generate at the participant
                        or their representative's request. The download link is valid for 24 hours.
                        See <Link href="/policies/info-blocking" class="underline">Information Blocking policy</Link>.
                    </p>
                </div>
            </div>

            <!-- Generate button -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Request New Export</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        Compiles every FHIR-mapped resource + clinical notes + assessments + ADL + SDRs + incidents into a
                        single ZIP archive with a FHIR R4 Bundle.
                    </p>
                </div>
                <button :disabled="generating" @click="requestExport"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
                    <ArchiveBoxArrowDownIcon class="w-4 h-4" />
                    {{ generating ? 'Generating...' : 'Generate EHI Export' }}
                </button>
            </div>

            <!-- History table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Past Exports</h2>
                </div>
                <div v-if="exports.length === 0" class="py-16 text-center text-sm text-slate-400">
                    No exports generated yet.
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/40 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-5 py-3 text-left">Requested</th>
                            <th class="px-5 py-3 text-left">By</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-left">Expires</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr v-for="e in exports" :key="e.id">
                            <td class="px-5 py-3 text-slate-700 dark:text-slate-200 text-xs">{{ fmt(e.created_at) }}</td>
                            <td class="px-5 py-3 text-slate-700 dark:text-slate-200 text-xs">{{ e.requested_by ?? '-' }}</td>
                            <td class="px-5 py-3 text-xs">
                                <span v-if="e.downloaded_at" class="inline-flex items-center gap-1 text-slate-500 dark:text-slate-400">
                                    <CheckBadgeIcon class="w-4 h-4" /> Downloaded {{ fmt(e.downloaded_at) }}
                                </span>
                                <span v-else-if="e.status === 'expired'" class="inline-flex items-center gap-1 text-red-600 dark:text-red-400">
                                    <ExclamationTriangleIcon class="w-4 h-4" /> Expired
                                </span>
                                <span v-else-if="e.downloadable" class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-300">
                                    <ClockIcon class="w-4 h-4" /> Ready
                                </span>
                                <span v-else class="inline-flex items-center gap-1 text-slate-500">
                                    <ClockIcon class="w-4 h-4" /> {{ e.status }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400 text-xs">
                                {{ fmt(e.expires_at) }}
                                <span v-if="e.downloadable" class="block text-slate-400">{{ hoursRemaining(e.expires_at) }}</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a v-if="e.downloadable && e.download_url" :href="e.download_url"
                                    class="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                    Download ZIP
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
