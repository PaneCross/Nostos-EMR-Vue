<script setup lang="ts">
// ─── ParticipantHeader.vue ────────────────────────────────────────────────────
// Sticky header: photo/initials avatar, name, MRN, DOB, site badge, enrollment
// status, flag chips, advance directive badges. Edit button opens a full inline
// modal covering all fields from UpdateParticipantRequest. Deactivate with
// confirmation modal. Photo upload/delete via axios.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import {
  ExclamationTriangleIcon, CameraIcon, XCircleIcon,
  BoltIcon, PencilSquareIcon,
} from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Flag {
  id: number; flag_type: string; description: string | null
  severity: 'low' | 'medium' | 'high' | 'critical'; is_active: boolean
}

interface Participant {
  id: number; mrn: string; first_name: string; last_name: string
  preferred_name: string | null; dob: string; enrollment_status: string
  photo_path: string | null; site: { id: number; name: string }
  advance_directive_status: string | null
  advance_directive_type: string | null
  advance_directive_reviewed_at: string | null
  gender: string | null; pronouns: string | null
  ssn_last_four: string | null
  medicare_id: string | null; medicaid_id: string | null
  h_number: string | null; pace_contract_id: string | null
  primary_language: string; interpreter_needed: boolean
  interpreter_language: string | null
  enrollment_date: string | null
  disenrollment_date: string | null; disenrollment_reason: string | null
  nursing_facility_eligible: boolean; nf_certification_date: string | null
  day_center_days: string[] | null
  race: string | null; ethnicity: string | null; race_detail: string | null
  marital_status: string | null; veteran_status: string | null
  education_level: string | null; religion: string | null
  legal_representative_type: string | null
}

const props = defineProps<{
  participant:         Participant
  activeFlags:         Flag[]
  activeTab:           string
  canEdit:             boolean
  canDelete:           boolean
  hasBreakGlassAccess: boolean
  breakGlassExpiresAt: string | null
}>()

const emit = defineEmits<{ 'tab-change': [tab: string] }>()

// ── Display constants ──────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
  enrolled:    'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
  referred:    'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
  intake:      'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-800 dark:text-indigo-300',
  pending:     'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300',
  disenrolled: 'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400',
  deceased:    'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-500',
}

const FLAG_SEVERITY_COLORS: Record<string, string> = {
  low:      'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
  medium:   'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300 border-yellow-200 dark:border-yellow-800',
  high:     'bg-orange-100 dark:bg-orange-950/60 text-orange-800 dark:text-orange-300 border-orange-200 dark:border-orange-800',
  critical: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800',
}

const FLAG_LABELS: Record<string, string> = {
  wheelchair: 'Wheelchair', stretcher: 'Stretcher', oxygen: 'Oxygen',
  behavioral: 'Behavioral', fall_risk: 'Fall Risk', wandering_risk: 'Wandering Risk',
  isolation: 'Isolation', dnr: 'DNR', weight_bearing_restriction: 'Weight Bearing',
  dietary_restriction: 'Dietary', elopement_risk: 'Elopement Risk',
  hospice: 'Hospice', other: 'Other',
}

// ── Phase I2: clinical-risk chips (Beers / predictive risk / care gaps) ──────
const beersCount = ref<number | null>(null)
const riskBand = ref<string | null>(null)       // 'low' | 'medium' | 'high'
const riskScore = ref<number | null>(null)
const careGapCount = ref<number | null>(null)

// Phase O11 — sentinel flags so a 403/500 surfaces honestly as "—" instead
// of looking identical to a successful "0 hits" state.
const beersFailed = ref(false)
const riskFailed = ref(false)
const careGapsFailed = ref(false)

// Phase Q4 — eligibility chip (latest 270/271 check status)
const eligibilityStatus = ref<string | null>(null)
const eligibilityCheckedAt = ref<string | null>(null)
const eligibilityFailed = ref(false)

