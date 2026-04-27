<script setup lang="ts">
// ─── OverviewTab.vue (Facesheet) ──────────────────────────────────────────────
// PACE Facesheet. Full-width document layout: dark header, IDs strip, 3-col
// body (Address+Contacts+Demographics / Allergies+Diagnoses / Vitals+Insurance+
// Directive), HIPAA footer. Print uses visibility isolation so only the
// facesheet renders: nav, header, and tab bar are all suppressed.
// ─────────────────────────────────────────────────────────────────────────────

import { computed, onMounted, onUnmounted, ref } from 'vue'
import { PrinterIcon, ExclamationTriangleIcon, ShieldExclamationIcon, PencilSquareIcon, CalendarDaysIcon } from '@heroicons/vue/24/outline'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

interface Participant {
  id: number; mrn: string; first_name: string; last_name: string
  preferred_name: string | null; dob: string; gender: string | null
  pronouns: string | null; ssn_last_four: string | null
  medicare_id: string | null; medicaid_id: string | null
  pace_contract_id: string | null; h_number: string | null
  primary_language: string; interpreter_needed: boolean
  interpreter_language: string | null; enrollment_status: string
  enrollment_date: string | null; disenrollment_date: string | null
  disenrollment_reason: string | null; nursing_facility_eligible: boolean
  nf_certification_date: string | null
  nf_certification_expires_at: string | null
  nf_recert_waived: boolean
  nf_recert_waived_reason: string | null
  advance_directive_status: string | null; advance_directive_type: string | null
  advance_directive_reviewed_at: string | null
  race: string | null; ethnicity: string | null; marital_status: string | null
  veteran_status: string | null; education_level: string | null
  religion: string | null; photo_path: string | null
  day_center_days: string[] | null
  site: { id: number; name: string }
  tenant: { id: number; name: string }; created_at: string
}

interface Address {
  id: number; address_type: string; is_primary: boolean
  street: string; city: string; state: string; zip: string
}

interface Contact {
  id: number; first_name: string; last_name: string
  relationship: string | null; phone_primary: string | null
  phone_secondary: string | null; email: string | null
  is_emergency_contact: boolean; is_legal_guardian: boolean
  priority_order: number
}

interface Vital {
  id: number; recorded_at: string
  weight_lbs: number | null; height_in: number | null; bmi: number | null
  bp_systolic: number | null; bp_diastolic: number | null
  pulse: number | null; temperature_f: number | null; o2_saturation: number | null
}

interface Insurance {
  id: number; payer_name: string; payer_type: string
  plan_name: string | null; member_id: string | null
  is_active: boolean
}

const props = defineProps<{
  participant: Participant
  flags?: unknown[]
  problems?: unknown[]
  allergies?: Record<string, unknown[]>
  addresses?: Address[]
  contacts?: Contact[]
  vitals?: Vital[]
  insurances?: Insurance[]
}>()

// ── Photo fallback ─────────────────────────────────────────────────────────────
const photoError = ref(false)

// ── Print isolation ────────────────────────────────────────────────────────────
// Portrait letter. Blanket CSS reset strips ALL background colors and forces
// monochrome text: no dark-mode colors bleed through. Specific class selectors
// (.facesheet-*) restore structural borders and muted text where needed.
// overflow: hidden hard-clips at the page boundary for one-page guarantee.
let printStyle: HTMLStyleElement | null = null
onMounted(() => {
  printStyle = document.createElement('style')
  printStyle.id = 'facesheet-print-styles'
  printStyle.textContent = `
    @media print {
      @page { size: letter portrait; margin: 0.45in; }

      * { visibility: hidden !important; box-shadow: none !important; }
      #facesheet-print-root,
      #facesheet-print-root * { visibility: visible !important; }

      /* Root: fixed to page, overflow clips at boundary */
      #facesheet-print-root {
        position: fixed !important;
        inset: 0 !important;
        width: 100% !important;
        overflow: hidden !important;
        z-index: 99999 !important;
        margin: 0 !important;
        padding: 0 !important;
        font-size: 10px !important;
        line-height: 1.4 !important;
        background: white !important;
        color: #111827 !important;
      }

      /* Blanket reset: strip every background color and force dark text */
      #facesheet-print-root,
      #facesheet-print-root * {
        background-color: white !important;
        background-image: none !important;
        color: #111827 !important;
        border-color: #e5e7eb !important;
      }

      /* Header: thick bottom border replaces dark background */
      #facesheet-print-root .facesheet-header {
        border-bottom: 2px solid #1f2937 !important;
        padding-bottom: 10px !important;
      }

      /* IDs strip: gray top border */
      #facesheet-print-root .facesheet-ids-strip {
        border-top-color: #9ca3af !important;
        margin-top: 8px !important;
        padding-top: 6px !important;
      }

      /* Allergy banner: left accent bar instead of red bg */
      #facesheet-print-root .facesheet-allergy-banner,
      #facesheet-print-root .facesheet-allergy-banner * {
        color: #991b1b !important;
        border-left: 4px solid #dc2626 !important;
        padding-left: 10px !important;
      }

      /* Section headings: slightly dimmer */
      #facesheet-print-root h3 {
        color: #374151 !important;
        border-bottom-color: #d1d5db !important;
      }

      /* ICD code chips: keep faint background for legibility */
      #facesheet-print-root .facesheet-icd-code {
        background-color: #f3f4f6 !important;
        color: #374151 !important;
        border: 1px solid #d1d5db !important;
      }

      /* Allergy severity badges: plain bordered */
      #facesheet-print-root .facesheet-severity-badge {
        background-color: white !important;
        border-color: #9ca3af !important;
        color: #374151 !important;
      }

      /* Footer: muted */
      #facesheet-print-root .facesheet-footer,
      #facesheet-print-root .facesheet-footer * {
        color: #6b7280 !important;
        border-top-color: #d1d5db !important;
      }

      /* Photo: gray border */
      #facesheet-print-root img {
        border-color: #9ca3af !important;
      }

      /* Column grid: thin separator lines */
      #facesheet-print-root .facesheet-grid {
        background-color: #d1d5db !important;
        gap: 1px !important;
      }

      [data-no-print] { display: none !important; visibility: hidden !important; }
    }
  `
  document.head.appendChild(printStyle)
})
onUnmounted(() => { printStyle?.remove() })

