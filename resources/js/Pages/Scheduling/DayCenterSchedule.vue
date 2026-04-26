<!--
  Day Center Schedule Management — bulk view/edit of participant recurring
  day-center patterns. Click a day to stage a change; changes are held in a
  pending queue until the user reviews and approves them in batch. Protects
  against accidental single-click edits.
  Route: GET /scheduling/day-center/manage
-->
<script setup lang="ts">
// ─── Scheduling/DayCenterSchedule ───────────────────────────────────────────
// Bulk-edit each participant's recurring weekly day-center pattern (e.g.
// "Mary attends Mon/Wed/Fri at the East site"). Changes stage in a pending
// queue and require explicit batch approval — protects against accidental
// single-click edits to production schedules.
//
// Audience: Center Manager, Scheduling staff, IDT (recommends pattern).
//
// Notable rules:
//   - Day-center frequency is part of the participant's plan of care; major
//     changes need IDT acknowledgement (see Idt/RunMeeting.vue).
//   - Pending changes show pre/post diff so reviewers see what they're
//     approving before commit.
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import {
    MagnifyingGlassIcon, CheckCircleIcon, XCircleIcon,
    ArrowPathIcon, ExclamationTriangleIcon, ClipboardDocumentCheckIcon,
} from '@heroicons/vue/24/outline'

interface ParticipantRow {
    id:              number
    mrn:             string
    name:            string
    preferred_name:  string | null
    site_id:         number
    site_name:       string | null
    day_center_days: string[]
    original_days:   string[]   // snapshot of server state, used to detect pending changes
}

interface SiteOption { id: number; name: string }

const props = defineProps<{
    participants: { id: number; mrn: string; name: string; preferred_name: string | null; site_id: number; site_name: string | null; day_center_days: string[] }[]
    sites:        SiteOption[]
    selectedSite: number | null
}>()

// ── Mutable row state (local) ────────────────────────────────────────────────
// Each row has day_center_days (current staged) + original_days (last-saved).
const rows = ref<ParticipantRow[]>(props.participants.map(p => ({
    ...p,
    day_center_days: [...(p.day_center_days ?? [])],
    original_days:   [...(p.day_center_days ?? [])],
})))

const DAY_CODES = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
const DAY_LABELS: Record<string, string> = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' }

// Sort an array of day codes into weekday order (mon first, sun last).
function sortDays(days: string[]): string[] {
    return DAY_CODES.filter(d => days.includes(d))
}

// Format for display (e.g. "Mon, Wed, Fri"), chronological.
function formatDays(days: string[]): string {
    if (!days.length) return 'No schedule'
    return sortDays(days).map(d => DAY_LABELS[d]).join(', ')
}

const search = ref('')
const siteFilter = ref<string>(props.selectedSite?.toString() ?? '')

// ── Filtering ─────────────────────────────────────────────────────────────────
const filteredRows = computed(() => {
    let list = rows.value
    if (siteFilter.value) {
        const id = Number(siteFilter.value)
        list = list.filter(r => r.site_id === id)
    }
    if (search.value.trim()) {
        const q = search.value.toLowerCase()
        list = list.filter(r => r.name.toLowerCase().includes(q) || r.mrn.toLowerCase().includes(q))
    }
    return list
})

// ── Pending changes ──────────────────────────────────────────────────────────
function rowHasChanges(row: ParticipantRow): boolean {
    if (row.day_center_days.length !== row.original_days.length) return true
    const a = [...row.day_center_days].sort()
    const b = [...row.original_days].sort()
    return a.some((d, i) => d !== b[i])
}

const pendingRows = computed(() => rows.value.filter(rowHasChanges))

interface DiffSummary {
    added: string[]
    removed: string[]
}
function diff(row: ParticipantRow): DiffSummary {
    return {
        added:   sortDays(row.day_center_days.filter(d => !row.original_days.includes(d))),
        removed: sortDays(row.original_days.filter(d => !row.day_center_days.includes(d))),
    }
}