onMounted(async () => {
  const id = props.participant.id
  // Fire all three in parallel; swallow individual failures so a 403 on one
  // doesn't blank the rest. Track the failure so the template can render "—".
  axios.get(`/participants/${id}/beers-flags`).then(r => {
    const rows = r.data?.flags ?? r.data?.rows ?? []
    beersCount.value = Array.isArray(rows) ? rows.length : 0
  }).catch(() => { beersFailed.value = true })
  axios.get(`/participants/${id}/predictive-risk`).then(r => {
    const latest = r.data?.latest ?? {}
    // Prefer acute_event for demo-visible chip; fall back to disenrollment.
    const s = latest['acute_event'] ?? latest['disenrollment'] ?? null
    riskBand.value = s?.band ?? null
    riskScore.value = s?.score ?? null
  }).catch(() => { riskFailed.value = true })
  axios.get(`/participants/${id}/care-gaps`).then(r => {
    const gaps = r.data?.gaps ?? []
    careGapCount.value = Array.isArray(gaps) ? gaps.filter((g: any) => !g.satisfied).length : 0
  }).catch(() => { careGapsFailed.value = true })
  // Phase Q4 — latest eligibility check
  axios.get(`/participants/${id}/eligibility-checks`).then(r => {
    const latest = (r.data?.checks ?? [])[0]
    if (latest) {
      eligibilityStatus.value = latest.response_status
      eligibilityCheckedAt.value = latest.requested_at
    }
  }).catch(() => { eligibilityFailed.value = true })
})

const ELIG_CHIP_CLASS: Record<string, string> = {
  active:     'bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300 border-emerald-300 dark:border-emerald-700',
  inactive:   'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border-red-300 dark:border-red-700',
  unknown:    'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-200 dark:border-slate-700',
  not_active: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 border-amber-300 dark:border-amber-700',
}

const RISK_CHIP_CLASS: Record<string, string> = {
  high:   'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border-red-300 dark:border-red-700',
  medium: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 border-amber-300 dark:border-amber-700',
  low:    'bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300 border-emerald-300 dark:border-emerald-700',
}

// ── Photo state ────────────────────────────────────────────────────────────────

const photoPath      = ref<string | null>(props.participant.photo_path)
const photoUploading = ref(false)
const photoInputRef  = ref<HTMLInputElement | null>(null)