function printFacesheet() {
  window.print()
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function fmtDateTime(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val)
  return d.toLocaleString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric',
    hour: 'numeric', minute: '2-digit', hour12: true,
  })
}

function age(dob: string): number {
  const d = new Date(dob.slice(0, 10) + 'T12:00:00')
  const now = new Date()
  let a = now.getFullYear() - d.getFullYear()
  if (now < new Date(now.getFullYear(), d.getMonth(), d.getDate())) a--
  return a
}

// Format day_center_days array to readable label (e.g. ["mon","wed","fri"] → "Mon, Wed, Fri")
function formatDayCenterDays(days: string[] | null | undefined): string {
  if (!Array.isArray(days) || days.length === 0) return 'Not scheduled'
  const order = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
  const labels: Record<string, string> = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' }
  return order.filter(d => days.includes(d)).map(d => labels[d]).join(', ')
}

// ── Inline Day Center schedule editor (focused mini-modal) ────────────────────
const showScheduleEditor = ref(false)
const scheduleDraft = ref<string[]>([])
const scheduleSaving = ref(false)
const scheduleError = ref('')
const DAY_CODES = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
const DAY_LABELS: Record<string, string> = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' }

function openScheduleEditor() {
  scheduleDraft.value = Array.isArray(props.participant.day_center_days) ? [...props.participant.day_center_days] : []
  scheduleError.value = ''
  showScheduleEditor.value = true
}

function toggleDay(code: string) {
  const idx = scheduleDraft.value.indexOf(code)
  if (idx >= 0) scheduleDraft.value.splice(idx, 1)
  else scheduleDraft.value.push(code)
}

async function saveSchedule() {
  scheduleSaving.value = true
  scheduleError.value = ''
  try {
    await axios.patch(`/participants/${props.participant.id}`, {
      day_center_days: scheduleDraft.value.length > 0 ? scheduleDraft.value : null,
    })
    showScheduleEditor.value = false
    router.reload({ only: ['participant'] })
  } catch (err: any) {
    scheduleError.value = err.response?.data?.message ?? 'Failed to save schedule.'
  } finally {
    scheduleSaving.value = false
  }
}

const printDate = new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })

// ── Computed ──────────────────────────────────────────────────────────────────
const activeProblems = computed(() =>
  ((props.problems ?? []) as Array<{ status: string; icd10_code: string; icd10_description: string; is_primary_diagnosis: boolean }>)
    .filter(p => p.status === 'active' || p.status === 'chronic')
    .slice(0, 10)
)

const lifeThreateningAllergies = computed(() =>
  Object.values(props.allergies ?? {}).flat().filter((a: unknown) =>
    (a as { severity: string }).severity === 'life_threatening'
  ) as Array<{ id: number; allergen_name: string; reaction_description: string | null }>
)

// Phase 2 (MVP roadmap): NF-LOC recert banner: §460.160(b)(2)
const nfLocDays = computed<number | null>(() => {
  const p = props.participant
  if (p.nf_recert_waived) return null
  if (!p.nf_certification_expires_at) return null
  const exp = new Date(p.nf_certification_expires_at.slice(0, 10) + 'T12:00:00').getTime()
  const now = new Date().getTime()
  return Math.floor((exp - now) / (1000 * 60 * 60 * 24))
})
const nfLocBannerShow = computed(() => {
  const p = props.participant
  if (p.enrollment_status !== 'enrolled') return false
  if (p.nf_recert_waived) return false
  const d = nfLocDays.value
  return d !== null && d <= 60
})
const nfLocBannerLevel = computed<'overdue' | 'soon' | 'info'>(() => {
  const d = nfLocDays.value ?? 99999
  if (d < 0) return 'overdue'
  if (d <= 30) return 'soon'
  return 'info'
})

