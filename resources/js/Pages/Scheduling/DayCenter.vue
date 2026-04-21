<!--
  Day Center Attendance — manage daily check-ins and absences for enrolled
  PACE participants attending the day center.

  Loads the full enrolled roster on mount (GET /scheduling/day-center/roster),
  merged with any existing attendance records for the selected date. Staff can
  check in participants (mark present), mark late, or record absences with reasons.

  Route:   GET /scheduling/day-center -> Inertia::render('Scheduling/DayCenter')
  Props:   attendance, summary, selectedDate, selectedSite, statusLabels,
           absentReasons, canManage
-->
<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import {
    CheckCircleIcon,
    XCircleIcon,
    ClockIcon,
    MagnifyingGlassIcon,
    HomeIcon,
} from '@heroicons/vue/24/outline'

// ── Types ─────────────────────────────────────────────────────────────────────

interface AttendanceParticipant {
    id:         number
    mrn:        string
    first_name: string
    last_name:  string
}

interface AttendanceRecord {
    id:             number
    participant:    AttendanceParticipant
    status:         string
    check_in_time:  string | null
    check_out_time: string | null
    absent_reason:  string | null
}

interface Summary {
    total:   number
    present: number
    absent:  number
    excused: number
    late:    number
}

interface RosterEntry {
    id:             number
    mrn:            string
    name:           string
    preferred_name: string | null
    attendance:     string | null   // null = not yet recorded
    source:         'scheduled' | 'appointment' | 'override' | 'cross_site'
    home_site:      { id: number; name: string } | null
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    attendance:    AttendanceRecord[]
    summary:       Summary
    selectedDate:  string
    selectedSite:  number | string
    statusLabels:  Record<string, string>
    absentReasons: Record<string, string>
    canManage:     boolean
}>()

// ── Constants ─────────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, { bg: string; text: string; icon: string }> = {
    present: { bg: 'bg-green-100 dark:bg-green-900/60', text: 'text-green-700 dark:text-green-300', icon: 'text-green-500' },
    absent:  { bg: 'bg-red-100 dark:bg-red-900/60',     text: 'text-red-700 dark:text-red-300',     icon: 'text-red-500' },
    late:    { bg: 'bg-amber-100 dark:bg-amber-900/60',  text: 'text-amber-700 dark:text-amber-300', icon: 'text-amber-500' },
    excused: { bg: 'bg-blue-100 dark:bg-blue-900/60',    text: 'text-blue-700 dark:text-blue-300',   icon: 'text-blue-500' },
}

// ── State ─────────────────────────────────────────────────────────────────────

const date = ref(props.selectedDate)
const roster = ref<RosterEntry[]>([])
const rosterLoading = ref(false)
const search = ref('')
const savingId = ref<number | null>(null)

// ── Summary from props (refreshes on Inertia reload) ──────────────────────────

const summaryItems = computed(() => [
    { key: 'present' as const, label: 'Present', value: props.summary.present, color: 'text-green-600 dark:text-green-400', border: 'border-green-200 dark:border-green-800' },
    { key: 'late' as const,    label: 'Late',    value: props.summary.late,    color: 'text-amber-600 dark:text-amber-400', border: 'border-amber-200 dark:border-amber-800' },
    { key: 'absent' as const,  label: 'Absent',  value: props.summary.absent,  color: 'text-red-600 dark:text-red-400',     border: 'border-red-200 dark:border-red-800' },
    { key: 'excused' as const, label: 'Excused', value: props.summary.excused, color: 'text-blue-600 dark:text-blue-400',   border: 'border-blue-200 dark:border-blue-800' },
])

const rosterTotal = computed(() => roster.value.length)
const rosterCheckedIn = computed(() => roster.value.filter(r => r.attendance === 'present' || r.attendance === 'late').length)
const rosterPending = computed(() => roster.value.filter(r => !r.attendance).length)

// ── Filtered roster ───────────────────────────────────────────────────────────

const filteredRoster = computed(() => {
    if (!search.value.trim()) return roster.value
    const q = search.value.toLowerCase()
    return roster.value.filter(r =>
        r.name.toLowerCase().includes(q) || r.mrn.toLowerCase().includes(q)
    )
})

