<!--
  Finance / HOS-M Annual Surveys
  Health Outcomes Survey for Medicare (PACE): tracks annual survey
  administration, completion, and CMS submission. One survey per participant
  per year (DB unique constraint enforced).

  Route: GET /billing/hos-m
  Backend: app/Http/Controllers/HosMSurveyController.php
-->
<script setup lang="ts">
import { ref, computed, reactive } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    PlusIcon, XMarkIcon, MagnifyingGlassIcon, ClipboardDocumentListIcon,
    CheckCircleIcon, PaperAirplaneIcon,
} from '@heroicons/vue/24/outline'

// ── Types ─────────────────────────────────────────────────────────────────────

interface ParticipantSummary {
    id: number
    mrn: string
    first_name: string
    last_name: string
}

interface HosMSurvey {
    id: number
    participant: ParticipantSummary | null
    administered_by: { id: number; first_name: string; last_name: string } | null
    survey_year: number
    administered_at: string | null
    completed: boolean
    submitted_to_cms: boolean
    submitted_at: string | null
    responses: Record<string, any> | null
}

interface Stats {
    total_enrolled:   number
    surveyed:         number
    completed:        number
    submitted_to_cms: number
}

// Laravel serializes eager-loaded relationships using the relationship name.
// `administeredBy` relationship on the model → `administered_by` in JSON by
// Laravel's default conversion. Accept both shapes for safety.
interface BackendSurvey extends Omit<HosMSurvey, 'administered_by'> {
    administeredBy?: { id: number; first_name: string; last_name: string } | null
    administered_by?: { id: number; first_name: string; last_name: string } | null
}

const props = defineProps<{
    surveys:              BackendSurvey[]
    stats:                Stats
    selectedYear:         number
    currentYear:          number
    availableYears:       number[]
    enrolledParticipants: ParticipantSummary[]
}>()

const surveyList = computed<HosMSurvey[]>(() =>
    props.surveys.map(s => ({
        ...s,
        administered_by: s.administered_by ?? s.administeredBy ?? null,
    })),
)

// ── Year selector ────────────────────────────────────────────────────────────
// Server-side change: reloads the page with ?year=N so stats + surveys table
// refresh to show only that year.
function changeYear(year: number | string) {
    const y = Number(year)
    if (!y || y === props.selectedYear) return
    router.get('/billing/hos-m', { year: y }, { preserveState: false, preserveScroll: false })
}

// ── Status filter chips ──────────────────────────────────────────────────────
// Client-side: cheap to compute from `surveyList` which already holds the
// year's surveys. Chips are mutually-exclusive "all | incomplete | complete
// (pending CMS) | submitted".
type StatusFilter = 'all' | 'incomplete' | 'pending_cms' | 'submitted'
const statusFilter = ref<StatusFilter>('all')

const counts = computed(() => ({
    all:        surveyList.value.length,
    incomplete: surveyList.value.filter(s => !s.completed).length,
    pending:    surveyList.value.filter(s => s.completed && !s.submitted_to_cms).length,
    submitted:  surveyList.value.filter(s => s.submitted_to_cms).length,
}))

// ── Search + filter ──────────────────────────────────────────────────────────

const search = ref('')
const filtered = computed(() => {
    let list = surveyList.value

    // Status chip filter
    if (statusFilter.value === 'incomplete')  list = list.filter(s => !s.completed)
    else if (statusFilter.value === 'pending_cms') list = list.filter(s => s.completed && !s.submitted_to_cms)
    else if (statusFilter.value === 'submitted')   list = list.filter(s => s.submitted_to_cms)

    // Text search
    const q = search.value.toLowerCase().trim()
    if (q) {
        list = list.filter(s => {
            if (!s.participant) return false
            const name = `${s.participant.first_name} ${s.participant.last_name}`.toLowerCase()
            return name.includes(q) || s.participant.mrn.toLowerCase().includes(q)
        })
    }
    return list
})

// ── Helpers ──────────────────────────────────────────────────────────────────

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val)
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'numeric', day: 'numeric' })
}