const allAllergiesCount = computed(() =>
  Object.values(props.allergies ?? {}).flat().length
)

const homeAddress = computed(() =>
  (props.addresses ?? []).find(a => a.address_type === 'home' && a.is_primary)
  ?? (props.addresses ?? []).find(a => a.address_type === 'home')
  ?? (props.addresses ?? [])[0]
  ?? null
)

const emergencyContacts = computed(() =>
  (props.contacts ?? []).filter(c => c.is_emergency_contact)
    .sort((a, b) => a.priority_order - b.priority_order).slice(0, 3)
)

const latestVital = computed(() =>
  ((props.vitals ?? []) as Vital[])[0] ?? null
)

const activeInsurances = computed(() =>
  ((props.insurances ?? []) as Insurance[]).filter(i => i.is_active)
)

const SEVERITY_ORDER: Record<string, number> = {
  life_threatening: 0, severe: 1, moderate: 2, mild: 3, intolerance: 4,
}
const allAllergyList = computed(() =>
  Object.values(props.allergies ?? {}).flat() as Array<{
    id: number; allergen_name: string; allergy_type: string
    reaction_description: string | null; severity: string
  }>
)
const nonLifeThreateningAllergies = computed(() =>
  allAllergyList.value
    .filter(a => a.severity !== 'life_threatening')
    .sort((a, b) => (SEVERITY_ORDER[a.severity] ?? 9) - (SEVERITY_ORDER[b.severity] ?? 9))
)

// ── Display maps ──────────────────────────────────────────────────────────────
const STATUS_COLORS: Record<string, string> = {
  enrolled:    'bg-green-100 text-green-800',
  referred:    'bg-blue-100 text-blue-800',
  intake:      'bg-indigo-100 text-indigo-800',
  pending:     'bg-yellow-100 text-yellow-800',
  disenrolled: 'bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-slate-300',
  deceased:    'bg-gray-300 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
}

const SEVERITY_BADGE_COLORS: Record<string, string> = {
  severe:      'bg-orange-100 dark:bg-orange-950/60 text-orange-800 dark:text-orange-300 border-orange-200 dark:border-orange-800',
  moderate:    'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300 border-yellow-200 dark:border-yellow-800',
  mild:        'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 border-gray-200 dark:border-slate-700',
  intolerance: 'bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
}

const ALLERGY_TYPE_LABELS: Record<string, string> = {
  drug: 'Drug', food: 'Food', environmental: 'Environmental',
  dietary_restriction: 'Dietary', latex: 'Latex', contrast: 'Contrast',
}

const PAYER_TYPE_LABELS: Record<string, string> = {
  medicare_a: 'Medicare Part A', medicare_b: 'Medicare Part B',
  medicare_d: 'Medicare Part D', medicaid: 'Medicaid',
  commercial: 'Commercial', managed_care: 'Managed Care', other: 'Other',
}

const DIRECTIVE_LABELS: Record<string, string> = {
  has_directive: 'Has Directive on File',
  declined_directive: 'Declined Directive',
  incapacitated_no_directive: 'Incapacitated / No Directive',
  unknown: 'Unknown',
}

const DIRECTIVE_TYPE_LABELS: Record<string, string> = {
  dnr: 'DNR', polst: 'POLST', living_will: 'Living Will',
  healthcare_proxy: 'Healthcare Proxy', combined: 'Combined',
}
</script>

