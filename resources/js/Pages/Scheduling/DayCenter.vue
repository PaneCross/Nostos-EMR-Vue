<!--
  Day Center Attendance — manage daily check-ins and absences for enrolled
  PACE participants attending the day center.

  Shows 4 KPI summary cards (present / absent / excused / late), an attendance
  table with status chips and action buttons, and a Mark Absence modal for
  recording absent or excused absences with a reason and optional notes.

  Route:   GET /scheduling/day-center -> Inertia::render('Scheduling/DayCenter')
  Props:   attendance, summary, selectedDate, selectedSite, statusLabels,
           absentReasons, canManage
-->
<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ─────────────────────────────────────────────────────────────────────

interface AttendanceParticipant {
    id: number
    mrn: string
    first_name: string
    last_name: string
}

interface AttendanceRecord {
    id: number
    participant: AttendanceParticipant
    status: string
    check_in_time: string | null
    check_out_time: string | null
    absent_reason: string | null
}

interface Summary {
    total: number
    present: number
    absent: number
    excused: number
    late: number
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    attendance: AttendanceRecord[]
    summary: Summary
    selectedDate: string
    selectedSite: number | string
    statusLabels: Record<string, string>
    absentReasons: Record<string, string>
    canManage: boolean
}>()

// ── Constants ─────────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
    present: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    absent: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    late: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    excused: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
}

// ── Summary items ─────────────────────────────────────────────────────────────

const summaryItems = [
    { key: 'present', label: 'Present', color: 'text-green-600 dark:text-green-400' },
    { key: 'absent', label: 'Absent', color: 'text-red-600 dark:text-red-400' },
    { key: 'excused', label: 'Excused', color: 'text-blue-600 dark:text-blue-400' },
    { key: 'late', label: 'Late', color: 'text-amber-600 dark:text-amber-400' },
] as const

// ── Date navigation ────────────────────────────────────────────────────────────

const date = ref(props.selectedDate)

function handleDateChange(newDate: string) {
    date.value = newDate
    router.get(
        '/scheduling/day-center',
        { date: newDate, site_id: props.selectedSite },
        { preserveState: true, replace: true },
    )
}

// ── Mark Present ──────────────────────────────────────────────────────────────

const savingId = ref<number | null>(null)

async function markPresent(participantId: number) {
    savingId.value = participantId
    try {
        await axios.post('/scheduling/day-center/check-in', {
            participant_id: participantId,
            site_id: props.selectedSite,
            attendance_date: date.value,
            status: 'present',
        })
        router.reload({ only: ['attendance', 'summary'] })
    } finally {
        savingId.value = null
    }
}

// ── Absence modal state ────────────────────────────────────────────────────────

const absentFor = ref<number | null>(null)
const absentStatus = ref<'absent' | 'excused'>('absent')
const absentReason = ref('')
const absentNotes = ref('')
const absentSaving = ref(false)
const absentError = ref('')

function openAbsentModal(participantId: number) {
    absentFor.value = participantId
    absentStatus.value = 'absent'
    absentReason.value = ''
    absentNotes.value = ''
    absentError.value = ''
}

function closeAbsentModal() {
    absentFor.value = null
    absentError.value = ''
}

async function submitAbsence() {
    if (!absentReason.value) {
        absentError.value = 'Please select a reason.'
        return
    }
    absentSaving.value = true
    absentError.value = ''
    try {
        await axios.post('/scheduling/day-center/absent', {
            participant_id: absentFor.value,
            site_id: props.selectedSite,
            attendance_date: date.value,
            status: absentStatus.value,
            absent_reason: absentReason.value,
            notes: absentNotes.value || null,
        })
        absentFor.value = null
        router.reload({ only: ['attendance', 'summary'] })
    } catch {
        absentError.value = 'Failed to save. Please try again.'
    } finally {
        absentSaving.value = false
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatDateLong(dateStr: string): string {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    })
}
</script>