async function handlePhotoUpload(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  photoUploading.value = true
  const fd = new FormData()
  fd.append('photo', file)
  try {
    const res = await axios.post(`/participants/${props.participant.id}/photo`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    photoPath.value = res.data.photo_path
  } catch { alert('Photo upload failed. Max 4 MB, jpg/png/webp only.') }
  finally {
    photoUploading.value = false
    if (photoInputRef.value) photoInputRef.value.value = ''
  }
}

async function handlePhotoDelete() {
  if (!confirm('Remove participant photo?')) return
  try {
    await axios.delete(`/participants/${props.participant.id}/photo`)
    photoPath.value = null
  } catch { alert('Failed to remove photo.') }
}

// ── Edit modal ─────────────────────────────────────────────────────────────────

const showEditModal = ref(false)
const editSaving    = ref(false)
const editError     = ref('')

function blankForm() {
  const p = props.participant
  return {
    // Identity
    first_name:      p.first_name,
    last_name:       p.last_name,
    preferred_name:  p.preferred_name   ?? '',
    dob:             p.dob,
    gender:          p.gender           ?? '',
    pronouns:        p.pronouns         ?? '',
    // Identifiers
    ssn_last_four:   p.ssn_last_four    ?? '',
    medicare_id:     p.medicare_id      ?? '',
    medicaid_id:     p.medicaid_id      ?? '',
    h_number:        p.h_number         ?? '',
    pace_contract_id: p.pace_contract_id ?? '',
    // Language
    primary_language:   p.primary_language,
    interpreter_needed: p.interpreter_needed,
    interpreter_language: p.interpreter_language ?? '',
    // Enrollment
    enrollment_status:         p.enrollment_status,
    enrollment_date:           p.enrollment_date        ?? '',
    disenrollment_date:        p.disenrollment_date     ?? '',
    disenrollment_reason:      p.disenrollment_reason   ?? '',
    nursing_facility_eligible: p.nursing_facility_eligible,
    nf_certification_date:     p.nf_certification_date  ?? '',
    // Day Center schedule (array of weekday codes)
    day_center_days:           Array.isArray(p.day_center_days) ? [...p.day_center_days] : [],
    // Advance Directive
    advance_directive_status:      p.advance_directive_status      ?? '',
    advance_directive_type:        p.advance_directive_type        ?? '',
    advance_directive_reviewed_at: p.advance_directive_reviewed_at
      ? p.advance_directive_reviewed_at.slice(0, 10) : '',
    // Demographics
    race:            p.race            ?? '',
    ethnicity:       p.ethnicity       ?? '',
    race_detail:     p.race_detail     ?? '',
    marital_status:  p.marital_status  ?? '',
    veteran_status:  p.veteran_status  ?? '',
    education_level: p.education_level ?? '',
    religion:        p.religion        ?? '',
    // Legal
    legal_representative_type: p.legal_representative_type ?? '',
  }
}

const editForm = ref(blankForm())

function openEditModal() {
  editForm.value  = blankForm()
  editError.value = ''
  showEditModal.value = true
}

function nullIfEmpty(v: string) { return v.trim() || null }

function submitEdit() {
  editSaving.value = true
  editError.value  = ''
  const f = editForm.value
  router.patch(
    `/participants/${props.participant.id}`,
    {
      first_name:      f.first_name,
      last_name:       f.last_name,
      preferred_name:  nullIfEmpty(f.preferred_name),
      dob:             f.dob,
      gender:          nullIfEmpty(f.gender),
      pronouns:        nullIfEmpty(f.pronouns),
      ssn_last_four:   nullIfEmpty(f.ssn_last_four),
      medicare_id:     nullIfEmpty(f.medicare_id),
      medicaid_id:     nullIfEmpty(f.medicaid_id),
      h_number:        nullIfEmpty(f.h_number),
      pace_contract_id: nullIfEmpty(f.pace_contract_id),
      primary_language:    f.primary_language,
      interpreter_needed:  f.interpreter_needed,
      interpreter_language: nullIfEmpty(f.interpreter_language),
      enrollment_status:         f.enrollment_status,
      enrollment_date:           nullIfEmpty(f.enrollment_date),
      disenrollment_date:        nullIfEmpty(f.disenrollment_date),
      disenrollment_reason:      nullIfEmpty(f.disenrollment_reason),
      nursing_facility_eligible: f.nursing_facility_eligible,
      nf_certification_date:     nullIfEmpty(f.nf_certification_date),
      day_center_days:           (f.day_center_days ?? []).length > 0 ? f.day_center_days : null,
      advance_directive_status:      nullIfEmpty(f.advance_directive_status),
      advance_directive_type:        nullIfEmpty(f.advance_directive_type),
      advance_directive_reviewed_at: nullIfEmpty(f.advance_directive_reviewed_at),
      race:            nullIfEmpty(f.race),
      ethnicity:       nullIfEmpty(f.ethnicity),
      race_detail:     nullIfEmpty(f.race_detail),
      marital_status:  nullIfEmpty(f.marital_status),
      veteran_status:  nullIfEmpty(f.veteran_status),
      education_level: nullIfEmpty(f.education_level),
      religion:        nullIfEmpty(f.religion),
      legal_representative_type: nullIfEmpty(f.legal_representative_type),
    },
    {
      onSuccess: () => { showEditModal.value = false },
      onError: (errors) => {
        editError.value = Object.values(errors).flat()[0]?.toString() ?? 'Failed to save changes.'
      },
      onFinish: () => { editSaving.value = false },
    }
  )
}

// ── Deactivate ─────────────────────────────────────────────────────────────────

const showDeactivateModal = ref(false)
const deleting = ref(false)

function handleDelete() {
  deleting.value = true
  showDeactivateModal.value = false
  router.delete(`/participants/${props.participant.id}`)
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function age(dob: string): number {
  const d = new Date(dob.slice(0, 10) + 'T12:00:00')
  const now = new Date()
  let a = now.getFullYear() - d.getFullYear()
  if (now < new Date(now.getFullYear(), d.getMonth(), d.getDate())) a--
  return a
}

// Shared input/select classes
const inputCls = 'w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-400'
const sectionHdr = 'text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider pt-4 pb-1 border-b border-gray-100 dark:border-slate-700 mt-2'
</script>

<template>
  <!-- ── Header content (card wrapper + sticky are in Show.vue) ─────────── -->
  <div class="bg-white dark:bg-slate-800 px-6 py-5">
    <div class="flex items-start gap-4">

      <!-- Photo / initials avatar -->
      <div class="relative flex-shrink-0 group">
        <img
          v-if="photoPath"
          :src="`/storage/${photoPath}`"
          :alt="`${participant.first_name} ${participant.last_name}`"
          class="w-16 h-16 rounded-full object-cover border-2 border-gray-200 dark:border-slate-600"
          @error="photoPath = null"
        />
        <div
          v-else
          class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-400 to-indigo-500 flex items-center justify-center text-white text-xl font-bold"
        >
          {{ participant.first_name[0] }}{{ participant.last_name[0] }}
        </div>

        <template v-if="canEdit">
          <button
            type="button"
            :disabled="photoUploading"
            class="absolute inset-0 rounded-full bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer disabled:cursor-wait"
            title="Upload photo"
            @click="photoInputRef?.click()"
          >
            <div v-if="photoUploading" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
            <CameraIcon v-else class="w-4 h-4 text-white" />
          </button>
          <button
            v-if="photoPath"
            type="button"
            class="absolute -top-1 -right-1 rounded-full opacity-0 group-hover:opacity-100 transition-opacity"
            title="Remove photo"
            @click="handlePhotoDelete"
          >
            <XCircleIcon class="w-5 h-5 text-red-500 bg-white dark:bg-slate-800 rounded-full" />
          </button>
          <input ref="photoInputRef" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="handlePhotoUpload" />
        </template>
      </div>

      <!-- Name + metadata -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <h1 class="text-base font-semibold text-gray-900 dark:text-slate-100">
            {{ participant.first_name }} {{ participant.last_name }}
            <span v-if="participant.preferred_name" class="text-gray-400 dark:text-slate-500 font-normal text-sm ml-1">
              "{{ participant.preferred_name }}"
            </span>
          </h1>
          <span :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium', STATUS_COLORS[participant.enrollment_status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400']">
            {{ participant.enrollment_status }}
          </span>
        </div>

        <div class="flex items-center gap-3 mt-1 flex-wrap">
          <span class="font-mono text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-2 py-0.5 rounded">
            {{ participant.mrn }}
          </span>
          <span class="text-xs text-gray-500 dark:text-slate-400">
            {{ fmtDate(participant.dob) }}
            <span class="ml-1 text-gray-400 dark:text-slate-500">({{ age(participant.dob) }} yrs)</span>
          </span>
          <span class="text-xs bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 px-2 py-0.5 rounded">
            {{ participant.site.name }}
          </span>
        </div>

        <!-- Flags + directive badges -->
        <div v-if="activeFlags.length > 0 || participant.advance_directive_status" class="flex flex-wrap gap-1 mt-2">
          <span
            v-for="flag in activeFlags" :key="flag.id"
            :title="flag.description ?? flag.flag_type"
            :class="['inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium', FLAG_SEVERITY_COLORS[flag.severity] ?? '']"
          >{{ FLAG_LABELS[flag.flag_type] ?? flag.flag_type }}</span>

          <span v-if="participant.advance_directive_type === 'dnr'" class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-bold bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 border-red-300 dark:border-red-700">DNR</span>
          <span v-else-if="participant.advance_directive_type === 'polst'" class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-bold bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300 border-amber-300 dark:border-amber-700">POLST</span>
          <span v-else-if="participant.advance_directive_status === 'has_directive'" class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 border-gray-300 dark:border-slate-600">Advance Directive on File</span>
          <span v-else-if="['declined_directive','unknown','incapacitated_no_directive'].includes(participant.advance_directive_status ?? '')" class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 border-gray-300 dark:border-slate-600">No Directive</span>
        </div>

        <!-- Phase I2 — clinical-risk chips -->
        <div class="flex flex-wrap gap-1 mt-1" data-testid="clinical-risk-chips">
          <span
            v-if="beersCount !== null && beersCount > 0"
            title="Active medications flagged by AGS Beers Criteria"
            class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800 cursor-pointer"
            @click="emit('tab-change', 'medications')"
          >
            Beers · {{ beersCount }}
          </span>
          <span
            v-else-if="beersFailed"
            title="Beers data unavailable for your role."
            class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-gray-50 dark:bg-slate-800 text-gray-500 dark:text-slate-400 border-gray-200 dark:border-slate-700"
            data-testid="chip-beers-failed"
          >
            Beers · —
          </span>
          <span
            v-if="riskBand"
            :title="`Predictive acute-event risk: ${riskScore}/100 (${riskBand})`"
            :class="['inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium', RISK_CHIP_CLASS[riskBand] ?? 'bg-gray-100 text-gray-600']"
          >
            Risk · {{ riskBand }}<span v-if="riskScore !== null" class="ml-1 opacity-70">({{ riskScore }})</span>
          </span>
          <span
            v-else-if="riskFailed"
            title="Predictive risk data unavailable for your role."
            class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-gray-50 dark:bg-slate-800 text-gray-500 dark:text-slate-400 border-gray-200 dark:border-slate-700"
            data-testid="chip-risk-failed"
          >
            Risk · —
          </span>
          <span
            v-if="careGapCount !== null && careGapCount > 0"
            title="Open preventive-care gaps"
            :class="['inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium', careGapCount >= 3 ? 'bg-red-50 dark:bg-red-950/40 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800' : 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800']"
          >
            Care gaps · {{ careGapCount }}
          </span>
          <span
            v-else-if="careGapsFailed"
            title="Care-gap data unavailable for your role."
            class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-gray-50 dark:bg-slate-800 text-gray-500 dark:text-slate-400 border-gray-200 dark:border-slate-700"
            data-testid="chip-care-gaps-failed"
          >
            Care gaps · —
          </span>
          <!-- Phase Q4 — eligibility chip -->
          <span
            v-if="eligibilityStatus"
            :title="`Latest 270/271 eligibility ${eligibilityStatus} (checked ${eligibilityCheckedAt})`"
            :class="['inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium', ELIG_CHIP_CLASS[eligibilityStatus] ?? 'bg-gray-100 text-gray-600']"
            data-testid="chip-eligibility"
            @click="emit('tab-change', 'insurance')"
          >
            Eligibility · {{ eligibilityStatus }}
          </span>
          <span
            v-else-if="eligibilityFailed"
            title="Eligibility data unavailable for your role."
            class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-gray-50 dark:bg-slate-800 text-gray-500 dark:text-slate-400 border-gray-200 dark:border-slate-700"
            data-testid="chip-eligibility-failed"
          >
            Eligibility · —
          </span>
        </div>
      </div>

      <!-- Action buttons -->
      <div class="flex items-center gap-2 flex-shrink-0">
        <span v-if="hasBreakGlassAccess" class="inline-flex items-center gap-1 text-xs px-2 py-1 bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 rounded-md border border-red-300 dark:border-red-700">
          <BoltIcon class="w-3 h-3" />
          Emergency Access Active
        </span>
        <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors" @click="emit('tab-change', 'care_plan')">Care Plan</button>
        <a href="/schedule" class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">Schedule</a>
        <button v-if="canEdit" class="inline-flex items-center gap-1 text-xs px-3 py-1.5 border border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors" @click="openEditModal">
          <PencilSquareIcon class="w-3.5 h-3.5" />
          Edit
        </button>
        <button v-if="canDelete" :disabled="deleting" class="text-xs px-3 py-1.5 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors disabled:opacity-50" @click="showDeactivateModal = true">
          {{ deleting ? 'Deactivating...' : 'Deactivate' }}
        </button>
      </div>
    </div>
  </div>

  <!-- ── Edit Participant Modal ──────────────────────────────────────────────── -->
  <Teleport to="body">
    <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="showEditModal = false">
      <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">

        <!-- Modal header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700 shrink-0">
          <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Edit Participant</h2>
            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ participant.first_name }} {{ participant.last_name }} &middot; {{ participant.mrn }}</p>
          </div>
          <button class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 transition-colors" @click="showEditModal = false">
            <XCircleIcon class="w-6 h-6" />
          </button>
        </div>

        <!-- Scrollable body -->
        <div class="overflow-y-auto flex-1 px-6 pb-4">

          <!-- ── 1. Identity ── -->
          <p :class="sectionHdr">Identity</p>
          <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">First Name *</label>
              <input v-model="editForm.first_name" type="text" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Last Name *</label>
              <input v-model="editForm.last_name" type="text" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Preferred Name</label>
              <input v-model="editForm.preferred_name" type="text" placeholder="Optional nickname" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Date of Birth *</label>
              <input v-model="editForm.dob" type="date" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Gender</label>
              <input v-model="editForm.gender" type="text" placeholder="e.g. Male, Female, Non-binary" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Pronouns</label>
              <input v-model="editForm.pronouns" type="text" placeholder="e.g. she/her, he/him" :class="inputCls" />
            </div>
          </div>

          <!-- ── 2. Program Identifiers ── -->
          <p :class="sectionHdr">Program Identifiers</p>
          <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Medicare ID</label>
              <input v-model="editForm.medicare_id" type="text" placeholder="e.g. 1EG4-TE5-MK72" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Medicaid ID</label>
              <input v-model="editForm.medicaid_id" type="text" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">H-Number</label>
              <input v-model="editForm.h_number" type="text" placeholder="e.g. H1234" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">PACE Contract ID</label>
              <input v-model="editForm.pace_contract_id" type="text" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">SSN Last 4 Digits</label>
              <input v-model="editForm.ssn_last_four" type="text" maxlength="4" placeholder="0000" :class="inputCls" />
            </div>
          </div>

          <!-- ── 3. Language & Communication ── -->
          <p :class="sectionHdr">Language &amp; Communication</p>
          <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Primary Language</label>
              <input v-model="editForm.primary_language" type="text" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Interpreter Language</label>
              <input v-model="editForm.interpreter_language" type="text" placeholder="If different from primary" :class="inputCls" />
            </div>
            <div class="col-span-2 flex items-center gap-3">
              <input id="edit-interp" v-model="editForm.interpreter_needed" type="checkbox" class="h-4 w-4 rounded border-gray-300 dark:border-slate-600 text-blue-600" />
              <label for="edit-interp" class="text-sm text-gray-700 dark:text-slate-300">Interpreter required for appointments</label>
            </div>
          </div>

          <!-- ── 4. Enrollment ── -->
          <p :class="sectionHdr">Enrollment</p>
          <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Enrollment Status</label>
              <select name="enrollment_status" v-model="editForm.enrollment_status" :class="inputCls">
                <option value="referred">Referred</option>
                <option value="intake">Intake</option>
                <option value="pending">Pending</option>
                <option value="enrolled">Enrolled</option>
                <option value="disenrolled">Disenrolled</option>
                <option value="deceased">Deceased</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Enrollment Date</label>
              <input v-model="editForm.enrollment_date" type="date" :class="inputCls" />
            </div>
            <div class="col-span-2 flex items-center gap-3">
              <input id="edit-nf" v-model="editForm.nursing_facility_eligible" type="checkbox" class="h-4 w-4 rounded border-gray-300 dark:border-slate-600 text-blue-600" />
              <label for="edit-nf" class="text-sm text-gray-700 dark:text-slate-300">Nursing Facility Eligible (42 CFR 460.6)</label>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">NF Certification Date</label>
              <input v-model="editForm.nf_certification_date" type="date" :class="inputCls" />
            </div>

            <!-- Day Center recurring schedule -->
            <div class="col-span-2">
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Day Center Schedule</label>
              <div class="flex flex-wrap gap-2">
                <label
                  v-for="day in [
                    { code: 'mon', label: 'Mon' },
                    { code: 'tue', label: 'Tue' },
                    { code: 'wed', label: 'Wed' },
                    { code: 'thu', label: 'Thu' },
                    { code: 'fri', label: 'Fri' },
                    { code: 'sat', label: 'Sat' },
                    { code: 'sun', label: 'Sun' },
                  ]"
                  :key="day.code"
                  :class="[
                    'inline-flex items-center justify-center px-3 py-1.5 rounded-lg border cursor-pointer text-sm font-medium transition-colors select-none',
                    editForm.day_center_days?.includes(day.code)
                      ? 'bg-blue-600 text-white border-blue-600'
                      : 'bg-white dark:bg-slate-700 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600',
                  ]"
                >
                  <input
                    :value="day.code"
                    v-model="editForm.day_center_days"
                    type="checkbox"
                    class="sr-only"
                  />
                  {{ day.label }}
                </label>
              </div>
              <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">
                Which weekdays this participant is scheduled to attend the day center. Overrides available via appointments.
              </p>
            </div>
          </div>

          <!-- Disenrollment (conditional) -->
          <div v-if="editForm.enrollment_status === 'disenrolled'" class="grid grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Disenrollment Date</label>
              <input v-model="editForm.disenrollment_date" type="date" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Disenrollment Reason</label>
              <input v-model="editForm.disenrollment_reason" type="text" :class="inputCls" />
            </div>
          </div>

          <!-- ── 5. Advance Directive ── -->
          <p :class="sectionHdr">Advance Directive (42 CFR 460.96)</p>
          <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Directive Status</label>
              <select name="advance_directive_status" v-model="editForm.advance_directive_status" :class="inputCls">
                <option value="">-- Not set --</option>
                <option value="has_directive">Has Directive on File</option>
                <option value="declined_directive">Declined Directive</option>
                <option value="incapacitated_no_directive">Incapacitated / No Directive</option>
                <option value="unknown">Unknown</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Directive Type</label>
              <select name="advance_directive_type" v-model="editForm.advance_directive_type" :class="inputCls">
                <option value="">-- None --</option>
                <option value="dnr">DNR</option>
                <option value="polst">POLST</option>
                <option value="living_will">Living Will</option>
                <option value="healthcare_proxy">Healthcare Proxy</option>
                <option value="combined">Combined</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Last Reviewed</label>
              <input v-model="editForm.advance_directive_reviewed_at" type="date" :class="inputCls" />
            </div>
          </div>

          <!-- ── 6. Demographics & Social History ── -->
          <p :class="sectionHdr">Demographics &amp; Social History</p>
          <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Race</label>
              <select name="race" v-model="editForm.race" :class="inputCls">
                <option value="">-- Not specified --</option>
                <option value="white">White</option>
                <option value="black_african_american">Black / African American</option>
                <option value="asian">Asian</option>
                <option value="american_indian_alaska_native">American Indian / Alaska Native</option>
                <option value="native_hawaiian_pacific_islander">Native Hawaiian / Pacific Islander</option>
                <option value="multiracial">Multiracial</option>
                <option value="other">Other</option>
                <option value="unknown">Unknown</option>
                <option value="declined">Declined to specify</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Ethnicity</label>
              <select name="ethnicity" v-model="editForm.ethnicity" :class="inputCls">
                <option value="">-- Not specified --</option>
                <option value="hispanic_latino">Hispanic / Latino</option>
                <option value="not_hispanic_latino">Not Hispanic / Latino</option>
                <option value="unknown">Unknown</option>
                <option value="declined">Declined to specify</option>
              </select>
            </div>
            <div class="col-span-2">
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Race Detail (optional free text)</label>
              <input v-model="editForm.race_detail" type="text" placeholder="Additional detail if needed" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Marital Status</label>
              <select name="marital_status" v-model="editForm.marital_status" :class="inputCls">
                <option value="">-- Not specified --</option>
                <option value="single">Single</option>
                <option value="married">Married</option>
                <option value="domestic_partner">Domestic Partner</option>
                <option value="divorced">Divorced</option>
                <option value="widowed">Widowed</option>
                <option value="separated">Separated</option>
                <option value="unknown">Unknown</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Veteran Status</label>
              <select name="veteran_status" v-model="editForm.veteran_status" :class="inputCls">
                <option value="">-- Not specified --</option>
                <option value="not_veteran">Not a veteran</option>
                <option value="veteran_active">Veteran (active benefits)</option>
                <option value="veteran_inactive">Veteran (no active benefits)</option>
                <option value="unknown">Unknown</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Education Level</label>
              <select name="education_level" v-model="editForm.education_level" :class="inputCls">
                <option value="">-- Not specified --</option>
                <option value="less_than_high_school">Less than high school</option>
                <option value="high_school_ged">High school / GED</option>
                <option value="some_college">Some college</option>
                <option value="associates">Associate's degree</option>
                <option value="bachelors">Bachelor's degree</option>
                <option value="graduate">Graduate degree</option>
                <option value="unknown">Unknown</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Religion</label>
              <input v-model="editForm.religion" type="text" placeholder="Optional" :class="inputCls" />
            </div>
          </div>

          <!-- ── 7. Legal Representative ── -->
          <p :class="sectionHdr">Legal Representative</p>
          <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Representative Type</label>
              <select name="legal_representative_type" v-model="editForm.legal_representative_type" :class="inputCls">
                <option value="">-- None / Self --</option>
                <option value="self">Self</option>
                <option value="legal_guardian">Legal Guardian</option>
                <option value="durable_poa">Durable Power of Attorney</option>
                <option value="healthcare_proxy">Healthcare Proxy</option>
                <option value="court_appointed">Court Appointed</option>
                <option value="other">Other</option>
              </select>
            </div>
          </div>

          <!-- Error message -->
          <div v-if="editError" class="mt-4 rounded-lg bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 px-4 py-2.5 text-sm text-red-700 dark:text-red-300">
            {{ editError }}
          </div>
        </div>

        <!-- Modal footer -->
        <div class="flex items-center justify-between px-6 py-4 border-t border-gray-200 dark:border-slate-700 shrink-0 bg-gray-50 dark:bg-slate-900/50 rounded-b-2xl">
          <p class="text-xs text-gray-400 dark:text-slate-500">* Required fields. Some fields only editable by Enrollment / IT Admin staff.</p>
          <div class="flex gap-3">
            <button class="text-sm px-4 py-2 text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 transition-colors" @click="showEditModal = false">Cancel</button>
            <button :disabled="editSaving" class="text-sm px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors font-medium" @click="submitEdit">
              {{ editSaving ? 'Saving...' : 'Save Changes' }}
            </button>
          </div>
        </div>

      </div>
    </div>
  </Teleport>

  <!-- ── Deactivate Modal ────────────────────────────────────────────────────── -->
  <div v-if="showDeactivateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg mx-4 p-6">
      <div class="flex items-start gap-3 mb-4">
        <div class="flex-shrink-0 w-9 h-9 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center">
          <ExclamationTriangleIcon class="w-5 h-5 text-red-600 dark:text-red-400" />
        </div>
        <div>
          <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Deactivate participant record?</h3>
          <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">{{ participant.first_name }} {{ participant.last_name }} &middot; {{ participant.mrn }}</p>
        </div>
      </div>
      <p class="text-sm text-gray-600 dark:text-slate-400 mb-4">
        This is a data correction tool, not a clinical workflow. Use it only when a record was created in error.
        The record will be hidden but is not permanently deleted.
      </p>
      <div class="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-slate-700">
        <button class="text-sm px-4 py-2 text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200" @click="showDeactivateModal = false">Cancel</button>
        <button class="text-sm px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors" @click="handleDelete">Yes, deactivate</button>
      </div>
    </div>
  </div>
</template>