function pct(num: number, denom: number): number {
    if (denom <= 0) return 0
    return Math.min(100, Math.round((num / denom) * 100))
}

// ── Add Survey modal ─────────────────────────────────────────────────────────

interface ParticipantWithStatus extends ParticipantSummary {
    surveyStatus: 'needs' | 'incomplete' | 'complete' | 'submitted'
    statusLabel: string
}

const showAddModal = ref(false)
const saving = ref(false)
const formError = ref('')
const formErrors = ref<Record<string, string | string[]>>({})

const form = reactive({
    participant_id: '' as string | number,
    survey_year: props.selectedYear,
    administered_at: new Date().toISOString().slice(0, 10),
    completed: false,
    responses: {
        physical_health:  null as number | null,
        mental_health:    null as number | null,
        pain:             null as number | null,
        falls_past_year:  null as string | null,   // '0' | '1'
        fall_injuries:    null as string | null,
    },
})

function resetForm() {
    form.participant_id = ''
    form.survey_year = props.selectedYear
    form.administered_at = new Date().toISOString().slice(0, 10)
    form.completed = false
    form.responses = {
        physical_health: null, mental_health: null, pain: null,
        falls_past_year: null, fall_injuries: null,
    }
    formError.value = ''
    formErrors.value = {}
}

// Group enrolled participants into "Needs Survey" vs "Already Has Survey" for
// the CURRENTLY-LOADED year (selectedYear). If the user changes form.survey_year
// to a different year in the modal, groupings will only reflect surveys for
// the page's loaded year (to know about another year, switch the page selector).
const participantsByStatus = computed<{ needs: ParticipantSummary[]; done: ParticipantWithStatus[] }>(() => {
    // Only apply the optgroup logic when the form year matches the loaded year;
    // for any other year we can't tell who's been surveyed without another fetch,
    // so we show every participant as eligible.
    if (form.survey_year !== props.selectedYear) {
        return { needs: [...props.enrolledParticipants], done: [] }
    }
    const needs: ParticipantSummary[] = []
    const done: ParticipantWithStatus[] = []

    for (const p of props.enrolledParticipants) {
        const existing = surveyList.value.find(s => s.participant?.id === p.id)
        if (!existing) {
            needs.push(p)
            continue
        }
        let status: ParticipantWithStatus['surveyStatus']
        let label: string
        if (existing.submitted_to_cms) {
            status = 'submitted'
            label  = 'Submitted to CMS'
        } else if (existing.completed) {
            status = 'complete'
            label  = 'Complete: pending CMS submission'
        } else {
            status = 'incomplete'
            label  = 'Incomplete: in progress'
        }
        done.push({ ...p, surveyStatus: status, statusLabel: label })
    }
    return { needs, done }
})

function openAddModal() {
    resetForm()
    showAddModal.value = true
}
function closeAddModal() {
    showAddModal.value = false
}

async function submitSurvey() {
    if (!form.participant_id) { formError.value = 'Please select a participant.'; return }
    saving.value = true
    formError.value = ''
    formErrors.value = {}

    // Strip null responses so backend validation doesn't reject empty fields
    const responses: Record<string, any> = {}
    for (const [k, v] of Object.entries(form.responses)) {
        if (v !== null && v !== '') responses[k] = v
    }

    try {
        await axios.post('/billing/hos-m', {
            participant_id:   Number(form.participant_id),
            survey_year:      form.survey_year,
            administered_at:  form.administered_at,
            completed:        form.completed,
            ...(Object.keys(responses).length > 0 ? { responses } : {}),
        })
        closeAddModal()
        router.reload()
    } catch (err: any) {
        const status = err.response?.status
        if (status === 422) {
            formErrors.value = err.response.data.errors ?? {}
            formError.value = 'Please fix the highlighted fields.'
        } else if (status === 409) {
            formError.value = err.response.data.error ?? 'Survey already exists for this year.'
        } else {
            formError.value = err.response?.data?.message ?? err.message ?? 'Failed to save survey.'
        }
    } finally {
        saving.value = false
    }
}

// ── Submit to CMS action ─────────────────────────────────────────────────────