<template>
  <div id="facesheet-print-root" class="w-full min-h-full">

    <!-- Phase 2 (MVP roadmap): NF-LOC recert alert banner: §460.160(b)(2) -->
    <div
      v-if="nfLocBannerShow"
      :class="[
        'mx-6 mt-4 rounded-lg border px-4 py-2.5 flex items-start gap-3 print:hidden',
        nfLocBannerLevel === 'overdue'
          ? 'bg-red-50 dark:bg-red-950/40 border-red-300 dark:border-red-700 text-red-800 dark:text-red-200'
          : nfLocBannerLevel === 'soon'
            ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-200'
            : 'bg-blue-50 dark:bg-blue-950/40 border-blue-300 dark:border-blue-700 text-blue-800 dark:text-blue-200',
      ]"
    >
      <ExclamationTriangleIcon class="w-4 h-4 mt-0.5 shrink-0" />
      <div class="text-xs leading-relaxed">
        <p class="font-semibold">
          NF-LOC Recertification {{ nfLocBannerLevel === 'overdue' ? 'OVERDUE' : 'Due Soon' }}
        </p>
        <p>
          <template v-if="participant.nf_certification_expires_at">
            Expires {{ fmtDate(participant.nf_certification_expires_at) }}
            <span v-if="nfLocDays !== null">
              ({{ nfLocDays < 0 ? `${Math.abs(nfLocDays)} days overdue` : nfLocDays === 0 ? 'due today' : `${nfLocDays} days remaining` }})
            </span>
          </template>
          <template v-else>Expiration date not set.</template>
          <span class="italic"> &nbsp;42 CFR §460.160(b)(2).</span>
        </p>
      </div>
    </div>

    <!-- ── Header ───────────────────────────────────────────────────────────── -->
    <div class="facesheet-header bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white px-6 py-4 print:py-3 print:px-4">
      <div class="flex items-start justify-between gap-4 flex-wrap">

        <!-- Left: photo + name/demographics -->
        <div class="flex items-start gap-4 flex-1 min-w-0">

          <!-- Participant photo (shown on screen and in print) -->
          <div v-if="participant.photo_path && !photoError" class="shrink-0">
            <img
              :src="`/storage/${participant.photo_path}`"
              :alt="`${participant.first_name} ${participant.last_name}`"
              class="w-14 h-14 rounded-full object-cover border-2 border-slate-400 dark:border-slate-500"
              style="print-color-adjust: exact; -webkit-print-color-adjust: exact;"
              @error="photoError = true"
            />
          </div>

          <div class="flex-1 min-w-0">
            <!-- Name + badges -->
            <div class="flex items-center gap-3 flex-wrap">
              <h1 class="text-2xl font-bold tracking-tight leading-tight print:text-xl text-slate-900 dark:text-white">
                {{ participant.last_name }}, {{ participant.first_name }}
                <span v-if="participant.preferred_name" class="text-slate-500 dark:text-slate-400 font-normal text-lg ml-1">
                  "{{ participant.preferred_name }}"
                </span>
              </h1>
              <span :class="['text-xs px-2.5 py-1 rounded-full font-semibold uppercase tracking-wide shrink-0', STATUS_COLORS[participant.enrollment_status] ?? 'bg-gray-200 text-gray-700']">
                {{ participant.enrollment_status }}
              </span>
              <span
                v-if="lifeThreateningAllergies.length > 0"
                class="flex items-center gap-1 bg-red-600 text-white text-xs px-2.5 py-1 rounded-full font-bold uppercase shrink-0"
              >
                <ExclamationTriangleIcon class="w-3 h-3 shrink-0" />
                Allergy Alert
              </span>
            </div>

            <!-- Demographics strip -->
            <div class="flex flex-wrap gap-x-5 gap-y-1 mt-2 print:mt-1 text-sm print:text-xs text-slate-600 dark:text-slate-300">
              <span>DOB: <span class="text-slate-900 dark:text-white font-medium">{{ fmtDate(participant.dob) }}</span> ({{ age(participant.dob) }} yrs)</span>
              <span v-if="participant.gender">Sex: <span class="text-slate-900 dark:text-white font-medium capitalize">{{ participant.gender }}</span></span>
              <span v-if="participant.pronouns">Pronouns: <span class="text-slate-900 dark:text-white font-medium">{{ participant.pronouns }}</span></span>
              <span>Site: <span class="text-slate-900 dark:text-white font-medium">{{ participant.site.name }}</span></span>
              <span>Language: <span class="text-slate-900 dark:text-white font-medium">{{ participant.primary_language }}</span>
                <span v-if="participant.interpreter_needed" class="text-amber-600 dark:text-amber-400 ml-1 font-medium">(interpreter needed)</span>
              </span>
              <span v-if="participant.nursing_facility_eligible" class="text-amber-600 dark:text-amber-300 font-semibold">NF Eligible</span>
              <span v-if="Array.isArray(participant.day_center_days) && participant.day_center_days.length > 0">
                Day Center:
                <span class="text-slate-900 dark:text-white font-medium">{{ formatDayCenterDays(participant.day_center_days) }}</span>
              </span>
            </div>
          </div>
        </div>

        <!-- Right: confidential + print date + print button -->
        <div class="flex flex-col items-end gap-1.5 shrink-0">
          <span class="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300 font-semibold uppercase tracking-widest">
            <ShieldExclamationIcon class="w-3.5 h-3.5" />
            Confidential
          </span>
          <span class="text-xs text-slate-500 dark:text-slate-400">Printed {{ printDate }}</span>
          <button
            data-no-print
            class="mt-1 inline-flex items-center gap-1.5 text-xs px-3 py-1.5 bg-slate-200 dark:bg-slate-600 hover:bg-slate-300 dark:hover:bg-slate-500 text-slate-700 dark:text-white rounded-lg transition-colors"
            @click="printFacesheet"
          >
            <PrinterIcon class="w-3.5 h-3.5" />
            Print Facesheet
          </button>
          <!-- Phase 5 (MVP roadmap): EHI export per 21st Century Cures Act § 4004 -->
          <a
            data-no-print
            :href="`/participants/${participant.id}/ehi-export`"
            class="mt-1 inline-flex items-center gap-1.5 text-xs px-3 py-1.5 bg-indigo-100 dark:bg-indigo-900/40 hover:bg-indigo-200 dark:hover:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300 rounded-lg transition-colors"
            title="Generate an Electronic Health Information export (FHIR Bundle + clinical data ZIP)"
          >
            Request EHI Export
          </a>
        </div>
      </div>

      <!-- IDs strip -->
      <div class="facesheet-ids-strip flex flex-wrap gap-x-6 gap-y-1 mt-3 pt-3 print:mt-2 print:pt-2 border-t border-slate-300 dark:border-slate-700 text-xs">
        <span class="text-slate-500 dark:text-slate-400">MRN: <span class="font-mono text-slate-900 dark:text-white">{{ participant.mrn }}</span></span>
        <span v-if="participant.medicare_id" class="text-slate-500 dark:text-slate-400">Medicare: <span class="font-mono text-slate-900 dark:text-white">{{ participant.medicare_id }}</span></span>
        <span v-if="participant.medicaid_id" class="text-slate-500 dark:text-slate-400">Medicaid: <span class="font-mono text-slate-900 dark:text-white">{{ participant.medicaid_id }}</span></span>
        <span v-if="participant.h_number" class="text-slate-500 dark:text-slate-400">H#: <span class="font-mono text-slate-900 dark:text-white">{{ participant.h_number }}</span></span>
        <span v-if="participant.pace_contract_id" class="text-slate-500 dark:text-slate-400">Contract: <span class="font-mono text-slate-900 dark:text-white">{{ participant.pace_contract_id }}</span></span>
        <span v-if="participant.ssn_last_four" class="text-slate-500 dark:text-slate-400">SSN: <span class="font-mono text-slate-900 dark:text-white">***-**-{{ participant.ssn_last_four }}</span></span>
        <span class="text-slate-500 dark:text-slate-400">Enrolled: <span class="text-slate-800 dark:text-slate-100">{{ fmtDate(participant.enrollment_date) }}</span></span>
      </div>
    </div>

    <!-- ── Life-Threatening Allergy Banner ────────────────────────────────── -->
    <div v-if="lifeThreateningAllergies.length > 0" class="facesheet-allergy-banner bg-red-600 text-white px-6 py-3 print:py-2 print:px-4">
      <div class="flex items-start gap-2">
        <ExclamationTriangleIcon class="w-5 h-5 shrink-0 mt-0.5" />
        <div class="text-sm">
          <span class="font-bold uppercase tracking-wide">Life-Threatening Allergies: </span>
          <span v-for="(a, i) in lifeThreateningAllergies" :key="a.id">
            <span class="font-semibold">{{ a.allergen_name }}</span><span v-if="a.reaction_description"> ({{ a.reaction_description }})</span><span v-if="i < lifeThreateningAllergies.length - 1">, </span>
          </span>
        </div>
      </div>
    </div>

    <!-- ── 3-Column Body ──────────────────────────────────────────────────── -->
    <div class="facesheet-grid grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 print:grid-cols-3 gap-px bg-gray-200 dark:bg-slate-700">

      <!-- Column 1: Address + Contacts + Demographics -->
      <div class="bg-white dark:bg-slate-800 p-5 print:p-3 space-y-5 print:space-y-3">

        <section>
          <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 pb-1 border-b border-gray-100 dark:border-slate-700">
            Home Address
          </h3>
          <div v-if="homeAddress" class="text-sm text-gray-800 dark:text-slate-200 space-y-0.5">
            <p class="font-medium">{{ homeAddress.street }}</p>
            <p class="text-gray-600 dark:text-slate-400">{{ homeAddress.city }}, {{ homeAddress.state }} {{ homeAddress.zip }}</p>
          </div>
          <p v-else class="text-sm text-gray-400 dark:text-slate-500 italic">No address on file</p>
        </section>

        <section>
          <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 pb-1 border-b border-gray-100 dark:border-slate-700">
            Emergency Contacts
          </h3>
          <div v-if="emergencyContacts.length === 0" class="text-sm text-gray-400 dark:text-slate-500 italic">None on file</div>
          <ul v-else class="space-y-2 print:space-y-1">
            <li v-for="contact in emergencyContacts" :key="contact.id">
              <div class="text-sm font-semibold text-gray-900 dark:text-slate-100">
                {{ contact.first_name }} {{ contact.last_name }}
                <span v-if="contact.relationship" class="text-gray-500 dark:text-slate-400 font-normal text-xs"> ({{ contact.relationship }})</span>
              </div>
              <div v-if="contact.phone_primary" class="text-xs text-gray-600 dark:text-slate-400 mt-0.5">{{ contact.phone_primary }}</div>
              <div v-if="contact.phone_secondary" class="text-xs text-gray-500 dark:text-slate-500">Alt: {{ contact.phone_secondary }}</div>
              <div v-if="contact.is_legal_guardian" class="text-xs text-indigo-600 dark:text-indigo-400 font-medium">Legal Guardian</div>
            </li>
          </ul>
        </section>

        <section>
          <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 pb-1 border-b border-gray-100 dark:border-slate-700">
            Demographics
          </h3>
          <dl class="space-y-1.5 text-sm">
            <div v-if="participant.race" class="flex gap-2">
              <dt class="text-gray-500 dark:text-slate-400 w-24 shrink-0 text-xs">Race</dt>
              <dd class="text-gray-800 dark:text-slate-200 capitalize text-xs">{{ participant.race.replace(/_/g, ' ') }}</dd>
            </div>
            <div v-if="participant.ethnicity" class="flex gap-2">
              <dt class="text-gray-500 dark:text-slate-400 w-24 shrink-0 text-xs">Ethnicity</dt>
              <dd class="text-gray-800 dark:text-slate-200 capitalize text-xs">{{ participant.ethnicity.replace(/_/g, ' ') }}</dd>
            </div>
            <div v-if="participant.marital_status" class="flex gap-2">
              <dt class="text-gray-500 dark:text-slate-400 w-24 shrink-0 text-xs">Marital</dt>
              <dd class="text-gray-800 dark:text-slate-200 capitalize text-xs">{{ participant.marital_status.replace(/_/g, ' ') }}</dd>
            </div>
            <div v-if="participant.veteran_status" class="flex gap-2">
              <dt class="text-gray-500 dark:text-slate-400 w-24 shrink-0 text-xs">Veteran</dt>
              <dd class="text-gray-800 dark:text-slate-200 capitalize text-xs">{{ participant.veteran_status.replace(/_/g, ' ') }}</dd>
            </div>
            <div v-if="participant.education_level" class="flex gap-2">
              <dt class="text-gray-500 dark:text-slate-400 w-24 shrink-0 text-xs">Education</dt>
              <dd class="text-gray-800 dark:text-slate-200 capitalize text-xs">{{ participant.education_level.replace(/_/g, ' ') }}</dd>
            </div>
            <div v-if="participant.religion" class="flex gap-2">
              <dt class="text-gray-500 dark:text-slate-400 w-24 shrink-0 text-xs">Religion</dt>
              <dd class="text-gray-800 dark:text-slate-200 text-xs">{{ participant.religion }}</dd>
            </div>
            <div class="flex gap-2 items-center">
              <dt class="text-gray-500 dark:text-slate-400 w-24 shrink-0 text-xs">Day Center</dt>
              <dd class="text-gray-800 dark:text-slate-200 text-xs flex-1">
                {{ formatDayCenterDays(participant.day_center_days) }}
              </dd>
              <button
                type="button"
                class="shrink-0 inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 hover:underline print:hidden"
                :title="'Edit day-center schedule'"
                @click="openScheduleEditor"
              >
                <PencilSquareIcon class="w-3.5 h-3.5" />
                Edit
              </button>
            </div>
            <p v-if="!participant.race && !participant.ethnicity && !participant.marital_status && !participant.veteran_status && !participant.education_level && !participant.religion" class="text-xs text-gray-400 dark:text-slate-500 italic">
              No demographic details on file
            </p>
          </dl>
        </section>
      </div>

      <!-- Column 2: Allergies + Diagnoses -->
      <div class="bg-white dark:bg-slate-800 p-5 print:p-3 space-y-5 print:space-y-3">

        <section>
          <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 pb-1 border-b border-gray-100 dark:border-slate-700">
            Allergies &amp; Restrictions ({{ allAllergiesCount }})
          </h3>
          <div v-if="allAllergiesCount === 0" class="text-sm text-gray-400 dark:text-slate-500 italic">None on file</div>
          <div v-else class="space-y-2">
            <div
              v-if="lifeThreateningAllergies.length > 0"
              class="rounded border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/60 px-3 py-2"
            >
              <p class="text-xs font-bold text-red-700 dark:text-red-300 uppercase mb-1.5">Life-Threatening</p>
              <ul class="space-y-1">
                <li v-for="a in lifeThreateningAllergies" :key="a.id" class="text-sm text-red-800 dark:text-red-300 font-semibold">
                  {{ a.allergen_name }}
                  <span v-if="a.reaction_description" class="font-normal text-red-600 dark:text-red-400">: {{ a.reaction_description }}</span>
                </li>
              </ul>
            </div>
            <ul v-if="nonLifeThreateningAllergies.length > 0" class="space-y-1.5 mt-1">
              <li
                v-for="(a, idx) in nonLifeThreateningAllergies"
                :key="a.id"
                :class="['flex items-start gap-2 text-sm', idx >= 6 ? 'print:hidden' : '']"
              >
                <span class="flex-1 text-gray-800 dark:text-slate-200 font-medium leading-snug">
                  {{ a.allergen_name }}
                  <span class="text-xs font-normal text-gray-400 dark:text-slate-500 ml-1">
                    {{ ALLERGY_TYPE_LABELS[a.allergy_type] ?? a.allergy_type }}
                  </span>
                </span>
                <span v-if="a.reaction_description" class="text-xs text-gray-500 dark:text-slate-400 shrink-0">
                  {{ a.reaction_description }}
                </span>
                <span :class="['facesheet-severity-badge text-xs px-1.5 py-0.5 rounded-full border font-medium shrink-0 capitalize', SEVERITY_BADGE_COLORS[a.severity] ?? 'bg-gray-100 dark:bg-slate-800 text-gray-500 border-gray-200']">
                  {{ a.severity.replace('_', ' ') }}
                </span>
              </li>
            </ul>
          </div>
        </section>

        <section>
          <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 pb-1 border-b border-gray-100 dark:border-slate-700">
            Active Diagnoses ({{ activeProblems.length }})
          </h3>
          <div v-if="activeProblems.length === 0" class="text-sm text-gray-400 dark:text-slate-500 italic">No active diagnoses</div>
          <ul v-else class="space-y-1.5 print:space-y-1">
            <li
              v-for="(problem, idx) in activeProblems"
              :key="problem.icd10_code"
              :class="['flex items-start gap-2', idx >= 7 ? 'print:hidden' : '']"
            >
              <span class="facesheet-icd-code font-mono text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-1.5 py-0.5 rounded shrink-0 mt-0.5">
                {{ problem.icd10_code }}
              </span>
              <span class="text-sm text-gray-800 dark:text-slate-200 leading-snug">
                {{ problem.icd10_description }}
                <span v-if="problem.is_primary_diagnosis" class="text-xs text-blue-600 dark:text-blue-400 ml-1">(Primary)</span>
              </span>
            </li>
          </ul>
        </section>
      </div>

      <!-- Column 3: Vitals + Insurance + Directive -->
      <div class="bg-white dark:bg-slate-800 p-5 print:p-3 space-y-5 print:space-y-3 md:col-span-2 xl:col-span-1 print:col-span-1">

        <section>
          <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 pb-1 border-b border-gray-100 dark:border-slate-700">
            Latest Vitals
            <span v-if="latestVital" class="font-normal normal-case ml-1 text-gray-400 dark:text-slate-500">
              {{ fmtDateTime(latestVital.recorded_at) }}
            </span>
          </h3>
          <div v-if="!latestVital" class="text-sm text-gray-400 dark:text-slate-500 italic">No vitals recorded</div>
          <dl v-else class="grid grid-cols-2 gap-x-4 gap-y-2 print:gap-y-1">
            <div v-if="latestVital.bp_systolic && latestVital.bp_diastolic">
              <dt class="text-xs text-gray-500 dark:text-slate-400">Blood Pressure</dt>
              <dd class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ latestVital.bp_systolic }}/{{ latestVital.bp_diastolic }} <span class="text-xs font-normal text-gray-400">mmHg</span></dd>
            </div>
            <div v-if="latestVital.pulse">
              <dt class="text-xs text-gray-500 dark:text-slate-400">Heart Rate</dt>
              <dd class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ latestVital.pulse }} <span class="text-xs font-normal text-gray-400">bpm</span></dd>
            </div>
            <div v-if="latestVital.weight_lbs">
              <dt class="text-xs text-gray-500 dark:text-slate-400">Weight</dt>
              <dd class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ latestVital.weight_lbs }} <span class="text-xs font-normal text-gray-400">lbs</span></dd>
            </div>
            <div v-if="latestVital.temperature_f">
              <dt class="text-xs text-gray-500 dark:text-slate-400">Temperature</dt>
              <dd class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ latestVital.temperature_f }}&deg;F</dd>
            </div>
            <div v-if="latestVital.o2_saturation">
              <dt class="text-xs text-gray-500 dark:text-slate-400">O2 Sat</dt>
              <dd class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ latestVital.o2_saturation }}%</dd>
            </div>
            <div v-if="latestVital.bmi">
              <dt class="text-xs text-gray-500 dark:text-slate-400">BMI</dt>
              <dd class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ latestVital.bmi }}</dd>
            </div>
          </dl>
        </section>

        <section>
          <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 pb-1 border-b border-gray-100 dark:border-slate-700">
            Insurance Coverage
          </h3>
          <div v-if="activeInsurances.length === 0" class="text-sm text-gray-400 dark:text-slate-500 italic">No active coverage on file</div>
          <ul v-else class="space-y-2 print:space-y-1">
            <li v-for="ins in activeInsurances" :key="ins.id">
              <div class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ PAYER_TYPE_LABELS[ins.payer_type] ?? ins.payer_type }}</div>
              <div class="text-xs text-gray-500 dark:text-slate-400">
                {{ ins.payer_name }}<span v-if="ins.plan_name"> | {{ ins.plan_name }}</span>
              </div>
            </li>
          </ul>
        </section>

        <section>
          <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 pb-1 border-b border-gray-100 dark:border-slate-700">
            Advance Directive
          </h3>
          <dl class="space-y-1.5 print:space-y-1">
            <div class="flex gap-2">
              <dt class="text-xs text-gray-500 dark:text-slate-400 w-16 shrink-0">Status</dt>
              <dd class="text-sm text-gray-800 dark:text-slate-200">
                {{ DIRECTIVE_LABELS[participant.advance_directive_status ?? ''] ?? (participant.advance_directive_status ?? '-') }}
              </dd>
            </div>
            <div v-if="participant.advance_directive_type" class="flex gap-2">
              <dt class="text-xs text-gray-500 dark:text-slate-400 w-16 shrink-0">Type</dt>
              <dd class="text-sm font-semibold text-gray-800 dark:text-slate-200">{{ DIRECTIVE_TYPE_LABELS[participant.advance_directive_type] ?? participant.advance_directive_type }}</dd>
            </div>
            <div v-if="participant.advance_directive_reviewed_at" class="flex gap-2">
              <dt class="text-xs text-gray-500 dark:text-slate-400 w-16 shrink-0">Reviewed</dt>
              <dd class="text-sm text-gray-800 dark:text-slate-200">{{ fmtDate(participant.advance_directive_reviewed_at) }}</dd>
            </div>
            <!-- Phase 8 (MVP roadmap): generate fillable advance-directive PDF -->
            <div class="flex gap-2 mt-2 print:hidden">
              <a
                :href="`/participants/${participant.id}/advance-directive/pdf?type=${participant.advance_directive_type || 'dnr'}`"
                target="_blank"
                class="text-xs px-2 py-1 rounded border border-slate-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700"
                title="Download a pre-filled advance directive (PACE-generated facsimile)"
              >
                Download PDF
              </a>
            </div>
          </dl>
        </section>
      </div>
    </div>

    <!-- ── HIPAA Footer ───────────────────────────────────────────────────── -->
    <div class="facesheet-footer bg-slate-100 dark:bg-slate-900 border-t border-gray-200 dark:border-slate-700 px-6 py-4 print:py-2 text-center">
      <p class="text-xs text-gray-500 dark:text-slate-500 font-semibold uppercase tracking-wide">
        CONFIDENTIAL - Protected Health Information (PHI)
      </p>
      <p class="text-xs text-gray-400 dark:text-slate-600 mt-1">
        This document is intended solely for the care of the identified participant. Unauthorized disclosure is prohibited under HIPAA 45 CFR Part 164.
        {{ participant.tenant.name }} | Generated {{ printDate }}
      </p>
    </div>

    <!-- Day Center Schedule mini-modal (inline editor) -->
    <div
      v-if="showScheduleEditor"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 print:hidden"
      @click.self="showScheduleEditor = false"
    >
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md" role="dialog" aria-modal="true">
        <div class="px-6 pt-5 pb-4 border-b border-gray-100 dark:border-slate-700 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <CalendarDaysIcon class="w-5 h-5 text-blue-600 dark:text-blue-400" />
            <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Day Center Schedule</h2>
          </div>
          <button
            class="text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300"
            aria-label="Close"
            @click="showScheduleEditor = false"
          >&#x2715;</button>
        </div>
        <div class="px-6 py-5 space-y-4">
          <p class="text-sm text-gray-600 dark:text-slate-400">
            Select which weekdays {{ participant.first_name }} attends the day center. This controls the default roster; appointments can still override on specific dates.
          </p>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="code in DAY_CODES"
              :key="code"
              type="button"
              :class="[
                'px-3 py-2 rounded-lg border text-sm font-medium transition-colors select-none',
                scheduleDraft.includes(code)
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white dark:bg-slate-700 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600',
              ]"
              @click="toggleDay(code)"
            >
              {{ DAY_LABELS[code] }}
            </button>
          </div>
          <p v-if="scheduleError" class="text-sm text-red-600 dark:text-red-400">{{ scheduleError }}</p>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 dark:border-slate-700 flex justify-end gap-2">
          <button
            class="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
            @click="showScheduleEditor = false"
          >Cancel</button>
          <button
            :disabled="scheduleSaving"
            class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium disabled:opacity-50"
            @click="saveSchedule"
          >
            {{ scheduleSaving ? 'Saving...' : 'Save Schedule' }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>