<template>
    <AppShell>
        <Head title="Day Center Attendance" />

        <div class="px-6 py-6 space-y-5">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-slate-100">
                        Day Center Attendance
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                        Track participant check-ins and absences for the day center.
                    </p>
                </div>

                <!-- Date picker -->
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-600 dark:text-slate-400 font-medium"
                        >Date:</label
                    >
                    <input
                        type="date"
                        :value="date"
                        class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 dark:bg-slate-700"
                        @change="handleDateChange(($event.target as HTMLInputElement).value)"
                    />
                </div>
            </div>

            <!-- Summary cards -->
            <div class="grid grid-cols-4 gap-3">
                <div
                    v-for="item in summaryItems"
                    :key="item.key"
                    class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 text-center shadow-sm"
                >
                    <p :class="['text-2xl font-bold', item.color]">
                        {{ props.summary[item.key] }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ item.label }}</p>
                </div>
            </div>

            <!-- Attendance table -->
            <div
                class="border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden bg-white dark:bg-slate-800 shadow-sm"
            >
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th
                                v-for="h in [
                                    'Participant',
                                    'MRN',
                                    'Check-In',
                                    'Check-Out',
                                    'Status',
                                    ...(props.canManage ? ['Actions'] : []),
                                ]"
                                :key="h"
                                class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <!-- Empty state -->
                        <tr v-if="props.attendance.length === 0">
                            <td
                                :colspan="props.canManage ? 6 : 5"
                                class="px-4 py-10 text-center text-gray-400 dark:text-slate-500"
                            >
                                No attendance records for this date. Use the roster to start
                                checking participants in.
                            </td>
                        </tr>

                        <!-- Rows -->
                        <tr
                            v-for="record in props.attendance"
                            :key="record.id"
                            class="hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
                        >
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">
                                {{ record.participant.last_name }},
                                {{ record.participant.first_name }}
                            </td>
                            <td
                                class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-slate-400"
                            >
                                {{ record.participant.mrn }}
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                {{ record.check_in_time ?? '-' }}
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                {{ record.check_out_time ?? '-' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    :class="[
                                        'inline-flex px-2 py-0.5 rounded text-xs font-medium',
                                        STATUS_COLORS[record.status] ??
                                            'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300',
                                    ]"
                                >
                                    {{ props.statusLabels[record.status] ?? record.status }}
                                </span>
                                <span
                                    v-if="record.absent_reason"
                                    class="ml-2 text-xs text-gray-400 dark:text-slate-500"
                                >
                                    ({{ record.absent_reason }})
                                </span>
                            </td>
                            <td v-if="props.canManage" class="px-4 py-3">
                                <div class="flex gap-2">
                                    <button
                                        v-if="record.status !== 'present'"
                                        :disabled="savingId === record.participant.id"
                                        class="text-xs px-2.5 py-1 bg-green-600 hover:bg-green-700 text-white rounded disabled:opacity-50"
                                        @click="markPresent(record.participant.id)"
                                    >
                                        Present
                                    </button>
                                    <button
                                        v-if="
                                            record.status !== 'absent' &&
                                            record.status !== 'excused'
                                        "
                                        class="text-xs px-2.5 py-1 bg-red-100 dark:bg-red-900/40 hover:bg-red-200 dark:hover:bg-red-900/60 text-red-700 dark:text-red-300 rounded"
                                        @click="openAbsentModal(record.participant.id)"
                                    >
                                        Absent
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer note -->
            <p class="text-xs text-gray-400 dark:text-slate-500">
                Showing participants with attendance recorded for {{ formatDateLong(date) }}. Use
                the roster endpoint to pre-populate all enrolled participants.
            </p>
        </div>

        <!-- Mark Absence modal -->
        <div
            v-if="absentFor !== null"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeAbsentModal"
        >
            <div
                class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md"
                role="dialog"
                aria-modal="true"
            >
                <!-- Modal header -->
                <div
                    class="px-6 pt-5 pb-4 border-b border-gray-100 dark:border-slate-700 flex items-center justify-between"
                >
                    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">
                        Mark Absence
                    </h2>
                    <button
                        class="text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300"
                        aria-label="Close"
                        @click="closeAbsentModal"
                    >
                        &#x2715;
                    </button>
                </div>

                <!-- Modal body -->
                <div class="px-6 py-5 space-y-4">
                    <!-- Absent / Excused toggle -->
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Type</label
                        >
                        <div class="flex gap-2">
                            <button
                                v-for="s in ['absent', 'excused'] as const"
                                :key="s"
                                :class="[
                                    'flex-1 py-1.5 text-sm rounded-lg border transition-colors',
                                    absentStatus === s
                                        ? 'bg-red-600 text-white border-red-600'
                                        : 'bg-white dark:bg-slate-700 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600',
                                ]"
                                @click="absentStatus = s"
                            >
                                {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                            </button>
                        </div>
                    </div>

                    <!-- Reason select -->
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Reason</label
                        >
                        <select
                            v-model="absentReason"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700"
                            @change="absentError = ''"
                        >
                            <option value="">Select reason...</option>
                            <option
                                v-for="(label, val) in props.absentReasons"
                                :key="val"
                                :value="val"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1"
                        >
                            Notes
                            <span class="text-gray-400 dark:text-slate-500 font-normal"
                                >(optional)</span
                            >
                        </label>
                        <textarea
                            v-model="absentNotes"
                            rows="2"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700"
                        ></textarea>
                    </div>

                    <!-- Inline error -->
                    <p v-if="absentError" class="text-sm text-red-600 dark:text-red-400">
                        {{ absentError }}
                    </p>
                </div>

                <!-- Modal footer -->
                <div
                    class="px-6 py-4 border-t border-gray-100 dark:border-slate-700 flex justify-end gap-2"
                >
                    <button
                        class="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
                        @click="closeAbsentModal"
                    >
                        Cancel
                    </button>
                    <button
                        :disabled="absentSaving"
                        class="px-4 py-2 text-sm bg-red-600 hover:bg-red-700 text-white rounded-lg disabled:opacity-50"
                        @click="submitAbsence"
                    >
                        {{ absentSaving ? 'Saving...' : 'Mark Absent' }}
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