const submittingId = ref<number | null>(null)

async function submitToCms(survey: HosMSurvey) {
    if (!confirm(
        `Mark this survey as submitted to CMS?\n\n` +
        `IMPORTANT: This does NOT transmit data to CMS. It only records that you have ` +
        `already submitted this survey externally via HPMS or your clearinghouse.\n\n` +
        `Only confirm if the external submission has actually been completed. ` +
        `Once marked, this record will be locked from edits.`
    )) return
    submittingId.value = survey.id
    try {
        await axios.post(`/billing/hos-m/${survey.id}/submit`)
        router.reload()
    } catch (err: any) {
        alert(err.response?.data?.message ?? 'Failed to submit to CMS.')
    } finally {
        submittingId.value = null
    }
}

// ── View / Edit Survey modal ─────────────────────────────────────────────────

const showEditModal = ref(false)
const editingSurvey = ref<HosMSurvey | null>(null)
const editSaving = ref(false)
const editError = ref('')

// Local editable copy of the survey being viewed/edited
const editForm = reactive({
    completed: false,
    responses: {
        physical_health: null as number | null,
        mental_health:   null as number | null,
        pain:            null as number | null,
        falls_past_year: null as string | null,
        fall_injuries:   null as string | null,
    },
})

function openSurveyModal(survey: HosMSurvey) {
    editingSurvey.value = survey
    editForm.completed = survey.completed
    const r = survey.responses ?? {}
    editForm.responses = {
        physical_health: r.physical_health ?? null,
        mental_health:   r.mental_health   ?? null,
        pain:            r.pain            ?? null,
        falls_past_year: r.falls_past_year != null ? String(r.falls_past_year) : null,
        fall_injuries:   r.fall_injuries   != null ? String(r.fall_injuries)   : null,
    }
    editError.value = ''
    showEditModal.value = true
}

function closeSurveyModal() {
    showEditModal.value = false
    editingSurvey.value = null
    editError.value = ''
}

const isSurveyLocked = computed(() => editingSurvey.value?.submitted_to_cms ?? false)

async function saveSurveyEdits() {
    if (!editingSurvey.value || isSurveyLocked.value) return
    editSaving.value = true
    editError.value = ''

    const responses: Record<string, any> = {}
    for (const [k, v] of Object.entries(editForm.responses)) {
        if (v !== null && v !== '') responses[k] = v
    }

    try {
        await axios.put(`/billing/hos-m/${editingSurvey.value.id}`, {
            completed: editForm.completed,
            ...(Object.keys(responses).length > 0 ? { responses } : {}),
        })
        closeSurveyModal()
        router.reload()
    } catch (err: any) {
        const status = err.response?.status
        editError.value = status
            ? `Save failed (HTTP ${status}): ${err.response?.data?.message ?? ''}`
            : `Save failed: ${err.message}`
    } finally {
        editSaving.value = false
    }
}

async function submitCurrentToCms() {
    if (!editingSurvey.value) return
    if (!editingSurvey.value.completed) {
        editError.value = 'Survey must be marked Complete before submitting to CMS.'
        return
    }
    if (!confirm(
        `Mark this survey as submitted to CMS?\n\n` +
        `IMPORTANT: This does NOT transmit data to CMS. It only records that you have ` +
        `already submitted this survey externally via HPMS or your clearinghouse.\n\n` +
        `Only confirm if the external submission has actually been completed.`
    )) return
    editSaving.value = true
    editError.value = ''
    try {
        await axios.post(`/billing/hos-m/${editingSurvey.value.id}/submit`)
        closeSurveyModal()
        router.reload()
    } catch (err: any) {
        editError.value = err.response?.data?.message ?? 'Failed to submit to CMS.'
    } finally {
        editSaving.value = false
    }
}
</script>

