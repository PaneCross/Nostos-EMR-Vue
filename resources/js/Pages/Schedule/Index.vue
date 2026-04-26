<!--
  Schedule/Index — Week-view appointment calendar.

  Shows a 7-column grid (Sunday through Saturday) with hour rows from 8 AM to 6 PM.
  Each appointment is rendered as a color-coded block positioned by its UTC start/end
  time. Overlapping appointments in the same day column are laid out side by side.

  A red indicator line marks the current time (updated every 60s) on today's column.

  Clicking an appointment block opens a slide-over detail panel on the right.
  The "New Appointment" button opens a two-step booking modal:
    Step 1 — participant typeahead search (debounced, min 2 chars)
    Step 2 — appointment details form (type, start/end, location, transport, notes)

  Appointments are loaded client-side via GET /schedule/appointments?start_date=&end_date=
  on mount and whenever the week or filter changes. The initial Inertia response only
  carries static props (types, labels, colors, locations) to keep the page load fast.

  Route:  GET /schedule -> Inertia::render('Schedule/Index')
  Props:  appointmentTypes, typeLabels, typeColors, locations
-->
<script setup lang="ts">
// ─── Schedule/Index ─────────────────────────────────────────────────────────
// Org-wide week-view appointment calendar — clinical visits, IDT meetings,
// transport pickups, etc. New-appointment modal includes participant
// typeahead + transport-need flag.
//
// Audience: Front-desk + every department that books appointments.
//
// Notable rules:
//   - Appointments store UTC datetimes; display time renders in the
//     tenant's configured timezone (set in ItAdmin/SystemSettings).
//   - Transport-flagged appointments cascade to the Transport manifest
//     for that date (Transport/Manifest.vue).
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import LocationCombobox from '@/Components/LocationCombobox.vue'
import { TruckIcon, ExclamationTriangleIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

// ── Types ─────────────────────────────────────────────────────────────────────

interface LocationSummary {
  id: number
  name: string
  location_type: string
  site_id: number | null
  city?: string | null
}

interface ParticipantSummary {
  id: number
  mrn: string
  name?: string          // Full name from search endpoint
  first_name?: string    // When passed from other sources
  last_name?: string
  dob?: string
  age?: number
  enrollment_status?: string
  site_id?: number | null
  site_name?: string | null
}

interface ProviderSummary {
  id: number
  first_name: string
  last_name: string
}

interface AppointmentItem {
  id: number
  appointment_type: string
  scheduled_start: string
  scheduled_end: string
  status: 'scheduled' | 'confirmed' | 'completed' | 'cancelled' | 'no_show'
  transport_required: boolean
  notes: string | null
  cancellation_reason: string | null
  participant: ParticipantSummary | null
  provider: ProviderSummary | null
  location: LocationSummary | null
}

interface AppointmentWithLayout extends AppointmentItem {
  colIndex: number
  colCount: number
}

const props = defineProps<{
  appointmentTypes: string[]
  typeLabels: Record<string, string>
  typeColors: Record<string, string>
  locations: LocationSummary[]
}>()

// ── Constants ─────────────────────────────────────────────────────────────────

// Map Tailwind color names to static class strings (Tailwind purging requires static strings)
const COLOR_CLASS_MAP: Record<string, { bg: string; border: string; text: string }> = {
  blue:    { bg: 'bg-blue-100 dark:bg-blue-900/50',      border: 'border-blue-400 dark:border-blue-600',      text: 'text-blue-800 dark:text-blue-200' },
  green:   { bg: 'bg-green-100 dark:bg-green-800/50',     border: 'border-green-500 dark:border-green-500',     text: 'text-green-800 dark:text-green-200' },
  emerald: { bg: 'bg-emerald-100 dark:bg-emerald-800/50', border: 'border-emerald-500 dark:border-emerald-500', text: 'text-emerald-800 dark:text-emerald-200' },
  teal:    { bg: 'bg-teal-100 dark:bg-teal-800/50',      border: 'border-teal-400 dark:border-teal-500',      text: 'text-teal-800 dark:text-teal-200' },
  purple:  { bg: 'bg-purple-100 dark:bg-purple-800/50',   border: 'border-purple-400 dark:border-purple-500',   text: 'text-purple-800 dark:text-purple-200' },
  violet:  { bg: 'bg-violet-100 dark:bg-violet-800/50',   border: 'border-violet-400 dark:border-violet-500',   text: 'text-violet-800 dark:text-violet-200' },
  orange:  { bg: 'bg-orange-100 dark:bg-orange-800/50',   border: 'border-orange-400 dark:border-orange-500',   text: 'text-orange-800 dark:text-orange-200' },
  amber:   { bg: 'bg-amber-100 dark:bg-amber-800/50',     border: 'border-amber-400 dark:border-amber-500',     text: 'text-amber-800 dark:text-amber-200' },
  rose:    { bg: 'bg-rose-100 dark:bg-rose-800/50',       border: 'border-rose-400 dark:border-rose-500',       text: 'text-rose-800 dark:text-rose-200' },
  pink:    { bg: 'bg-pink-100 dark:bg-pink-800/50',       border: 'border-pink-400 dark:border-pink-500',       text: 'text-pink-800 dark:text-pink-200' },
  slate:   { bg: 'bg-slate-100 dark:bg-slate-700',        border: 'border-slate-400 dark:border-slate-500',     text: 'text-slate-800 dark:text-slate-200' },
  gray:    { bg: 'bg-gray-100 dark:bg-slate-700',         border: 'border-gray-400 dark:border-slate-500',      text: 'text-gray-800 dark:text-slate-200' },
  indigo:  { bg: 'bg-indigo-100 dark:bg-indigo-800/50',   border: 'border-indigo-400 dark:border-indigo-500',   text: 'text-indigo-800 dark:text-indigo-200' },
  cyan:    { bg: 'bg-cyan-100 dark:bg-cyan-800/50',       border: 'border-cyan-400 dark:border-cyan-500',       text: 'text-cyan-800 dark:text-cyan-200' },
  lime:    { bg: 'bg-lime-100 dark:bg-lime-800/50',       border: 'border-lime-400 dark:border-lime-500',       text: 'text-lime-800 dark:text-lime-200' },
}

const STATUS_LABELS: Record<string, string> = {
  scheduled: 'Scheduled',
  confirmed:  'Confirmed',
  completed:  'Completed',
  cancelled:  'Cancelled',
  no_show:    'No Show',
}

const STATUS_COLORS: Record<string, string> = {
  scheduled: 'bg-blue-50 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
  confirmed:  'bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-200',
  completed:  'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300',
  cancelled:  'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-300',
  no_show:    'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
}

const CANCEL_REASONS = [
  'Participant declined',
  'Participant hospitalized',
  'Provider unavailable',
  'Participant requested reschedule',
  'Weather cancellation',
  'Other',
]

const HOUR_START = 8   // 8 AM
const HOUR_END   = 18  // 6 PM
const HOURS      = Array.from({ length: HOUR_END - HOUR_START }, (_, i) => HOUR_START + i)
const DAY_NAMES  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

// ── Helper functions ──────────────────────────────────────────────────────────

function getWeekStart(date: Date): Date {
  const d = new Date(date)
  d.setDate(d.getDate() - d.getDay())
  d.setHours(0, 0, 0, 0)
  return d
}

function addDays(date: Date, days: number): Date {
  const d = new Date(date)
  d.setDate(d.getDate() + days)
  return d
}

function formatDateParam(date: Date): string {
  return date.toISOString().split('T')[0]
}

function formatDisplayDate(date: Date): string {
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

// Calculate top/height percentage for an appointment block within the day column.
// Uses getUTCHours/getUTCMinutes because times are stored as "local wall-clock in UTC"
// (e.g. a 10 AM appointment is stored as "10:00Z" so getUTCHours() returns 10).
function getTimePosition(start: Date, end: Date): { top: number; height: number } {
  const totalMinutes = (HOUR_END - HOUR_START) * 60
  const startMinutes = (start.getUTCHours() - HOUR_START) * 60 + start.getUTCMinutes()
  const endMinutes   = (end.getUTCHours()   - HOUR_START) * 60 + end.getUTCMinutes()
  const clampedStart = Math.max(0, startMinutes)
  const clampedEnd   = Math.min(totalMinutes, endMinutes)
  return {
    top:    (clampedStart / totalMinutes) * 100,
    height: Math.max(2, ((clampedEnd - clampedStart) / totalMinutes) * 100),
  }
}

// Assign overlapping appointments to side-by-side columns within the same day.
// Returns each appointment annotated with colIndex and colCount so blocks
// can render at the correct horizontal position and width.
function layoutDayAppointments(appts: AppointmentItem[]): AppointmentWithLayout[] {
  const sorted = [...appts].sort(
    (a, b) => new Date(a.scheduled_start).getTime() - new Date(b.scheduled_start).getTime(),
  )
  const colEnds: Date[] = []
  const withCol = sorted.map(appt => {
    const start = new Date(appt.scheduled_start)
    const end   = new Date(appt.scheduled_end)
    let col = colEnds.findIndex(colEnd => colEnd <= start)
    if (col === -1) { col = colEnds.length; colEnds.push(end) }
    else { colEnds[col] = end }
    return { ...appt, colIndex: col, colCount: 0 }
  })
  return withCol.map(appt => {
    const s = new Date(appt.scheduled_start)
    const e = new Date(appt.scheduled_end)
    let maxCol = appt.colIndex
    withCol.forEach(other => {
      if (new Date(other.scheduled_start) < e && new Date(other.scheduled_end) > s) {
        maxCol = Math.max(maxCol, other.colIndex)
      }
    })
    return { ...appt, colCount: maxCol + 1 }
  })
}

function hourLabel(h: number): string {
  if (h === 12) return '12 PM'
  return h > 12 ? `${h - 12} PM` : `${h} AM`
}

// ── Calendar state ────────────────────────────────────────────────────────────

const weekStart    = ref<Date>(getWeekStart(new Date()))
const appointments = ref<AppointmentItem[]>([])
const loading      = ref(false)
// Multi-select type filter. selectedTypes holds the types to SHOW.
// Defaults to all types — each type is a checkbox; uncheck to hide that type.
const selectedTypes    = ref<Set<string>>(new Set(props.appointmentTypes))
const typeFilterOpen   = ref(false)
const typeFilterRootEl = ref<HTMLElement | null>(null)

function toggleType(t: string) {
    const next = new Set(selectedTypes.value)
    if (next.has(t)) next.delete(t)
    else             next.add(t)
    selectedTypes.value = next
}
function selectAllTypes() { selectedTypes.value = new Set(props.appointmentTypes) }
function clearAllTypes()  { selectedTypes.value = new Set() }

const allTypesSelected = computed(() => selectedTypes.value.size === props.appointmentTypes.length)
const noTypesSelected  = computed(() => selectedTypes.value.size === 0)

// Label shown on the filter button
const typeFilterLabel = computed(() => {
    if (allTypesSelected.value) return 'All Types'
    if (noTypesSelected.value)  return 'No Types'
    const n = selectedTypes.value.size
    if (n === 1) {
        const t = [...selectedTypes.value][0]
        return props.typeLabels[t] ?? t
    }
    return `${n} selected`
})

function isTypeShown(type: string): boolean {
    return selectedTypes.value.has(type)
}

// Click-outside to close the filter dropdown
function onDocClickForTypeFilter(e: MouseEvent) {
    if (!typeFilterRootEl.value) return
    if (!typeFilterRootEl.value.contains(e.target as Node)) {
        typeFilterOpen.value = false
    }
}
watch(typeFilterOpen, (o) => {
    if (o) document.addEventListener('mousedown', onDocClickForTypeFilter)
    else   document.removeEventListener('mousedown', onDocClickForTypeFilter)
})

const weekDays = computed(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart.value, i)))
const weekEnd  = computed(() => addDays(weekStart.value, 6))
const today    = formatDateParam(new Date())