// ── Load roster ───────────────────────────────────────────────────────────────

async function loadRoster() {
    rosterLoading.value = true
    try {
        const res = await axios.get('/scheduling/day-center/roster', {
            params: { date: date.value, site_id: props.selectedSite },
        })
        roster.value = res.data.roster ?? []
    } catch {
        roster.value = []
    } finally {
        rosterLoading.value = false
    }
}

onMounted(loadRoster)

// ── Date change ───────────────────────────────────────────────────────────────

function handleDateChange(newDate: string) {
    date.value = newDate
    router.get(
        '/scheduling/day-center',
        { date: newDate, site_id: props.selectedSite },
        { preserveState: true, replace: true, onFinish: loadRoster },
    )
}

// ── Check-in actions ──────────────────────────────────────────────────────────

async function markPresent(participantId: number) {
    savingId.value = participantId
    try {
        await axios.post('/scheduling/day-center/check-in', {
            participant_id:  participantId,
            site_id:         props.selectedSite,
            attendance_date: date.value,
            status:          'present',
        })
        // Update local roster state immediately
        const entry = roster.value.find(r => r.id === participantId)
        if (entry) entry.attendance = 'present'
        router.reload({ only: ['attendance', 'summary'] })
    } finally {
        savingId.value = null
    }
}

async function markLate(participantId: number) {
    savingId.value = participantId
    try {
        await axios.post('/scheduling/day-center/check-in', {
            participant_id:  participantId,
            site_id:         props.selectedSite,
            attendance_date: date.value,
            status:          'late',
        })
        const entry = roster.value.find(r => r.id === participantId)
        if (entry) entry.attendance = 'late'
        router.reload({ only: ['attendance', 'summary'] })
    } finally {
        savingId.value = null
    }
}

// ── Absence modal ─────────────────────────────────────────────────────────────

const absentFor    = ref<number | null>(null)
const absentName   = ref('')
const absentStatus = ref<'absent' | 'excused'>('absent')
const absentReason = ref('')
const absentNotes  = ref('')
const absentSaving = ref(false)
const absentError  = ref('')

function openAbsentModal(participantId: number, name: string) {
    absentFor.value    = participantId
    absentName.value   = name
    absentStatus.value = 'absent'
    absentReason.value = ''
    absentNotes.value  = ''
    absentError.value  = ''
}

function closeAbsentModal() {
    absentFor.value = null
}

async function submitAbsence() {
    if (!absentReason.value) { absentError.value = 'Please select a reason.'; return }
    absentSaving.value = true
    absentError.value  = ''
    try {
        await axios.post('/scheduling/day-center/absent', {
            participant_id:  absentFor.value,
            site_id:         props.selectedSite,
            attendance_date: date.value,
            status:          absentStatus.value,
            absent_reason:   absentReason.value,
            notes:           absentNotes.value || null,
        })
        const entry = roster.value.find(r => r.id === absentFor.value)
        if (entry) entry.attendance = absentStatus.value
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
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
    })
}

function statusLabel(status: string | null): string {
    if (!status) return 'Not Recorded'
    return props.statusLabels[status] ?? status
}
</script>

