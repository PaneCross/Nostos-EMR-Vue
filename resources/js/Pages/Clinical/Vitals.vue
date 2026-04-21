<script setup lang="ts">
// ─── Clinical/Vitals.vue ──────────────────────────────────────────────────────
// Vitals overview across all participants. Client-side search by name/MRN.
// Out-of-range values highlighted. "Current" vs "Overdue" status badge.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { HeartIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface VitalRow {
    id: number
    recorded_at: string | null
    bp_systolic: number | null
    bp_diastolic: number | null
    pulse: number | null
    o2_saturation: number | null
    weight_lbs: number | null
    temperature_f: number | null
    pain_score: number | null
    blood_glucose: number | null
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null
}

const props = defineProps<{
    vitals: VitalRow[]
    participantsWithFreshVitals: number[]
}>()

// ── Search (client-side) ───────────────────────────────────────────────────────

const search = ref('')

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.vitals
    return props.vitals.filter(v => {
        if (!v.participant) return false
        const name = `${v.participant.first_name} ${v.participant.last_name}`.toLowerCase()
        const mrn = v.participant.mrn.toLowerCase()
        return name.includes(q) || mrn.includes(q)
    })
})

// ── Display helpers ────────────────────────────────────────────────────────────

const freshSet = computed(() => new Set(props.participantsWithFreshVitals))

function isFresh(participantId: number | undefined): boolean {
    if (participantId == null) return false
    return freshSet.value.has(participantId)
}

// Relative time: "3 days ago", "2 hours ago", etc.
function relativeTime(val: string | null): string {
    if (!val) return '-'
    const d = new Date(val)
    if (isNaN(d.getTime())) return '-'
    const diffMs = Date.now() - d.getTime()
    const diffMin = Math.floor(diffMs / 60_000)
    if (diffMin < 1) return 'just now'
    if (diffMin < 60) return `${diffMin} min ago`
    const diffHr = Math.floor(diffMin / 60)
    if (diffHr < 24) return `${diffHr} hr ago`
    const diffDays = Math.floor(diffHr / 24)
    if (diffDays < 30) return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`
    const diffWk = Math.floor(diffDays / 7)
    return `${diffWk} wk ago`
}

function fmtBp(systolic: number | null, diastolic: number | null): string {
    if (systolic == null && diastolic == null) return '-'
    return `${systolic ?? '?'}/${diastolic ?? '?'}`
}

function fmtNum(val: number | null, unit = ''): string {
    if (val == null) return '-'
    return unit ? `${val} ${unit}` : String(val)
}

// Out-of-range text classes
function bpClass(systolic: number | null): string {
    if (systolic == null) return 'text-gray-600 dark:text-slate-400'
    if (systolic > 160 || systolic < 90) return 'text-red-600 dark:text-red-400 font-semibold'
    return 'text-gray-900 dark:text-slate-100'
}

function o2Class(o2: number | null): string {
    if (o2 == null) return 'text-gray-600 dark:text-slate-400'
    if (o2 < 94) return 'text-red-600 dark:text-red-400 font-semibold'
    return 'text-gray-900 dark:text-slate-100'
}

function painClass(pain: number | null): string {
    if (pain == null) return 'text-gray-600 dark:text-slate-400'
    if (pain > 6) return 'text-amber-600 dark:text-amber-400 font-semibold'
    return 'text-gray-900 dark:text-slate-100'
}

// Counts
const freshCount = computed(() =>
    props.vitals.filter(v => v.participant && freshSet.value.has(v.participant.id)).length,
)
const uniqueParticipants = computed(() => {
    const ids = new Set(props.vitals.map(v => v.participant?.id).filter(Boolean))
    return ids.size
})
</script>

<template>
    <Head title="Vitals Overview" />

    <AppShell>
        <template #header>
            <div class="flex items-center gap-2">
                <HeartIcon class="w-5 h-5 text-gray-500 dark:text-slate-400" aria-hidden="true" />
                <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                    Vitals Overview
                </h1>
            </div>
        </template>

        <div class="px-6 py-5">
            <!-- ── Page header ── -->
            <div class="flex flex-wrap items-center gap-3 mb-5">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300">
                    {{ freshCount }} current
                </span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300">
                    {{ uniqueParticipants - freshCount }} overdue
                </span>
                <span class="text-sm text-gray-500 dark:text-slate-400">
                    {{ uniqueParticipants }} participant{{ uniqueParticipants !== 1 ? 's' : '' }} total
                </span>
            </div>

            <!-- ── Search bar ── -->
            <div class="relative mb-4 max-w-sm">
                <MagnifyingGlassIcon
                    class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-slate-500"
                    aria-hidden="true"
                />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by name or MRN"
                    aria-label="Search participants"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400"
                />
            </div>

            <!-- ── Table ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-slate-700" aria-label="Vitals overview">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th
                                v-for="col in ['Participant', 'Last Recorded', 'BP', 'Pulse', 'O2 Sat', 'Weight', 'Pain', 'Status']"
                                :key="col"
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr v-if="filtered.length === 0">
                            <td colspan="8" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                                No vitals records found.
                            </td>
                        </tr>

                        <tr
                            v-for="row in filtered"
                            :key="row.id"
                            class="bg-white dark:bg-slate-800 hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors"
                            tabindex="0"
                            :aria-label="row.participant ? `Open chart for ${row.participant.last_name}, ${row.participant.first_name}` : 'Open participant chart'"
                            @click="row.participant && router.visit(`/participants/${row.participant.id}?tab=vitals`)"
                            @keydown.enter="row.participant && router.visit(`/participants/${row.participant.id}?tab=vitals`)"
                        >
                            <!-- Participant -->
                            <td class="px-4 py-3">
                                <template v-if="row.participant">
                                    <div class="font-medium text-blue-600 dark:text-blue-400 hover:underline text-sm">
                                        {{ row.participant.last_name }}, {{ row.participant.first_name }}
                                    </div>
                                    <div class="font-mono text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                                        {{ row.participant.mrn }}
                                    </div>
                                </template>
                                <span v-else class="text-gray-400 dark:text-slate-500 text-sm">-</span>
                            </td>

                            <!-- Last recorded -->
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-400">
                                {{ relativeTime(row.recorded_at) }}
                            </td>

                            <!-- BP -->
                            <td class="px-4 py-3 text-sm" :class="bpClass(row.bp_systolic)">
                                {{ fmtBp(row.bp_systolic, row.bp_diastolic) }}
                            </td>

                            <!-- Pulse -->
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-400">
                                {{ fmtNum(row.pulse) }}
                            </td>

                            <!-- O2 sat -->
                            <td class="px-4 py-3 text-sm" :class="o2Class(row.o2_saturation)">
                                {{ row.o2_saturation != null ? `${row.o2_saturation}%` : '-' }}
                            </td>

                            <!-- Weight -->
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-400">
                                {{ row.weight_lbs != null ? `${row.weight_lbs} lbs` : '-' }}
                            </td>

                            <!-- Pain -->
                            <td class="px-4 py-3 text-sm" :class="painClass(row.pain_score)">
                                {{ fmtNum(row.pain_score) }}
                            </td>

                            <!-- Status badge -->
                            <td class="px-4 py-3">
                                <span
                                    v-if="row.participant && isFresh(row.participant.id)"
                                    class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300"
                                >
                                    Current
                                </span>
                                <span
                                    v-else
                                    class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300"
                                >
                                    Overdue
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