function appointmentsForDay(day: Date): AppointmentItem[] {
  const dayStr = formatDateParam(day)
  return appointments.value.filter(a =>
    a.scheduled_start.startsWith(dayStr) && isTypeShown(a.appointment_type),
  )
}

async function fetchAppointments() {
  loading.value = true
  try {
    const res = await axios.get('/schedule/appointments', {
      params: {
        start_date: formatDateParam(weekStart.value),
        end_date:   formatDateParam(weekEnd.value),
      },
    })
    appointments.value = res.data
  } catch {
    // Non-blocking
  } finally {
    loading.value = false
  }
}

watch(weekStart, fetchAppointments)

function prevWeek() { weekStart.value = addDays(weekStart.value, -7) }
function nextWeek() { weekStart.value = addDays(weekStart.value, 7) }
function goToday()  { weekStart.value = getWeekStart(new Date()) }

// ── "Now" line state ──────────────────────────────────────────────────────────
// Uses getHours()/getMinutes() (LOCAL) for the position of the indicator line
// because appointment times are stored as "local time expressed in UTC", so
// getUTCHours() on stored datetimes equals the intended local hour. The live
// "now" Date must also use local hours to land on the same visual axis.

const now        = ref(new Date())
const nowPct     = computed(() => {
  const mins = (now.value.getHours() - HOUR_START) * 60 + now.value.getMinutes()
  return (mins / ((HOUR_END - HOUR_START) * 60)) * 100
})
const showNowLine = computed(() =>
  now.value.getHours() >= HOUR_START && now.value.getHours() < HOUR_END,
)

let nowTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  fetchAppointments()
  nowTimer = setInterval(() => { now.value = new Date() }, 60_000)
})

onUnmounted(() => {
  if (nowTimer) clearInterval(nowTimer)
})

// ── Detail panel state ────────────────────────────────────────────────────────

const selectedAppt  = ref<AppointmentItem | null>(null)
const panelCancelling = ref(false)
const panelCancelReason = ref('')
const panelSaving   = ref(false)
const panelError    = ref('')

function openDetail(appt: AppointmentItem) {
  selectedAppt.value    = appt
  panelCancelling.value = false
  panelCancelReason.value = ''
  panelSaving.value     = false
  panelError.value      = ''
}

function closeDetail() { selectedAppt.value = null }

function handleStatusChange(updated: AppointmentItem) {
  appointments.value = appointments.value.map(a => a.id === updated.id ? updated : a)
  selectedAppt.value = updated
  panelCancelling.value = false
}

async function markComplete() {
  const appt = selectedAppt.value
  if (!appt?.participant) return
  panelSaving.value = true; panelError.value = ''
  try {
    const res = await axios.patch(`/participants/${appt.participant.id}/appointments/${appt.id}/complete`)
    handleStatusChange(res.data)
  } catch {
    panelError.value = 'Could not complete appointment.'
  } finally {
    panelSaving.value = false
  }
}

async function markNoShow() {
  const appt = selectedAppt.value
  if (!appt?.participant) return
  panelSaving.value = true; panelError.value = ''
  try {
    const res = await axios.patch(`/participants/${appt.participant.id}/appointments/${appt.id}/no-show`)
    handleStatusChange(res.data)
  } catch {
    panelError.value = 'Could not mark no-show.'
  } finally {
    panelSaving.value = false
  }
}

async function submitCancel() {
  const appt = selectedAppt.value
  if (!appt?.participant || !panelCancelReason.value.trim()) return
  panelSaving.value = true; panelError.value = ''
  try {
    const res = await axios.patch(
      `/participants/${appt.participant.id}/appointments/${appt.id}/cancel`,
      { cancellation_reason: panelCancelReason.value },
    )
    handleStatusChange(res.data)
  } catch {
    panelError.value = 'Could not cancel appointment.'
  } finally {
    panelSaving.value = false
  }
}