// ── Toggle a day (staged, not saved) ─────────────────────────────────────────
function toggleDay(row: ParticipantRow, code: string) {
    const idx = row.day_center_days.indexOf(code)
    if (idx >= 0) row.day_center_days.splice(idx, 1)
    else           row.day_center_days.push(code)
}

// Four cell states: unchanged-on (saved blue), unchanged-off (muted),
// pending-add (solid green), pending-remove (red w/ strikethrough).
// Light + dark mode friendly; line-through on removals provides non-color cue.
function dayPillClass(row: ParticipantRow, code: string): string {
    const isOn    = row.day_center_days.includes(code)
    const wasOn   = row.original_days.includes(code)
    const pendingAdd    = isOn  && !wasOn
    const pendingRemove = !isOn && wasOn

    if (pendingAdd) {
        return 'bg-green-500 dark:bg-green-600 text-white border-green-600 dark:border-green-500 ring-2 ring-green-200 dark:ring-green-800 ring-offset-0'
    }
    if (pendingRemove) {
        return 'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 border-red-400 dark:border-red-700 line-through ring-2 ring-red-200 dark:ring-red-800 ring-offset-0'
    }
    if (isOn) {
        return 'bg-blue-600 text-white border-blue-600'
    }
    return 'bg-white dark:bg-slate-700 text-gray-500 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600'
}

function revertRow(row: ParticipantRow) {
    row.day_center_days = [...row.original_days]
}

function discardAll() {
    for (const r of rows.value) {
        if (rowHasChanges(r)) r.day_center_days = [...r.original_days]
    }
}

// ── Review modal + batch apply ────────────────────────────────────────────────
const showReview = ref(false)
const applying   = ref(false)
const applyProgress = ref({ done: 0, total: 0, failed: 0 })
const applyErrorMessage = ref<string>('')

function openReview() {
    if (pendingRows.value.length === 0) return
    applyErrorMessage.value = ''
    showReview.value = true
}

// Auto-close modal if the user undoes every pending change from within it
watch(pendingRows, (p) => {
    if (showReview.value && p.length === 0 && !applying.value) {
        showReview.value = false
    }
})

async function applyChanges() {
    const pending = pendingRows.value
    if (pending.length === 0) return

    applying.value = true
    applyErrorMessage.value = ''
    applyProgress.value = { done: 0, total: pending.length, failed: 0 }

    const payload = {
        updates: pending.map(r => ({
            participant_id:  r.id,
            day_center_days: r.day_center_days.length > 0 ? r.day_center_days : null,
        })),
    }

    try {
        const res = await axios.post('/scheduling/day-center/manage/bulk', payload)
        const updated = res.data?.updated ?? 0
        const failed  = res.data?.failed ?? []
        const failedIds = new Set(failed.map((f: any) => f.participant_id))

        // Sync original_days on successfully updated rows
        for (const row of pending) {
            if (!failedIds.has(row.id)) {
                row.original_days = [...row.day_center_days]
            }
        }

        applyProgress.value = { done: updated, total: pending.length, failed: failed.length }

        if (failed.length === 0) {
            setTimeout(() => { showReview.value = false }, 800)
        }
    } catch (err: any) {
        const status = err.response?.status
        const msg    = err.response?.data?.message ?? err.message ?? 'Unknown error'
        applyErrorMessage.value = status
            ? `Save failed (HTTP ${status}): ${msg}`
            : `Save failed: ${msg}`
        applyProgress.value.failed = pending.length
    } finally {
        applying.value = false
    }
}