<template>
    <AppShell>
        <Head title="HOS-M Annual Surveys" />

        <div class="p-6 space-y-5">

            <!-- Header -->
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">HOS-M Annual Surveys</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                        Health Outcomes Survey for Medicare (PACE): {{ selectedYear }} administration
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Year selector -->
                    <label class="flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-slate-300">Year:</span>
                        <select
                            :value="selectedYear"
                            class="border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100"
                            @change="changeYear(($event.target as HTMLSelectElement).value)"
                        >
                            <option v-for="y in availableYears" :key="y" :value="y">
                                {{ y }}<span v-if="y === currentYear"> (current)</span>
                            </option>
                        </select>
                    </label>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors"
                        @click="openAddModal"
                    >
                        <PlusIcon class="w-4 h-4" />
                        Add Survey
                    </button>
                </div>
            </div>

            <!-- KPI cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-2">
                    <p class="text-sm font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wider">Enrolled Participants</p>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ stats.total_enrolled }}</span>
                        <span class="text-sm text-gray-400 dark:text-slate-500">/ {{ stats.total_enrolled }}</span>
                    </div>
                    <div class="w-full bg-gray-100 dark:bg-slate-700 rounded-full h-1.5 mt-1">
                        <div class="bg-gray-400 dark:bg-slate-500 h-1.5 rounded-full" style="width: 100%" />
                    </div>
                    <p class="text-sm text-gray-400 dark:text-slate-500">100%</p>
                </div>

                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-2">
                    <p class="text-sm font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wider">Surveyed ({{ selectedYear }})</p>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ stats.surveyed }}</span>
                        <span class="text-sm text-gray-400 dark:text-slate-500">/ {{ stats.total_enrolled }}</span>
                    </div>
                    <div class="w-full bg-gray-100 dark:bg-slate-700 rounded-full h-1.5 mt-1">
                        <div class="bg-blue-500 dark:bg-blue-400 h-1.5 rounded-full transition-all" :style="{ width: `${pct(stats.surveyed, stats.total_enrolled)}%` }" />
                    </div>
                    <p class="text-sm text-gray-400 dark:text-slate-500">{{ pct(stats.surveyed, stats.total_enrolled) }}%</p>
                </div>

                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-2">
                    <p class="text-sm font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wider">Completed ({{ selectedYear }})</p>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ stats.completed }}</span>
                        <span class="text-sm text-gray-400 dark:text-slate-500">/ {{ stats.total_enrolled }}</span>
                    </div>
                    <div class="w-full bg-gray-100 dark:bg-slate-700 rounded-full h-1.5 mt-1">
                        <div class="bg-green-500 dark:bg-green-400 h-1.5 rounded-full transition-all" :style="{ width: `${pct(stats.completed, stats.total_enrolled)}%` }" />
                    </div>
                    <p class="text-sm text-gray-400 dark:text-slate-500">{{ pct(stats.completed, stats.total_enrolled) }}%</p>
                </div>

                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-2" :title="'Surveys staff have marked as submitted externally via HPMS or clearinghouse. The EMR does not transmit to CMS directly.'">
                    <p class="text-sm font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wider">Marked CMS Submitted ({{ selectedYear }})</p>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ stats.submitted_to_cms }}</span>
                        <span class="text-sm text-gray-400 dark:text-slate-500">/ {{ stats.total_enrolled }}</span>
                    </div>
                    <div class="w-full bg-gray-100 dark:bg-slate-700 rounded-full h-1.5 mt-1">
                        <div class="bg-indigo-500 dark:bg-indigo-400 h-1.5 rounded-full transition-all" :style="{ width: `${pct(stats.submitted_to_cms, stats.total_enrolled)}%` }" />
                    </div>
                    <p class="text-sm text-gray-400 dark:text-slate-500">{{ pct(stats.submitted_to_cms, stats.total_enrolled) }}%</p>
                </div>
            </div>

            <!-- Filter bar: status chips + search -->
            <div class="flex flex-wrap items-center gap-3">
                <!-- Status chips -->
                <div class="flex flex-wrap gap-1">
                    <button
                        type="button"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-sm font-medium transition-colors border',
                            statusFilter === 'all'
                                ? 'bg-indigo-600 text-white border-indigo-600'
                                : 'bg-white dark:bg-slate-700 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600',
                        ]"
                        @click="statusFilter = 'all'"
                    >
                        All <span class="ml-1 text-sm opacity-75 tabular-nums">{{ counts.all }}</span>
                    </button>
                    <button
                        type="button"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-sm font-medium transition-colors border',
                            statusFilter === 'incomplete'
                                ? 'bg-amber-500 text-white border-amber-500'
                                : 'bg-white dark:bg-slate-700 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600',
                        ]"
                        @click="statusFilter = 'incomplete'"
                    >
                        Incomplete <span class="ml-1 text-sm opacity-75 tabular-nums">{{ counts.incomplete }}</span>
                    </button>
                    <button
                        type="button"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-sm font-medium transition-colors border',
                            statusFilter === 'pending_cms'
                                ? 'bg-green-600 text-white border-green-600'
                                : 'bg-white dark:bg-slate-700 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600',
                        ]"
                        @click="statusFilter = 'pending_cms'"
                    >
                        Pending CMS <span class="ml-1 text-sm opacity-75 tabular-nums">{{ counts.pending }}</span>
                    </button>
                    <button
                        type="button"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-sm font-medium transition-colors border',
                            statusFilter === 'submitted'
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white dark:bg-slate-700 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600',
                        ]"
                        @click="statusFilter = 'submitted'"
                    >
                        Submitted <span class="ml-1 text-sm opacity-75 tabular-nums">{{ counts.submitted }}</span>
                    </button>
                </div>

                <!-- Search (right side, push apart on wider screens) -->
                <div class="relative max-w-sm flex-1 min-w-[16rem] ml-auto">
                    <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search by participant or MRN..."
                        class="w-full pl-9 pr-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <table v-if="filtered.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Participant</th>
                            <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Year</th>
                            <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Administered At</th>
                            <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Administered By</th>
                            <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Completed</th>
                            <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">CMS Status</th>
                            <th class="px-5 py-3 text-right text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr
                            v-for="survey in filtered"
                            :key="survey.id"
                            class="hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer transition-colors"
                            tabindex="0"
                            :aria-label="`Open HOS-M survey for ${survey.participant?.first_name ?? 'participant'} ${survey.participant?.last_name ?? ''}`"
                            @click="openSurveyModal(survey)"
                            @keydown.enter="openSurveyModal(survey)"
                        >
                            <td class="px-5 py-3">
                                <div v-if="survey.participant">
                                    <p class="text-sm font-medium text-gray-900 dark:text-slate-100">
                                        {{ survey.participant.first_name }} {{ survey.participant.last_name }}
                                    </p>
                                    <p class="text-sm font-mono text-gray-500 dark:text-slate-400">{{ survey.participant.mrn }}</p>
                                </div>
                                <span v-else class="text-gray-400 dark:text-slate-500 text-sm">-</span>
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-slate-300">{{ survey.survey_year }}</td>
                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-slate-300">{{ fmtDate(survey.administered_at) }}</td>
                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-slate-300">
                                <span v-if="survey.administered_by">
                                    {{ survey.administered_by.first_name }} {{ survey.administered_by.last_name }}
                                </span>
                                <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    v-if="survey.completed"
                                    class="inline-flex items-center px-2 py-0.5 rounded text-sm font-medium bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300"
                                >Complete</span>
                                <span
                                    v-else
                                    class="inline-flex items-center px-2 py-0.5 rounded text-sm font-medium bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300"
                                >Incomplete</span>
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    v-if="survey.submitted_to_cms"
                                    class="inline-flex items-center px-2 py-0.5 rounded text-sm font-medium bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300"
                                    :title="survey.submitted_at ? `Submitted ${fmtDate(survey.submitted_at)}` : 'Submitted'"
                                >Submitted</span>
                                <span
                                    v-else
                                    class="inline-flex items-center px-2 py-0.5 rounded text-sm font-medium bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400"
                                >Not Submitted</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <button
                                    v-if="survey.completed && !survey.submitted_to_cms"
                                    type="button"
                                    :disabled="submittingId === survey.id"
                                    class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 disabled:opacity-50"
                                    @click.stop="submitToCms(survey)"
                                >
                                    <PaperAirplaneIcon class="w-3.5 h-3.5" />
                                    {{ submittingId === survey.id ? 'Saving...' : 'Mark CMS Submitted' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-else class="flex flex-col items-center justify-center py-16 text-gray-500 dark:text-slate-400">
                    <ClipboardDocumentListIcon class="w-10 h-10 mb-3 text-gray-300 dark:text-slate-600" />
                    <p class="text-sm">No surveys yet. Use "Add Survey" to record the first one.</p>
                </div>
            </div>
        </div>

        <!-- Add Survey modal -->
        <div
            v-if="showAddModal"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
            @click.self="closeAddModal"
        >
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col" role="dialog" aria-modal="true">
                <!-- Header -->
                <div class="px-5 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Add HOS-M Survey</h2>
                    <button class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300" aria-label="Close" @click="closeAddModal">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <!-- Body -->
                <form class="flex-1 overflow-y-auto px-5 py-4 space-y-4" @submit.prevent="submitSurvey">

                    <div
                        v-if="formError"
                        class="rounded-lg border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/60 px-3 py-2 text-sm text-red-700 dark:text-red-300"
                    >
                        {{ formError }}
                    </div>

                    <!-- Participant (grouped by survey status for the selected year) -->
                    <div>
                        <label for="participant_id" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                            Participant <span class="text-red-500">*</span>
                        </label>
                        <select
                            id="participant_id"
                            v-model="form.participant_id"
                            class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100"
                        >
                            <option value="">Select an enrolled participant</option>
                            <optgroup :label="`Needs ${form.survey_year} Survey (${participantsByStatus.needs.length})`">
                                <option v-for="p in participantsByStatus.needs" :key="p.id" :value="p.id">
                                    {{ p.last_name }}, {{ p.first_name }} ({{ p.mrn }})
                                </option>
                                <option v-if="participantsByStatus.needs.length === 0" disabled>
                                    All enrolled participants have a {{ form.survey_year }} survey
                                </option>
                            </optgroup>
                            <optgroup
                                v-if="participantsByStatus.done.length > 0"
                                :label="`Already Has ${form.survey_year} Survey (${participantsByStatus.done.length})`"
                            >
                                <option v-for="p in participantsByStatus.done" :key="p.id" :value="p.id" disabled>
                                    {{ p.last_name }}, {{ p.first_name }} ({{ p.mrn }}): {{ p.statusLabel }}
                                </option>
                            </optgroup>
                        </select>
                        <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                            <span class="font-medium text-gray-700 dark:text-slate-300">{{ participantsByStatus.needs.length }}</span>
                            of <span class="font-medium text-gray-700 dark:text-slate-300">{{ props.enrolledParticipants.length }}</span>
                            enrolled participants need a {{ form.survey_year }} survey.
                        </p>
                        <p v-if="formErrors.participant_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                            {{ Array.isArray(formErrors.participant_id) ? formErrors.participant_id[0] : formErrors.participant_id }}
                        </p>
                    </div>

                    <!-- Year + Date row -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="survey_year" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Survey Year <span class="text-red-500">*</span></label>
                            <input
                                id="survey_year"
                                v-model.number="form.survey_year"
                                type="number"
                                min="2020"
                                :max="currentYear + 1"
                                class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100"
                            />
                        </div>
                        <div>
                            <label for="administered_at" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Administered <span class="text-red-500">*</span></label>
                            <input
                                id="administered_at"
                                v-model="form.administered_at"
                                type="date"
                                class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100"
                            />
                        </div>
                    </div>

                    <!-- Completed toggle -->
                    <label class="flex items-center gap-2 p-3 rounded-lg border border-gray-200 dark:border-slate-700 cursor-pointer">
                        <input v-model="form.completed" type="checkbox" class="rounded border-gray-300 dark:border-slate-600 text-indigo-600" />
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-slate-100">Mark as Complete</p>
                            <p class="text-sm text-gray-500 dark:text-slate-400">Only completed surveys can be submitted to CMS.</p>
                        </div>
                    </label>

                    <!-- Responses (optional) -->
                    <details class="border border-gray-200 dark:border-slate-700 rounded-lg">
                        <summary class="px-3 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 cursor-pointer select-none">
                            Survey Responses <span class="text-gray-400 dark:text-slate-500 font-normal">(optional: can be added later)</span>
                        </summary>
                        <div class="px-3 py-3 border-t border-gray-200 dark:border-slate-700 space-y-3">
                            <p class="text-sm text-gray-500 dark:text-slate-400">
                                5-point scales: 1 = Excellent, 5 = Poor. Fall questions: Yes/No.
                            </p>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm text-gray-600 dark:text-slate-400 mb-1">Physical Health (1-5)</label>
                                    <input v-model.number="form.responses.physical_health" type="number" min="1" max="5"
                                        class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600 dark:text-slate-400 mb-1">Mental Health (1-5)</label>
                                    <input v-model.number="form.responses.mental_health" type="number" min="1" max="5"
                                        class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600 dark:text-slate-400 mb-1">Pain (1-5)</label>
                                    <input v-model.number="form.responses.pain" type="number" min="1" max="5"
                                        class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600 dark:text-slate-400 mb-1">Falls Past Year</label>
                                    <select v-model="form.responses.falls_past_year"
                                        class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100">
                                        <option :value="null">-</option>
                                        <option value="0">No</option>
                                        <option value="1">Yes</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600 dark:text-slate-400 mb-1">Fall Injuries</label>
                                    <select v-model="form.responses.fall_injuries"
                                        class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100">
                                        <option :value="null">-</option>
                                        <option value="0">No</option>
                                        <option value="1">Yes</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </details>
                </form>

                <!-- Footer -->
                <div class="px-5 py-4 border-t border-gray-200 dark:border-slate-700 flex justify-end gap-2">
                    <button
                        class="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
                        @click="closeAddModal"
                    >Cancel</button>
                    <button
                        :disabled="saving"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg disabled:opacity-50"
                        @click="submitSurvey"
                    >
                        <CheckCircleIcon class="w-4 h-4" />
                        {{ saving ? 'Saving...' : 'Save Survey' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- View / Edit Survey modal -->
        <div
            v-if="showEditModal && editingSurvey"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
            @click.self="closeSurveyModal"
        >
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col" role="dialog" aria-modal="true">
                <!-- Header -->
                <div class="px-5 py-4 border-b border-gray-200 dark:border-slate-700 flex items-start justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">
                            {{ isSurveyLocked ? 'View Survey' : 'Edit Survey' }}
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                            <span v-if="editingSurvey.participant">
                                {{ editingSurvey.participant.first_name }} {{ editingSurvey.participant.last_name }}
                                · {{ editingSurvey.participant.mrn }}
                            </span>
                            · {{ editingSurvey.survey_year }}
                        </p>
                    </div>
                    <button
                        class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300"
                        aria-label="Close"
                        @click="closeSurveyModal"
                    >
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <!-- Body -->
                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">

                    <!-- Lock banner when submitted -->
                    <div
                        v-if="isSurveyLocked"
                        class="rounded-lg border border-blue-300 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/60 px-3 py-2 text-sm text-blue-700 dark:text-blue-300"
                    >
                        This survey was submitted to CMS on
                        <span class="font-semibold">{{ fmtDate(editingSurvey.submitted_at) }}</span>
                        and is read-only.
                    </div>

                    <!-- Error -->
                    <div
                        v-if="editError"
                        class="rounded-lg border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/60 px-3 py-2 text-sm text-red-700 dark:text-red-300"
                    >
                        {{ editError }}
                    </div>

                    <!-- Meta info (read-only) -->
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-gray-500 dark:text-slate-400">Administered At</dt>
                            <dd class="text-gray-900 dark:text-slate-100 font-medium">{{ fmtDate(editingSurvey.administered_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-slate-400">Administered By</dt>
                            <dd class="text-gray-900 dark:text-slate-100 font-medium">
                                <span v-if="editingSurvey.administered_by">
                                    {{ editingSurvey.administered_by.first_name }} {{ editingSurvey.administered_by.last_name }}
                                </span>
                                <span v-else>-</span>
                            </dd>
                        </div>
                    </dl>

                    <!-- Completed toggle (editable unless locked) -->
                    <label
                        :class="[
                            'flex items-center gap-2 p-3 rounded-lg border cursor-pointer',
                            isSurveyLocked ? 'border-gray-200 dark:border-slate-700 opacity-60 cursor-not-allowed' : 'border-gray-200 dark:border-slate-700',
                        ]"
                    >
                        <input
                            v-model="editForm.completed"
                            type="checkbox"
                            :disabled="isSurveyLocked"
                            class="rounded border-gray-300 dark:border-slate-600 text-indigo-600"
                        />
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-slate-100">Mark as Complete</p>
                            <p class="text-sm text-gray-500 dark:text-slate-400">Only completed surveys can be submitted to CMS.</p>
                        </div>
                    </label>

                    <!-- Responses -->
                    <div class="border border-gray-200 dark:border-slate-700 rounded-lg">
                        <div class="px-3 py-2 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                            <p class="text-sm font-medium text-gray-700 dark:text-slate-300">Survey Responses</p>
                            <p class="text-sm text-gray-400 dark:text-slate-500">1 = Excellent, 5 = Poor</p>
                        </div>
                        <div class="px-3 py-3 space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm text-gray-600 dark:text-slate-400 mb-1">Physical Health (1-5)</label>
                                    <input
                                        v-model.number="editForm.responses.physical_health"
                                        type="number" min="1" max="5"
                                        :disabled="isSurveyLocked"
                                        class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 disabled:opacity-60"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600 dark:text-slate-400 mb-1">Mental Health (1-5)</label>
                                    <input
                                        v-model.number="editForm.responses.mental_health"
                                        type="number" min="1" max="5"
                                        :disabled="isSurveyLocked"
                                        class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 disabled:opacity-60"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600 dark:text-slate-400 mb-1">Pain (1-5)</label>
                                    <input
                                        v-model.number="editForm.responses.pain"
                                        type="number" min="1" max="5"
                                        :disabled="isSurveyLocked"
                                        class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 disabled:opacity-60"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600 dark:text-slate-400 mb-1">Falls Past Year</label>
                                    <select
                                        v-model="editForm.responses.falls_past_year"
                                        :disabled="isSurveyLocked"
                                        class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 disabled:opacity-60"
                                    >
                                        <option :value="null">-</option>
                                        <option value="0">No</option>
                                        <option value="1">Yes</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600 dark:text-slate-400 mb-1">Fall Injuries</label>
                                    <select
                                        v-model="editForm.responses.fall_injuries"
                                        :disabled="isSurveyLocked"
                                        class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 disabled:opacity-60"
                                    >
                                        <option :value="null">-</option>
                                        <option value="0">No</option>
                                        <option value="1">Yes</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-5 py-4 border-t border-gray-200 dark:border-slate-700 flex justify-between items-center gap-2">
                    <!-- Mark-CMS-Submitted shortcut (visible only when editable + complete + not yet marked) -->
                    <button
                        v-if="!isSurveyLocked && editingSurvey.completed && !editingSurvey.submitted_to_cms"
                        type="button"
                        :disabled="editSaving"
                        :title="'Records that you have already submitted this survey to CMS externally (via HPMS or clearinghouse). Does not transmit data.'"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 disabled:opacity-50"
                        @click="submitCurrentToCms"
                    >
                        <PaperAirplaneIcon class="w-4 h-4" />
                        Mark CMS Submitted
                    </button>
                    <span v-else></span>

                    <div class="flex items-center gap-2">
                        <button
                            class="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
                            @click="closeSurveyModal"
                        >Close</button>
                        <button
                            v-if="!isSurveyLocked"
                            :disabled="editSaving"
                            class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg disabled:opacity-50"
                            @click="saveSurveyEdits"
                        >
                            <CheckCircleIcon class="w-4 h-4" />
                            {{ editSaving ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