// ── Booking modal state ───────────────────────────────────────────────────────

const showBooking          = ref(false)
const bookingStep          = ref<1 | 2>(1)
const participantSearch    = ref('')
const participantResults   = ref<ParticipantSummary[]>([])
const selectedParticipant  = ref<ParticipantSummary | null>(null)
const bookingForm          = ref({
  appointment_type:   props.appointmentTypes[0] || 'clinic_visit',
  scheduled_start:    `${today}T09:00`,
  scheduled_end:      `${today}T10:00`,
  location_id:        '',
  transport_required: false,
  notes:              '',
})
const bookingSaving = ref(false)
const bookingError  = ref('')

let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(participantSearch, (q) => {
  if (searchTimer) clearTimeout(searchTimer)
  if (q.length < 2) { participantResults.value = []; return }
  searchTimer = setTimeout(async () => {
    try {
      const res = await axios.get('/participants/search', { params: { q } })
      participantResults.value = res.data
    } catch {
      participantResults.value = []
    }
  }, 300)
})

function selectParticipant(p: ParticipantSummary) {
  selectedParticipant.value = p
  bookingStep.value = 2
}

function resetBooking() {
  bookingStep.value         = 1
  participantSearch.value   = ''
  participantResults.value  = []
  selectedParticipant.value = null
  bookingSaving.value       = false
  bookingError.value        = ''
}

function openBooking() { resetBooking(); showBooking.value = true }
function closeBooking() {
  showBooking.value = false
  showCrossSiteConfirm.value = false
  crossSiteConfirmed.value = false
}

// ── Click-outside guard for the booking modal ────────────────────────────────
// Instead of closing on outside clicks (which loses in-progress form data),
// shake the modal + flash the X button to guide the user to close deliberately.
const modalShaking = ref(false)
const closeBtnFlashing = ref(false)

function handleBackdropClick() {
    modalShaking.value = true
    closeBtnFlashing.value = true
    setTimeout(() => { modalShaking.value = false }, 500)
    setTimeout(() => { closeBtnFlashing.value = false }, 1400)
}

// ── Cross-site detection ─────────────────────────────────────────────────────
const showCrossSiteConfirm = ref(false)
const crossSiteConfirmed   = ref(false)

const crossSiteInfo = computed(() => {
  const p = selectedParticipant.value
  if (!p?.site_id) return null
  const locId = Number(bookingForm.value.location_id)
  if (!locId) return null
  const loc = props.locations.find(l => l.id === locId)
  if (!loc?.site_id) return null
  if (loc.site_id === p.site_id) return null
  return {
    participantName: p.name || `${p.first_name ?? ''} ${p.last_name ?? ''}`.trim() || 'Participant',
    homeSiteName:    p.site_name ?? 'Home site',
    hostSiteName:    loc.name,
  }
})

async function submitBooking() {
  if (!selectedParticipant.value) { bookingError.value = 'Please select a participant first.'; return }

  // Cross-site interstitial — user must explicitly confirm before the POST fires.
  if (crossSiteInfo.value && !crossSiteConfirmed.value) {
    showCrossSiteConfirm.value = true
    return
  }

  bookingSaving.value = true; bookingError.value = ''
  try {
    const payload = { ...bookingForm.value } as any
    if (crossSiteInfo.value) payload.cross_site_confirmed = true
    const res = await axios.post(
      `/participants/${selectedParticipant.value.id}/appointments`,
      payload,
    )
    appointments.value = [...appointments.value, res.data]
    closeBooking()
  } catch (err: any) {
    bookingError.value = err.response?.status === 409
      ? err.response.data.message
      : 'Failed to create appointment. Please check all fields.'
    bookingSaving.value = false
  }
}
</script>