// ── Summary metrics ───────────────────────────────────────────────────────────
const totalEnrolled = computed(() => filteredRows.value.length)
const dayCounts = computed(() => {
    const counts: Record<string, number> = { mon: 0, tue: 0, wed: 0, thu: 0, fri: 0, sat: 0, sun: 0 }
    for (const r of filteredRows.value) {
        for (const d of r.day_center_days) {
            if (counts[d] !== undefined) counts[d]++
        }
    }
    return counts
})
function dayPct(code: string): number {
    if (totalEnrolled.value === 0) return 0
    return Math.round((dayCounts.value[code] / totalEnrolled.value) * 100)
}

// For load balance indicators — highlight peak vs. light days (weekdays only)
const weekdayCounts = computed(() => {
    const c = dayCounts.value
    return [c.mon, c.tue, c.wed, c.thu, c.fri]
})
const peakCount = computed(() => Math.max(...weekdayCounts.value, 0))
const lightCount = computed(() => {
    const nonZero = weekdayCounts.value.filter(n => n > 0)
    return nonZero.length === 0 ? 0 : Math.min(...nonZero)
})
// Substantial-variation threshold: require deviation of at least 2 participants
// AND 15% of the weekday average. Prevents flagging tiny noise on small programs
// while scaling cleanly as the roster grows. If the week is evenly distributed,
// no days get tagged.
function loadThreshold(): number {
    return Math.max(2, avgWeekday.value * 0.15)
}

function dayLoadLabel(code: string): 'peak' | 'light' | 'normal' | 'empty' {
    const n = dayCounts.value[code]
    if (n === 0) return 'empty'
    const isWeekday = ['mon', 'tue', 'wed', 'thu', 'fri'].includes(code)
    if (!isWeekday) return 'normal'

    const avg   = avgWeekday.value
    const delta = n - avg
    const t     = loadThreshold()

    if (delta >= t)  return 'peak'
    if (-delta >= t) return 'light'
    return 'normal'
}
const avgWeekday = computed(() => {
    const sum = weekdayCounts.value.reduce((a, b) => a + b, 0)
    return weekdayCounts.value.length ? Math.round(sum / weekdayCounts.value.length) : 0
})

// ── Schedule load distribution (participants by # of days scheduled) ─────────
// Gives at-a-glance read of load balancing: are most participants on 3 days?
// Are there many zeros that need attention? Do we have too many 5+ days?
const daysDistribution = computed<number[]>(() => {
    // Index 0..7 = number of scheduled days; value = participant count
    const buckets = [0, 0, 0, 0, 0, 0, 0, 0]
    for (const r of filteredRows.value) {
        const n = Math.min(r.day_center_days.length, 7)
        buckets[n]++
    }
    return buckets
})
const maxBucket = computed(() => Math.max(...daysDistribution.value, 1))

const avgDaysPerParticipant = computed(() => {
    if (filteredRows.value.length === 0) return 0
    const totalDays = filteredRows.value.reduce((sum, r) => sum + r.day_center_days.length, 0)
    return Math.round((totalDays / filteredRows.value.length) * 10) / 10
})

// ── Totals (above table) ──────────────────────────────────────────────────────
const totalWithSchedule = computed(() => filteredRows.value.filter(r => r.day_center_days.length > 0).length)
const totalWithoutSchedule = computed(() => filteredRows.value.filter(r => r.day_center_days.length === 0).length)
const totalPending = computed(() => pendingRows.value.length)
</script>