<template>
    <AppShell>
        <Head title="Day Center Attendance" />

        <div class="px-6 py-6 space-y-5">

            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-slate-100">Day Center Attendance</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                        {{ formatDateLong(date) }}
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <a
                        v-if="props.canManage"
                        href="/scheduling/day-center/manage"
                        class="text-sm text-blue-600 dark:text-blue-400 hover:underline"
                    >
                        Manage Schedules
                    </a>
                    <input
                        type="date"
                        :value="date"
                        class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 dark:bg-slate-700"
                        @change="handleDateChange(($event.target as HTMLInputElement).value)"
                    />
                </div>
            </div>

            <!-- Summary KPI cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div
                    v-for="item in summaryItems"
                    :key="item.key"
                    :class="['bg-white dark:bg-slate-800 rounded-xl border px-4 py-3 text-center shadow-sm', item.border]"
                >
                    <p :class="['text-2xl font-bold', item.color]">{{ item.value }}</p>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">{{ item.label }}</p>
                </div>
            </div>

            <!-- Roster progress bar -->
            <div v-if="rosterTotal > 0" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-sm font-medium text-gray-700 dark:text-slate-300">
                        {{ rosterCheckedIn }} of {{ rosterTotal }} checked in
                    </p>
                    <p class="text-sm text-gray-400 dark:text-slate-500">
                        {{ rosterPending }} pending
                    </p>
                </div>
                <div class="w-full bg-gray-200 dark:bg-slate-700 rounded-full h-2.5">
                    <div
                        class="bg-green-500 h-2.5 rounded-full transition-all duration-500"
                        :style="{ width: `${rosterTotal > 0 ? (rosterCheckedIn / rosterTotal) * 100 : 0}%` }"
                    />
                </div>
            </div>

            <!-- Search + roster table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <!-- Search bar -->
                <div class="px-4 py-3 border-b border-gray-200 dark:border-slate-700 flex items-center gap-3">
                    <div class="relative flex-1 max-w-sm">
                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-slate-500" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search by name or MRN..."
                            class="w-full pl-9 pr-3 py-1.5 text-sm border border-gray-300 dark:border-slate-600 rounded-lg dark:bg-slate-700"
                        />
                    </div>
                    <span class="text-sm text-gray-400 dark:text-slate-500">
                        {{ filteredRoster.length }} participants
                    </span>
                </div>

                <!-- Loading state -->
                <div v-if="rosterLoading" class="px-4 py-10 text-center">
                    <div class="inline-block w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                    <p class="text-sm text-gray-400 dark:text-slate-500 mt-2">Loading roster...</p>
                </div>

                <!-- Roster table -->
                <table v-else-if="filteredRoster.length > 0" class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Participant</th>
                            <th class="px-4 py-2.5 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">MRN</th>
                            <th class="px-4 py-2.5 text-center text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Status</th>
                            <th v-if="props.canManage" class="px-4 py-2.5 text-right text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr
                            v-for="entry in filteredRoster"
                            :key="entry.id"
                            :class="[
                                'transition-colors',
                                entry.attendance === 'present' || entry.attendance === 'late'
                                    ? 'bg-green-50/50 dark:bg-green-950/20'
                                    : entry.attendance === 'absent' || entry.attendance === 'excused'
                                        ? 'bg-red-50/30 dark:bg-red-950/10'
                                        : 'hover:bg-gray-50 dark:hover:bg-slate-700/50',
                            ]"
                        >
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">
                                <div class="flex items-center gap-2">
                                    <span>
                                        {{ entry.name }}
                                        <span v-if="entry.preferred_name" class="text-gray-400 dark:text-slate-500 text-sm ml-1">({{ entry.preferred_name }})</span>
                                    </span>
                                    <span
                                        v-if="entry.source === 'cross_site' && entry.home_site"
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-sm font-medium bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300"
                                        title="Cross-site visitor — enrolled at a different PACE site"
                                    >
                                        <HomeIcon class="w-3 h-3" />
                                        Home: {{ entry.home_site.name }}
                                    </span>
                                    <span
                                        v-else-if="entry.source === 'appointment'"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded text-sm font-medium bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300"
                                        title="Added by appointment (outside recurring schedule)"
                                    >
                                        Appt
                                    </span>
                                    <span
                                        v-else-if="entry.source === 'override'"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded text-sm font-medium bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300"
                                        title="Attendance recorded outside normal schedule"
                                    >
                                        Override
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 font-mono text-sm text-gray-600 dark:text-slate-400">{{ entry.mrn }}</td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    v-if="entry.attendance"
                                    :class="[
                                        'inline-flex items-center gap-1 px-2 py-0.5 rounded text-sm font-medium',
                                        STATUS_COLORS[entry.attendance]?.bg ?? 'bg-gray-100 dark:bg-slate-700',
                                        STATUS_COLORS[entry.attendance]?.text ?? 'text-gray-700 dark:text-slate-300',
                                    ]"
                                >
                                    <CheckCircleIcon v-if="entry.attendance === 'present'" class="w-3.5 h-3.5" />
                                    <ClockIcon v-else-if="entry.attendance === 'late'" class="w-3.5 h-3.5" />
                                    <XCircleIcon v-else class="w-3.5 h-3.5" />
                                    {{ statusLabel(entry.attendance) }}
                                </span>
                                <span v-else class="text-sm text-gray-400 dark:text-slate-500">-</span>
                            </td>
                            <td v-if="props.canManage" class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <template v-if="!entry.attendance">
                                        <button
                                            :disabled="savingId === entry.id"
                                            class="px-3 py-1 text-sm bg-green-600 hover:bg-green-700 text-white rounded-lg disabled:opacity-50 font-medium"
                                            @click="markPresent(entry.id)"
                                        >
                                            {{ savingId === entry.id ? '...' : 'Check In' }}
                                        </button>
                                        <button
                                            :disabled="savingId === entry.id"
                                            class="px-3 py-1 text-sm bg-amber-100 dark:bg-amber-900/40 hover:bg-amber-200 dark:hover:bg-amber-900/60 text-amber-700 dark:text-amber-300 rounded-lg disabled:opacity-50 font-medium"
                                            @click="markLate(entry.id)"
                                        >
                                            Late
                                        </button>
                                        <button
                                            class="px-3 py-1 text-sm bg-red-100 dark:bg-red-900/40 hover:bg-red-200 dark:hover:bg-red-900/60 text-red-700 dark:text-red-300 rounded-lg font-medium"
                                            @click="openAbsentModal(entry.id, entry.name)"
                                        >
                                            Absent
                                        </button>
                                    </template>
                                    <template v-else-if="entry.attendance === 'present' || entry.attendance === 'late'">
                                        <span class="text-sm text-green-600 dark:text-green-400 font-medium flex items-center gap-1">
                                            <CheckCircleIcon class="w-4 h-4" /> Checked in
                                        </span>
                                    </template>
                                    <template v-else>
                                        <button
                                            :disabled="savingId === entry.id"
                                            class="px-3 py-1 text-sm bg-green-600 hover:bg-green-700 text-white rounded-lg disabled:opacity-50 font-medium"
                                            @click="markPresent(entry.id)"
                                        >
                                            Override - Check In
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Empty state -->
                <div v-else class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                    <p class="text-sm">No enrolled participants found for this site.</p>
                </div>
            </div>
        </div>

        <!-- Mark Absence modal -->
        <div
            v-if="absentFor !== null"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeAbsentModal"
        >
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md" role="dialog" aria-modal="true">
                <div class="px-6 pt-5 pb-4 border-b border-gray-100 dark:border-slate-700 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Mark Absence</h2>
                        <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">{{ absentName }}</p>
                    </div>
                    <button class="text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300" aria-label="Close" @click="closeAbsentModal">&#x2715;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Type</label>
                        <div class="flex gap-2">
                            <button
                                v-for="s in (['absent', 'excused'] as const)"
                                :key="s"
                                :class="[
                                    'flex-1 py-1.5 text-sm rounded-lg border transition-colors font-medium',
                                    absentStatus === s
                                        ? s === 'absent' ? 'bg-red-600 text-white border-red-600' : 'bg-blue-600 text-white border-blue-600'
                                        : 'bg-white dark:bg-slate-700 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600',
                                ]"
                                @click="absentStatus = s"
                            >
                                {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Reason</label>
                        <select name="absentReason" v-model="absentReason" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700" @change="absentError = ''">
                            <option value="">Select reason...</option>
                            <option v-for="(label, val) in props.absentReasons" :key="val" :value="val">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Notes <span class="text-gray-400 dark:text-slate-500 font-normal">(optional)</span></label>
                        <textarea v-model="absentNotes" rows="2" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700" />
                    </div>
                    <p v-if="absentError" class="text-sm text-red-600 dark:text-red-400">{{ absentError }}</p>
                </div>
                <div class="px-6 py-4 border-t border-gray-100 dark:border-slate-700 flex justify-end gap-2">
                    <button class="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200" @click="closeAbsentModal">Cancel</button>
                    <button :disabled="absentSaving" class="px-4 py-2 text-sm bg-red-600 hover:bg-red-700 text-white rounded-lg disabled:opacity-50 font-medium" @click="submitAbsence">
                        {{ absentSaving ? 'Saving...' : 'Confirm' }}
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