<template>
  <AppShell>
    <Head title="Schedule" />

    <div class="flex flex-col h-full">

    <!-- Page header -->
    <div class="flex-shrink-0 flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
      <div class="flex items-center gap-3">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Schedule</h1>
        <span class="text-sm text-gray-500 dark:text-slate-400">
          {{ formatDisplayDate(weekStart) }} - {{ formatDisplayDate(weekEnd) }}
        </span>
        <span v-if="loading" class="text-xs text-gray-400 dark:text-slate-500 animate-pulse">Loading...</span>
      </div>

      <div class="flex items-center gap-2">
        <!-- Week navigation -->
        <button
          class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 bg-white dark:bg-slate-800"
          @click="prevWeek"
        >
          &lsaquo; Prev
        </button>
        <button
          class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 bg-white dark:bg-slate-800"
          @click="goToday"
        >
          Today
        </button>
        <button
          class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 bg-white dark:bg-slate-800"
          @click="nextWeek"
        >
          Next &rsaquo;
        </button>

        <!-- Appointment type multi-select filter -->
        <div ref="typeFilterRootEl" class="relative">
          <button
            type="button"
            class="inline-flex items-center gap-2 border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 min-w-[9rem] justify-between"
            @click="typeFilterOpen = !typeFilterOpen"
          >
            <span class="truncate">{{ typeFilterLabel }}</span>
            <ChevronDownIcon class="w-4 h-4 text-gray-400 dark:text-slate-500 shrink-0" />
          </button>

          <div
            v-if="typeFilterOpen"
            class="absolute right-0 top-full mt-1 z-30 w-72 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg overflow-hidden"
          >
            <!-- Actions -->
            <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
              <span class="text-sm font-semibold text-gray-700 dark:text-slate-300">Appointment Types</span>
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  class="text-sm text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-40 disabled:no-underline"
                  :disabled="allTypesSelected"
                  @click="selectAllTypes"
                >
                  Select all
                </button>
                <span class="text-gray-300 dark:text-slate-600">·</span>
                <button
                  type="button"
                  class="text-sm text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-40 disabled:no-underline"
                  :disabled="noTypesSelected"
                  @click="clearAllTypes"
                >
                  Clear
                </button>
              </div>
            </div>

            <!-- Checklist -->
            <ul class="max-h-[36rem] overflow-y-auto py-1">
              <li v-for="t in props.appointmentTypes" :key="t">
                <label
                  class="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700 text-gray-900 dark:text-slate-100"
                >
                  <input
                    type="checkbox"
                    :checked="selectedTypes.has(t)"
                    class="rounded border-gray-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500"
                    @change="toggleType(t)"
                  />
                  <span
                    class="inline-block w-2.5 h-2.5 rounded-sm border shrink-0"
                    :class="[
                      (COLOR_CLASS_MAP[props.typeColors[t]] ?? COLOR_CLASS_MAP.gray).bg,
                      (COLOR_CLASS_MAP[props.typeColors[t]] ?? COLOR_CLASS_MAP.gray).border,
                    ]"
                  />
                  <span class="truncate flex-1">{{ props.typeLabels[t] || t }}</span>
                </label>
              </li>
            </ul>
          </div>
        </div>

        <!-- New Appointment -->
        <button
          class="ml-2 px-4 py-1.5 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700"
          @click="openBooking"
        >
          + New Appointment
        </button>
      </div>
    </div>

    <!-- Calendar + detail panel wrapper -->
    <div class="flex flex-1 min-h-0 overflow-hidden">

    <!-- Calendar grid (squeezes when detail panel is open) -->
    <div class="flex flex-1 min-w-0 overflow-hidden">

      <!-- Time gutter -->
      <div class="w-14 flex-shrink-0 border-r border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800/60">
        <!-- Spacer to align with day header -->
        <div class="h-12 border-b border-gray-200 dark:border-slate-700" />
        <!-- Hour labels -->
        <div
          v-for="h in HOURS"
          :key="h"
          class="h-16 border-b border-gray-100 dark:border-slate-700/50 flex items-start justify-end pr-2 pt-1"
        >
          <span class="text-xs text-gray-500 dark:text-slate-400 font-medium">{{ hourLabel(h) }}</span>
        </div>
      </div>

      <!-- Day columns -->
      <div class="flex-1 overflow-x-auto overflow-y-auto">
        <div class="flex min-w-max">
          <div
            v-for="(day, dayIdx) in weekDays"
            :key="dayIdx"
            class="flex-1 min-w-[140px] border-r border-gray-200 dark:border-slate-700 last:border-r-0"
          >
            <!-- Day header -->
            <div
              :class="[
                'h-12 border-b border-gray-200 dark:border-slate-700 flex flex-col items-center justify-center sticky top-0 z-10',
                formatDateParam(day) === today
                  ? 'bg-blue-50 dark:bg-blue-900/30'
                  : 'bg-white dark:bg-slate-800',
              ]"
            >
              <span
                :class="[
                  'text-xs font-medium',
                  formatDateParam(day) === today
                    ? 'text-blue-600 dark:text-blue-400'
                    : 'text-gray-500 dark:text-slate-400',
                ]"
              >
                {{ DAY_NAMES[day.getDay()] }}
              </span>
              <span
                :class="[
                  'text-lg font-bold',
                  formatDateParam(day) === today
                    ? 'text-blue-700 dark:text-blue-300'
                    : 'text-gray-900 dark:text-slate-100',
                ]"
              >
                {{ day.getDate() }}
              </span>
            </div>

            <!-- Hour rows + appointments -->
            <div
              :class="[
                'relative',
                formatDateParam(day) === today ? 'bg-blue-50/60 dark:bg-blue-900/10' : '',
              ]"
              :style="{ height: `${HOURS.length * 64}px` }"
            >
              <!-- Hour grid lines -->
              <div
                v-for="(h, hi) in HOURS"
                :key="h"
                class="absolute w-full border-b border-gray-100 dark:border-slate-700/50"
                :style="{ top: `${hi * 64}px`, height: '64px' }"
              />

              <!-- Current time indicator — red line on today's column only.
                   pointer-events-none so it never blocks appointment clicks. -->
              <div
                v-if="formatDateParam(day) === today && showNowLine"
                class="absolute left-0 right-0 z-20 pointer-events-none"
                :style="{ top: `${nowPct}%` }"
              >
                <div class="w-full border-t-2 border-red-400" />
                <div
                  class="absolute rounded-full bg-red-500 border-2 border-white dark:border-slate-800"
                  style="width: 10px; height: 10px; top: -6px; left: 0"
                />
              </div>

              <!-- Appointment blocks — laid out side-by-side for overlapping slots -->
              <button
                v-for="appt in layoutDayAppointments(appointmentsForDay(day))"
                :key="appt.id"
                :class="[
                  'absolute rounded px-1 py-0.5 text-left border transition-opacity hover:opacity-90',
                  appt.status === 'cancelled' || appt.status === 'no_show' ? 'opacity-40 line-through' : '',
                  (COLOR_CLASS_MAP[props.typeColors[appt.appointment_type]] ?? COLOR_CLASS_MAP.gray).bg,
                  (COLOR_CLASS_MAP[props.typeColors[appt.appointment_type]] ?? COLOR_CLASS_MAP.gray).border,
                  (COLOR_CLASS_MAP[props.typeColors[appt.appointment_type]] ?? COLOR_CLASS_MAP.gray).text,
                ]"
                :style="{
                  top:       `${getTimePosition(new Date(appt.scheduled_start), new Date(appt.scheduled_end)).top}%`,
                  height:    `${getTimePosition(new Date(appt.scheduled_start), new Date(appt.scheduled_end)).height}%`,
                  minHeight: '20px',
                  left:      `calc(${(appt.colIndex / appt.colCount) * 100}% + 2px)`,
                  width:     `calc(${(1 / appt.colCount) * 100}% - 4px)`,
                }"
                @click="openDetail(appt)"
              >
                <p class="text-xs font-semibold leading-tight truncate">
                  {{ props.typeLabels[appt.appointment_type] || appt.appointment_type }}
                </p>
                <p v-if="appt.participant" class="text-xs leading-tight truncate opacity-80">
                  {{ appt.participant.first_name }} {{ appt.participant.last_name }}
                </p>
                <TruckIcon v-if="appt.transport_required" class="w-3 h-3" aria-hidden="true" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </div><!-- end calendar grid -->

    <!-- Appointment detail slide panel (inline, squeezes calendar) -->
    <div
      :class="[
        'flex-shrink-0 transition-[width] duration-300 ease-in-out overflow-hidden border-l border-gray-200 dark:border-slate-700',
        selectedAppt ? 'w-96' : 'w-0 border-l-0',
      ]"
    >
    <div
      v-if="selectedAppt"
      class="w-96 h-full bg-white dark:bg-slate-800 shadow-lg flex flex-col"
    >
      <!-- Panel header (colored by appointment type) -->
      <div
        :class="[
          'px-5 py-4 border-b border-gray-200 dark:border-slate-700',
          (COLOR_CLASS_MAP[props.typeColors[selectedAppt.appointment_type]] ?? COLOR_CLASS_MAP.gray).bg,
        ]"
      >
        <div class="flex items-start justify-between">
          <div>
            <span
              :class="[
                'inline-block text-xs font-semibold px-2 py-0.5 rounded-full border mb-1',
                (COLOR_CLASS_MAP[props.typeColors[selectedAppt.appointment_type]] ?? COLOR_CLASS_MAP.gray).text,
                (COLOR_CLASS_MAP[props.typeColors[selectedAppt.appointment_type]] ?? COLOR_CLASS_MAP.gray).border,
              ]"
            >
              {{ props.typeLabels[selectedAppt.appointment_type] || selectedAppt.appointment_type }}
            </span>
            <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">
              <template v-if="selectedAppt.participant">
                {{ selectedAppt.participant.first_name }} {{ selectedAppt.participant.last_name }}
              </template>
              <template v-else>Unknown</template>
            </h3>
            <p v-if="selectedAppt.participant" class="text-xs text-gray-500 dark:text-slate-400">
              {{ selectedAppt.participant.mrn }}
            </p>
          </div>
          <button
            class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300 text-lg"
            aria-label="Close panel"
            @click="closeDetail"
          >
            &#x2715;
          </button>
        </div>
      </div>

      <!-- Panel body -->
      <div class="flex-1 overflow-y-auto p-5 space-y-4">
        <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <div>
            <p class="text-gray-500 dark:text-slate-400 text-xs">Status</p>
            <span
              :class="['inline-block mt-0.5 px-2 py-0.5 rounded-full text-xs font-medium', STATUS_COLORS[selectedAppt.status] || '']"
            >
              {{ STATUS_LABELS[selectedAppt.status] || selectedAppt.status }}
            </span>
          </div>
          <div>
            <p class="text-gray-500 dark:text-slate-400 text-xs">Transport</p>
            <p class="font-medium mt-0.5 text-gray-900 dark:text-slate-100 flex items-center gap-1">
              <template v-if="selectedAppt.transport_required">
                <TruckIcon class="w-4 h-4 inline-block" aria-hidden="true" /> Required
              </template>
              <template v-else>Not needed</template>
            </p>
          </div>
          <div class="col-span-2">
            <p class="text-gray-500 dark:text-slate-400 text-xs">Date and Time</p>
            <p class="font-medium mt-0.5 text-gray-900 dark:text-slate-100">
              {{ new Date(selectedAppt.scheduled_start).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) }}
              &middot;
              {{ new Date(selectedAppt.scheduled_start).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) }}
              -
              {{ new Date(selectedAppt.scheduled_end).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) }}
            </p>
          </div>
          <div v-if="selectedAppt.provider" class="col-span-2">
            <p class="text-gray-500 dark:text-slate-400 text-xs">Provider</p>
            <p class="font-medium mt-0.5 text-gray-900 dark:text-slate-100">
              {{ selectedAppt.provider.first_name }} {{ selectedAppt.provider.last_name }}
            </p>
          </div>
          <div v-if="selectedAppt.location" class="col-span-2">
            <p class="text-gray-500 dark:text-slate-400 text-xs">Location</p>
            <p class="font-medium mt-0.5 text-gray-900 dark:text-slate-100">{{ selectedAppt.location.name }}</p>
          </div>
          <div v-if="selectedAppt.notes" class="col-span-2">
            <p class="text-gray-500 dark:text-slate-400 text-xs">Notes</p>
            <p class="mt-0.5 text-gray-700 dark:text-slate-300">{{ selectedAppt.notes }}</p>
          </div>
          <div v-if="selectedAppt.cancellation_reason" class="col-span-2">
            <p class="text-gray-500 dark:text-slate-400 text-xs">Cancellation Reason</p>
            <p class="mt-0.5 text-red-700 dark:text-red-300">{{ selectedAppt.cancellation_reason }}</p>
          </div>
        </div>

        <p v-if="panelError" class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/60 rounded p-2">
          {{ panelError }}
        </p>

        <!-- Action buttons (editable appointments only) -->
        <template v-if="selectedAppt.status === 'scheduled' || selectedAppt.status === 'confirmed'">
          <div v-if="!panelCancelling" class="flex flex-col gap-2 pt-2">
            <button
              :disabled="panelSaving"
              class="w-full py-2 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50"
              @click="markComplete"
            >
              Mark Complete
            </button>
            <button
              class="w-full py-2 rounded-lg bg-white dark:bg-slate-800 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 text-sm font-medium hover:bg-red-50 dark:hover:bg-red-900/20"
              @click="panelCancelling = true"
            >
              Cancel Appointment
            </button>
            <button
              :disabled="panelSaving"
              class="w-full py-2 rounded-lg bg-white dark:bg-slate-800 border border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-300 text-sm font-medium hover:bg-amber-50 dark:hover:bg-amber-900/20"
              @click="markNoShow"
            >
              Mark No-Show
            </button>
          </div>

          <!-- Cancellation reason form -->
          <div v-else class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Cancellation Reason</label>
              <select name="panelCancelReason"
                v-model="panelCancelReason"
                class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700"
              >
                <option value="">Select reason</option>
                <option v-for="reason in CANCEL_REASONS" :key="reason" :value="reason">{{ reason }}</option>
              </select>
            </div>
            <div class="flex gap-2">
              <button
                class="flex-1 py-2 rounded-lg border border-gray-300 dark:border-slate-600 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 bg-white dark:bg-slate-800"
                @click="panelCancelling = false"
              >
                Back
              </button>
              <button
                :disabled="panelSaving || !panelCancelReason"
                class="flex-1 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 disabled:opacity-50"
                @click="submitCancel"
              >
                {{ panelSaving ? 'Cancelling...' : 'Confirm Cancel' }}
              </button>
            </div>
          </div>
        </template>
      </div>
    </div>
    </div><!-- end slide panel wrapper -->

    </div><!-- end calendar + detail panel wrapper -->

    <!-- Booking modal -->
    <div
      v-if="showBooking"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      @click.self="handleBackdropClick"
    >
      <div
        :class="[
          'bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg',
          modalShaking ? 'animate-shake' : '',
        ]"
        role="dialog"
        aria-modal="true"
      >
        <!-- Modal header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">New Appointment</h2>
          <button
            :class="[
              'text-xl font-bold rounded-full w-8 h-8 flex items-center justify-center transition-all',
              closeBtnFlashing
                ? 'bg-red-500 text-white ring-4 ring-red-300 dark:ring-red-600 scale-110'
                : 'text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700',
            ]"
            aria-label="Close"
            @click="closeBooking"
          >
            &#x2715;
          </button>
        </div>

        <div class="p-6 space-y-4">

          <!-- Step 1: Participant search -->
          <template v-if="bookingStep === 1">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Search Participant</label>
              <input
                v-model="participantSearch"
                type="text"
                placeholder="Name or MRN..."
                autofocus
                class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 placeholder-gray-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              />
              <ul
                v-if="participantResults.length > 0"
                class="mt-1 border border-gray-200 dark:border-slate-600 rounded-lg divide-y divide-gray-100 dark:divide-slate-700 max-h-48 overflow-y-auto bg-white dark:bg-slate-800"
              >
                <li v-for="p in participantResults" :key="p.id">
                  <button
                    type="button"
                    class="w-full text-left px-3 py-2 text-sm text-gray-900 dark:text-slate-100 hover:bg-blue-50 dark:hover:bg-slate-700 flex items-center justify-between gap-3"
                    @click="selectParticipant(p)"
                  >
                    <div class="min-w-0 flex-1">
                      <p class="font-medium truncate">{{ p.name || `${p.first_name ?? ''} ${p.last_name ?? ''}`.trim() || 'Unknown' }}</p>
                      <p class="text-sm text-gray-500 dark:text-slate-400 truncate">
                        {{ p.mrn }}<span v-if="p.dob"> &middot; DOB {{ p.dob }}</span><span v-if="p.age != null"> ({{ p.age }} yrs)</span>
                      </p>
                    </div>
                    <span
                      v-if="p.enrollment_status"
                      class="inline-flex shrink-0 items-center px-1.5 py-0.5 rounded text-sm font-medium bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 capitalize"
                    >
                      {{ p.enrollment_status.replace(/_/g, ' ') }}
                    </span>
                  </button>
                </li>
              </ul>
            </div>
          </template>

          <!-- Step 2: Appointment details -->
          <template v-else-if="bookingStep === 2 && selectedParticipant">

            <!-- Selected participant chip with change button -->
            <div class="flex items-center gap-2 p-2 bg-blue-50 dark:bg-blue-950/60 rounded-lg">
              <span class="text-sm font-medium text-blue-800 dark:text-blue-300">
                {{ selectedParticipant.name || `${selectedParticipant.first_name ?? ''} ${selectedParticipant.last_name ?? ''}`.trim() || 'Unknown' }}
              </span>
              <span class="text-sm text-blue-600 dark:text-blue-400">{{ selectedParticipant.mrn }}</span>
              <button
                type="button"
                class="ml-auto text-xs text-blue-600 dark:text-blue-400 hover:underline"
                @click="bookingStep = 1; selectedParticipant = null"
              >
                Change
              </button>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Appointment Type</label>
              <select name="appointment_type"
                v-model="bookingForm.appointment_type"
                class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700"
              >
                <option v-for="t in props.appointmentTypes" :key="t" :value="t">
                  {{ props.typeLabels[t] || t }}
                </option>
              </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Start</label>
                <input
                  v-model="bookingForm.scheduled_start"
                  type="datetime-local"
                  required
                  class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">End</label>
                <input
                  v-model="bookingForm.scheduled_end"
                  type="datetime-local"
                  required
                  class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700"
                />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Location</label>
              <LocationCombobox
                :locations="props.locations"
                :model-value="bookingForm.location_id || null"
                placeholder="Search locations — type to filter..."
                @update:model-value="bookingForm.location_id = $event == null ? '' : String($event)"
              />
            </div>

            <div class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 dark:border-slate-600">
              <input
                id="transport_required"
                v-model="bookingForm.transport_required"
                type="checkbox"
                class="h-4 w-4 rounded border-gray-300 dark:border-slate-600 text-blue-600"
              />
              <label for="transport_required" class="text-sm font-medium text-gray-700 dark:text-slate-300">
                Transport Required
                <span class="block text-xs text-gray-500 dark:text-slate-400 font-normal">Check if participant needs a ride</span>
              </label>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Notes</label>
              <textarea
                v-model="bookingForm.notes"
                rows="2"
                placeholder="Optional notes for this appointment..."
                class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 resize-none placeholder-gray-400 dark:placeholder-slate-500"
              />
            </div>
          </template>

          <p
            v-if="bookingError"
            class="rounded-lg bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-300"
          >
            {{ bookingError }}
          </p>

          <div class="flex justify-end gap-3 pt-2">
            <button
              type="button"
              class="px-4 py-2 text-sm text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600"
              @click="closeBooking"
            >
              Cancel
            </button>
            <button
              v-if="bookingStep === 2"
              :disabled="bookingSaving"
              class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
              @click="submitBooking"
            >
              {{ bookingSaving ? 'Saving...' : 'Create Appointment' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Cross-site confirmation modal -->
    <div
      v-if="showCrossSiteConfirm && crossSiteInfo"
      class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"
    >
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md" role="dialog" aria-modal="true">
        <div class="px-6 pt-5 pb-4 border-b border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 rounded-t-xl">
          <div class="flex items-start gap-3">
            <ExclamationTriangleIcon class="w-6 h-6 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
            <div>
              <h2 class="text-base font-semibold text-amber-800 dark:text-amber-200">Cross-Site Appointment</h2>
              <p class="text-sm text-amber-700 dark:text-amber-300 mt-1">
                This participant is enrolled at a different PACE site.
              </p>
            </div>
          </div>
        </div>
        <div class="px-6 py-5 space-y-3 text-sm text-gray-700 dark:text-slate-300">
          <p>
            You're scheduling
            <span class="font-semibold text-gray-900 dark:text-slate-100">{{ crossSiteInfo.participantName }}</span>
            (enrolled at
            <span class="font-semibold text-gray-900 dark:text-slate-100">{{ crossSiteInfo.homeSiteName }}</span>)
            at
            <span class="font-semibold text-gray-900 dark:text-slate-100">{{ crossSiteInfo.hostSiteName }}</span>.
          </p>
          <p class="text-sm text-gray-500 dark:text-slate-400">
            This visit will be logged as cross-site on the participant's audit trail. Their attendance that day will appear on the host site's day-center roster.
          </p>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 dark:border-slate-700 flex justify-end gap-2">
          <button
            class="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
            @click="showCrossSiteConfirm = false"
          >
            Cancel
          </button>
          <button
            class="px-4 py-2 text-sm bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-medium"
            @click="crossSiteConfirmed = true; showCrossSiteConfirm = false; submitBooking()"
          >
            Yes, Schedule Cross-Site
          </button>
        </div>
      </div>
    </div>

    </div><!-- end flex column wrapper -->

  </AppShell>
</template>