<template>
    <AppShell>
        <Head title="Day Center Schedule Management" />

        <div class="px-6 py-6 space-y-5">

            <!-- Header -->
            <div>
                <h1 class="text-xl font-bold text-gray-900 dark:text-slate-100">Day Center Schedule Management</h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                    Stage recurring weekday patterns for each participant. Changes are held until you review and approve them in batch below.
                    Appointments of type "Day Center Attendance" still override this on specific dates.
                </p>
            </div>

            <!-- Summary row: weekday cards + compact distribution chart side by side -->
            <div>
                <div class="flex flex-col 2xl:flex-row gap-3">
                    <!-- Weekday cards — expand to fill remaining width -->
                    <div class="grid grid-cols-7 gap-2 flex-1 min-w-0">
                        <div
                            v-for="code in DAY_CODES"
                            :key="code"
                            :class="[
                                'text-center p-3 rounded-xl border transition-colors',
                                dayLoadLabel(code) === 'peak'
                                    ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-800'
                                    : dayLoadLabel(code) === 'light'
                                        ? 'bg-blue-50 dark:bg-blue-950/40 border-blue-300 dark:border-blue-800'
                                        : 'bg-white dark:bg-slate-800 border-gray-200 dark:border-slate-700',
                            ]"
                        >
                            <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ dayCounts[code] }}</p>
                            <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5 font-medium">{{ DAY_LABELS[code] }}</p>
                            <p class="text-sm text-gray-400 dark:text-slate-500 mt-0.5">
                                {{ dayPct(code) }}%
                            </p>
                            <p
                                v-if="dayLoadLabel(code) === 'peak'"
                                class="text-sm mt-1 font-semibold text-amber-700 dark:text-amber-300"
                            >
                                Peak
                            </p>
                            <p
                                v-else-if="dayLoadLabel(code) === 'light'"
                                class="text-sm mt-1 font-semibold text-blue-700 dark:text-blue-300"
                            >
                                Light
                            </p>
                        </div>
                    </div>

                    <!-- Compact distribution chart (fixed width on 2xl+) -->
                    <div
                        class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 2xl:w-80 shrink-0"
                        :title="'Distribution of participants by number of scheduled days per week'"
                    >
                        <div class="flex items-baseline justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Days / Participant</h3>
                            <span class="text-sm text-gray-500 dark:text-slate-400">
                                avg <span class="font-semibold text-gray-700 dark:text-slate-300">{{ avgDaysPerParticipant }}</span>
                            </span>
                        </div>
                        <div class="flex items-end gap-1 h-16">
                            <div
                                v-for="n in 8"
                                :key="n - 1"
                                class="flex-1 flex flex-col items-center gap-1 min-w-0"
                            >
                                <span
                                    class="text-sm tabular-nums leading-none"
                                    :class="daysDistribution[n - 1] > 0 ? 'text-gray-700 dark:text-slate-300 font-semibold' : 'text-gray-300 dark:text-slate-600'"
                                >{{ daysDistribution[n - 1] }}</span>
                                <div class="w-full bg-gray-100 dark:bg-slate-700 rounded flex items-end" style="height: 2rem;">
                                    <div
                                        :class="[
                                            'w-full rounded transition-all',
                                            (n - 1) === 0
                                                ? 'bg-red-400 dark:bg-red-500'
                                                : (n - 1) === 7
                                                    ? 'bg-amber-400 dark:bg-amber-500'
                                                    : 'bg-blue-400 dark:bg-blue-500',
                                        ]"
                                        :style="{ height: `${(daysDistribution[n - 1] / maxBucket) * 100}%` }"
                                    />
                                </div>
                                <span class="text-sm text-gray-400 dark:text-slate-500 tabular-nums leading-none">{{ n - 1 }}d</span>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-sm text-gray-400 dark:text-slate-500 mt-2">
                    Weekday average: {{ avgWeekday }} participants/day ·
                    Peak Mon-Fri: {{ peakCount }} ·
                    Lightest active weekday: {{ lightCount }}
                </p>
            </div>

            <!-- Pending changes bar (slides into view when the first change is staged) -->
            <Transition name="slide-reveal">
                <div
                    v-if="totalPending > 0"
                    class="flex items-center justify-between gap-3 p-4 rounded-xl border-2 border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40"
                >
                    <div class="flex items-center gap-3">
                        <ExclamationTriangleIcon class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0" />
                        <div>
                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">
                                {{ totalPending }} pending change{{ totalPending === 1 ? '' : 's' }}
                            </p>
                            <div class="flex items-center gap-3 text-sm text-amber-700 dark:text-amber-300 mt-0.5 flex-wrap">
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block w-3 h-3 rounded bg-green-500 dark:bg-green-600 border border-green-600 dark:border-green-500"></span>
                                    Adding
                                </span>
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block w-3 h-3 rounded bg-red-50 dark:bg-red-950/60 border border-red-400 dark:border-red-700"></span>
                                    Removing
                                </span>
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block w-3 h-3 rounded bg-blue-600 border border-blue-600"></span>
                                    Saved
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="px-3 py-1.5 rounded-lg border border-amber-300 dark:border-amber-700 text-sm text-amber-800 dark:text-amber-200 hover:bg-amber-100 dark:hover:bg-amber-900/60 font-medium"
                            @click="discardAll"
                        >
                            Discard All
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium"
                            @click="openReview"
                        >
                            <ClipboardDocumentCheckIcon class="w-4 h-4" />
                            Review Changes
                        </button>
                    </div>
                </div>
            </Transition>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-64 max-w-sm">
                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-slate-500" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search by name or MRN..."
                        class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg dark:bg-slate-700"
                    />
                </div>
                <select name="siteFilter" v-model="siteFilter" class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700">
                    <option value="">All Sites</option>
                    <option v-for="s in props.sites" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <span class="text-sm text-gray-500 dark:text-slate-400 ml-auto">
                    {{ filteredRows.length }} participants ·
                    <span class="text-green-600 dark:text-green-400 font-medium">{{ totalWithSchedule }} scheduled</span>
                    ·
                    <span class="text-amber-600 dark:text-amber-400 font-medium">{{ totalWithoutSchedule }} unscheduled</span>
                </span>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Participant</th>
                            <th class="px-4 py-2.5 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">MRN</th>
                            <th class="px-4 py-2.5 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Site</th>
                            <th class="px-4 py-2.5 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Schedule</th>
                            <th class="px-4 py-2.5 text-right text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide w-32"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr
                            v-for="row in filteredRows"
                            :key="row.id"
                            :class="[
                                'transition-colors',
                                rowHasChanges(row)
                                    ? 'bg-amber-50/50 dark:bg-amber-950/20'
                                    : 'hover:bg-gray-50 dark:hover:bg-slate-700/50',
                            ]"
                        >
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">
                                <div class="flex items-center gap-2">
                                    <span class="w-1 h-4 rounded" :class="rowHasChanges(row) ? 'bg-amber-500' : 'bg-transparent'" />
                                    <span>
                                        {{ row.name }}
                                        <span v-if="row.preferred_name" class="text-gray-400 dark:text-slate-500 text-sm ml-1">({{ row.preferred_name }})</span>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 font-mono text-sm text-gray-600 dark:text-slate-400">{{ row.mrn }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-400">{{ row.site_name ?? '-' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <button
                                        v-for="code in DAY_CODES"
                                        :key="code"
                                        type="button"
                                        :class="[
                                            'px-2 py-1 rounded border text-sm font-medium transition-all select-none min-w-[3.25rem]',
                                            dayPillClass(row, code),
                                        ]"
                                        @click="toggleDay(row, code)"
                                    >
                                        {{ DAY_LABELS[code] }}
                                    </button>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    v-if="rowHasChanges(row)"
                                    type="button"
                                    class="inline-flex items-center gap-1 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
                                    @click="revertRow(row)"
                                >
                                    <ArrowPathIcon class="w-3.5 h-3.5" /> Revert
                                </button>
                            </td>
                        </tr>
                        <tr v-if="filteredRows.length === 0">
                            <td colspan="5" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                                No participants match the current filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Review & approve modal -->
        <div
            v-if="showReview"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="!applying && (showReview = false)"
        >
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col" role="dialog" aria-modal="true">
                <div class="px-6 pt-5 pb-4 border-b border-gray-100 dark:border-slate-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <ClipboardDocumentCheckIcon class="w-5 h-5 text-amber-600 dark:text-amber-400" />
                            <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">
                                Review Schedule Changes
                            </h2>
                        </div>
                        <button
                            v-if="!applying"
                            class="text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300"
                            aria-label="Close"
                            @click="showReview = false"
                        >&#x2715;</button>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                        {{ pendingRows.length }} participant{{ pendingRows.length === 1 ? '' : 's' }} will be updated.
                    </p>
                </div>

                <!-- Change list -->
                <div class="flex-1 overflow-y-auto px-6 py-4 space-y-2">
                    <div
                        v-for="row in pendingRows"
                        :key="row.id"
                        class="border border-gray-200 dark:border-slate-700 rounded-lg p-3"
                    >
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-slate-100 truncate">{{ row.name }}</p>
                                <p class="text-sm text-gray-500 dark:text-slate-400">{{ row.mrn }} · {{ row.site_name }}</p>
                            </div>
                            <button
                                v-if="!applying"
                                type="button"
                                class="shrink-0 inline-flex items-center gap-1 px-2 py-1 rounded text-sm font-medium text-gray-600 dark:text-slate-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 border border-gray-200 dark:border-slate-700"
                                title="Undo this participant's changes"
                                @click="revertRow(row)"
                            >
                                <ArrowPathIcon class="w-3.5 h-3.5" />
                                Undo
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-2 text-sm">
                            <!-- Added days (chronological) -->
                            <span
                                v-for="d in diff(row).added"
                                :key="'add-' + d"
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300 font-medium"
                            >
                                <CheckCircleIcon class="w-3.5 h-3.5" />
                                Add {{ DAY_LABELS[d] }}
                            </span>
                            <!-- Removed days (chronological) -->
                            <span
                                v-for="d in diff(row).removed"
                                :key="'rm-' + d"
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 font-medium"
                            >
                                <XCircleIcon class="w-3.5 h-3.5" />
                                Remove {{ DAY_LABELS[d] }}
                            </span>
                        </div>
                        <!-- Before / after — always chronological -->
                        <p class="text-sm text-gray-400 dark:text-slate-500 mt-1.5">
                            <span class="line-through">{{ formatDays(row.original_days) }}</span>
                            <span class="mx-1">→</span>
                            <span class="text-gray-600 dark:text-slate-400">{{ formatDays(row.day_center_days) }}</span>
                        </p>
                    </div>
                </div>

                <!-- Progress bar + actions -->
                <div class="px-6 py-4 border-t border-gray-100 dark:border-slate-700">
                    <div
                        v-if="applyErrorMessage"
                        class="mb-3 px-3 py-2 rounded bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-sm text-red-700 dark:text-red-300"
                    >
                        {{ applyErrorMessage }}
                    </div>
                    <div v-if="applying || applyProgress.failed > 0" class="mb-3">
                        <div class="flex items-center justify-between text-sm mb-1">
                            <span class="text-gray-600 dark:text-slate-400">
                                Applying changes: {{ applyProgress.done }} / {{ applyProgress.total }}
                            </span>
                            <span v-if="applyProgress.failed > 0" class="text-red-600 dark:text-red-400 font-medium">
                                {{ applyProgress.failed }} failed
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-slate-700 rounded-full h-1.5">
                            <div
                                class="bg-green-500 h-1.5 rounded-full transition-all"
                                :style="{ width: `${applyProgress.total === 0 ? 0 : (applyProgress.done / applyProgress.total) * 100}%` }"
                            />
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button
                            :disabled="applying"
                            class="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 disabled:opacity-50"
                            @click="showReview = false"
                        >
                            {{ applying ? 'Applying...' : 'Close' }}
                        </button>
                        <button
                            :disabled="applying || pendingRows.length === 0"
                            class="px-4 py-2 text-sm bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium disabled:opacity-50"
                            @click="applyChanges"
                        >
                            {{ applying ? 'Applying...' : `Approve & Save All (${pendingRows.length})` }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
