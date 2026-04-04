<script setup lang="ts">
// ─── OverviewTab.vue (Facesheet) ──────────────────────────────────────────────
// PACE Facesheet overview. Summarizes participant demographics, enrollment info,
// home address, emergency contacts, latest vitals, insurance coverages,
// advance directive status, and key clinical indicators.
// Designed for print-to-PDF use. Supports window.print() via the Print button.
// ─────────────────────────────────────────────────────────────────────────────

import { computed } from 'vue'
import { PrinterIcon } from '@heroicons/vue/24/outline'

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
  advance_directive_status: string | null; advance_directive_type: string | null
  advance_directive_reviewed_at: string | null
  race: string | null; ethnicity: string | null; marital_status: string | null
  veteran_status: string | null; education_level: string | null
  religion: string | null; site: { id: number; name: string }
  tenant: { id: number; name: string }; created_at: string
}

interface Address {
  id: number
  address_type: string
  is_primary: boolean
  street: string
  city: string
  state: string
  zip: string
}

interface Contact {
  id: number
  first_name: string
  last_name: string
  relationship: string | null
  phone_primary: string | null
  phone_secondary: string | null
  email: string | null
  is_emergency_contact: boolean
  is_legal_guardian: boolean
  priority_order: number
}

interface Vital {
  id: number
  recorded_at: string
  weight_lbs: number | null
  height_in: number | null
  bmi: number | null
  bp_systolic: number | null
  bp_diastolic: number | null
  pulse: number | null
  temperature_f: number | null
  oxygen_saturation: number | null
}

interface Insurance {
  id: number
  payer_name: string
  payer_type: string
  plan_name: string | null
  member_id: string | null
  group_number: string | null
  effective_date: string | null
  termination_date: string | null
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

const activeProblems = computed(() =>
  ((props.problems ?? []) as Array<{ status: string; icd10_code: string; icd10_description: string; is_primary_diagnosis: boolean }>)
    .filter(p => p.status === 'active' || p.status === 'chronic')
    .slice(0, 8)
)

const lifeThreateningAllergies = computed(() => {
  const groups = props.allergies ?? {}
  return Object.values(groups).flat().filter((a: unknown) =>
    (a as { severity: string }).severity === 'life_threatening'
  ) as Array<{ id: number; allergen_name: string; reaction_description: string | null }>
})

const homeAddress = computed(() =>
  (props.addresses ?? []).find(a => a.address_type === 'home' && a.is_primary)
  ?? (props.addresses ?? []).find(a => a.address_type === 'home')
  ?? (props.addresses ?? [])[0]
  ?? null
)

const emergencyContacts = computed(() =>
  (props.contacts ?? [])
    .filter(c => c.is_emergency_contact)
    .sort((a, b) => a.priority_order - b.priority_order)
    .slice(0, 3)
)

const latestVital = computed(() =>
  ((props.vitals ?? []) as Vital[]).length > 0
    ? (props.vitals as Vital[])[0]
    : null
)

const activeInsurances = computed(() =>
  ((props.insurances ?? []) as Insurance[]).filter(i => i.is_active)
)

const PAYER_TYPE_LABELS: Record<string, string> = {
  medicare_a:   'Medicare Part A',
  medicare_b:   'Medicare Part B',
  medicare_d:   'Medicare Part D',
  medicaid:     'Medicaid',
  commercial:   'Commercial',
  managed_care: 'Managed Care',
  other:        'Other',
}
</script>

<template>
  <div id="facesheet-print" class="p-6 max-w-5xl">
    <!-- Print button -->
    <div class="flex justify-between items-center mb-6 print:hidden">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">PACE Facesheet</h2>
      <button
        class="inline-flex items-center gap-2 text-sm px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
        @click="() => window.print()"
      >
        <PrinterIcon class="w-4 h-4" />
        Print Facesheet
      </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

      <!-- ── Demographics ── -->
      <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4">
        <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3">Demographics</h3>
        <dl class="space-y-2">
          <div class="grid grid-cols-2 gap-2">
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Full Name</dt>
              <dd class="text-sm font-medium text-gray-900 dark:text-slate-100">
                {{ participant.first_name }} {{ participant.last_name }}
                <span v-if="participant.preferred_name" class="text-gray-400 text-xs">"{{ participant.preferred_name }}"</span>
              </dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">MRN</dt>
              <dd class="text-sm font-mono text-gray-900 dark:text-slate-100">{{ participant.mrn }}</dd>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Date of Birth</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100">{{ fmtDate(participant.dob) }} (age {{ age(participant.dob) }})</dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Gender</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100">{{ participant.gender ?? '-' }}</dd>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">SSN (last 4)</dt>
              <dd class="text-sm font-mono text-gray-900 dark:text-slate-100">
                {{ participant.ssn_last_four ? `***-**-${participant.ssn_last_four}` : '-' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Pronouns</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100">{{ participant.pronouns ?? '-' }}</dd>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Language</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100">
                {{ participant.primary_language }}
                <span v-if="participant.interpreter_needed" class="text-amber-600 dark:text-amber-400 text-xs ml-1">(Interpreter needed)</span>
              </dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Marital Status</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100">{{ participant.marital_status ?? '-' }}</dd>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Race / Ethnicity</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100">{{ participant.race ?? '-' }} / {{ participant.ethnicity ?? '-' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Veteran Status</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100">{{ participant.veteran_status ?? '-' }}</dd>
            </div>
          </div>
        </dl>
      </div>

      <!-- ── Enrollment ── -->
      <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4">
        <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3">Enrollment</h3>
        <dl class="space-y-2">
          <div class="grid grid-cols-2 gap-2">
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Status</dt>
              <dd class="text-sm font-medium text-gray-900 dark:text-slate-100 capitalize">{{ participant.enrollment_status }}</dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Enrollment Date</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100">{{ fmtDate(participant.enrollment_date) }}</dd>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Medicare ID</dt>
              <dd class="text-sm font-mono text-gray-900 dark:text-slate-100">{{ participant.medicare_id ?? '-' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Medicaid ID</dt>
              <dd class="text-sm font-mono text-gray-900 dark:text-slate-100">{{ participant.medicaid_id ?? '-' }}</dd>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">H-Number</dt>
              <dd class="text-sm font-mono text-gray-900 dark:text-slate-100">{{ participant.h_number ?? '-' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Contract ID</dt>
              <dd class="text-sm font-mono text-gray-900 dark:text-slate-100">{{ participant.pace_contract_id ?? '-' }}</dd>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Site</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100">{{ participant.site.name }}</dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">NF Eligible</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100">{{ participant.nursing_facility_eligible ? 'Yes' : 'No' }}</dd>
            </div>
          </div>
        </dl>
      </div>

      <!-- ── Home Address ── -->
      <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4">
        <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3">Home Address</h3>
        <div v-if="homeAddress" class="text-sm text-gray-900 dark:text-slate-100">
          <p>{{ homeAddress.street }}</p>
          <p>{{ homeAddress.city }}, {{ homeAddress.state }} {{ homeAddress.zip }}</p>
        </div>
        <p v-else class="text-sm text-gray-400 dark:text-slate-500">No address on file</p>
      </div>

      <!-- ── Emergency Contacts ── -->
      <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4">
        <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3">Emergency Contacts</h3>
        <div v-if="emergencyContacts.length === 0" class="text-sm text-gray-400 dark:text-slate-500">
          No emergency contacts on file
        </div>
        <ul v-else class="space-y-2">
          <li v-for="contact in emergencyContacts" :key="contact.id" class="text-sm">
            <div class="font-medium text-gray-900 dark:text-slate-100">
              {{ contact.first_name }} {{ contact.last_name }}
              <span v-if="contact.relationship" class="text-gray-500 dark:text-slate-400 font-normal ml-1">({{ contact.relationship }})</span>
            </div>
            <div v-if="contact.phone_primary" class="text-gray-600 dark:text-slate-400 text-xs mt-0.5">{{ contact.phone_primary }}</div>
            <div v-if="contact.is_legal_guardian" class="text-xs text-indigo-600 dark:text-indigo-400 mt-0.5">Legal Guardian</div>
          </li>
        </ul>
      </div>

      <!-- ── Latest Vitals ── -->
      <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4">
        <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3">
          Latest Vitals
          <span v-if="latestVital" class="font-normal normal-case text-[10px] ml-1">{{ fmtDate(latestVital.recorded_at) }}</span>
        </h3>
        <div v-if="!latestVital" class="text-sm text-gray-400 dark:text-slate-500">No vitals recorded</div>
        <dl v-else class="grid grid-cols-2 gap-x-4 gap-y-1.5">
          <div v-if="latestVital.bp_systolic && latestVital.bp_diastolic">
            <dt class="text-xs text-gray-500 dark:text-slate-400">Blood Pressure</dt>
            <dd class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ latestVital.bp_systolic }}/{{ latestVital.bp_diastolic }} mmHg</dd>
          </div>
          <div v-if="latestVital.pulse">
            <dt class="text-xs text-gray-500 dark:text-slate-400">Heart Rate</dt>
            <dd class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ latestVital.pulse }} bpm</dd>
          </div>
          <div v-if="latestVital.weight_lbs">
            <dt class="text-xs text-gray-500 dark:text-slate-400">Weight</dt>
            <dd class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ latestVital.weight_lbs }} lbs</dd>
          </div>
          <div v-if="latestVital.temperature_f">
            <dt class="text-xs text-gray-500 dark:text-slate-400">Temperature</dt>
            <dd class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ latestVital.temperature_f }}&deg;F</dd>
          </div>
          <div v-if="latestVital.oxygen_saturation">
            <dt class="text-xs text-gray-500 dark:text-slate-400">O2 Saturation</dt>
            <dd class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ latestVital.oxygen_saturation }}%</dd>
          </div>
          <div v-if="latestVital.bmi">
            <dt class="text-xs text-gray-500 dark:text-slate-400">BMI</dt>
            <dd class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ latestVital.bmi }}</dd>
          </div>
        </dl>
      </div>

      <!-- ── Insurance Coverages ── -->
      <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4">
        <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3">Insurance Coverages</h3>
        <div v-if="activeInsurances.length === 0" class="text-sm text-gray-400 dark:text-slate-500">No active insurance on file</div>
        <ul v-else class="space-y-2">
          <li v-for="ins in activeInsurances" :key="ins.id" class="text-sm">
            <div class="font-medium text-gray-900 dark:text-slate-100">
              {{ PAYER_TYPE_LABELS[ins.payer_type] ?? ins.payer_type }}
            </div>
            <div class="text-xs text-gray-600 dark:text-slate-400">
              {{ ins.payer_name }}
              <span v-if="ins.plan_name"> · {{ ins.plan_name }}</span>
            </div>
            <div v-if="ins.member_id" class="text-xs font-mono text-gray-500 dark:text-slate-400">ID: {{ ins.member_id }}</div>
          </li>
        </ul>
      </div>

      <!-- ── Advance Directive ── -->
      <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4">
        <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3">Advance Directive</h3>
        <dl class="space-y-2">
          <div class="grid grid-cols-2 gap-2">
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Status</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100 capitalize">{{ participant.advance_directive_status?.replace(/_/g, ' ') ?? '-' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500 dark:text-slate-400">Type</dt>
              <dd class="text-sm text-gray-900 dark:text-slate-100 capitalize">{{ participant.advance_directive_type?.replace(/_/g, ' ') ?? '-' }}</dd>
            </div>
          </div>
          <div>
            <dt class="text-xs text-gray-500 dark:text-slate-400">Last Reviewed</dt>
            <dd class="text-sm text-gray-900 dark:text-slate-100">{{ fmtDate(participant.advance_directive_reviewed_at) }}</dd>
          </div>
        </dl>
      </div>

      <!-- ── Life-threatening allergies ── -->
      <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4">
        <h3 class="text-xs font-bold text-red-500 uppercase tracking-wider mb-3">Life-Threatening Allergies</h3>
        <div v-if="lifeThreateningAllergies.length === 0" class="text-sm text-gray-400 dark:text-slate-500">
          None on file
        </div>
        <ul v-else class="space-y-1">
          <li
            v-for="allergy in lifeThreateningAllergies"
            :key="allergy.id"
            class="text-sm text-red-700 dark:text-red-300 font-medium"
          >
            {{ allergy.allergen_name }}
            <span v-if="allergy.reaction_description" class="text-gray-600 dark:text-slate-400 font-normal">
              : {{ allergy.reaction_description }}
            </span>
          </li>
        </ul>
      </div>

      <!-- ── Active Problem List ── -->
      <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4 md:col-span-2">
        <h3 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3">Active Diagnosis List</h3>
        <div v-if="activeProblems.length === 0" class="text-sm text-gray-400 dark:text-slate-500">No active diagnoses on file</div>
        <ul v-else class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
          <li
            v-for="problem in activeProblems"
            :key="problem.icd10_code"
            class="flex items-start gap-2 text-sm"
          >
            <span class="font-mono text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-1.5 py-0.5 rounded mt-0.5 shrink-0">{{ problem.icd10_code }}</span>
            <span class="text-gray-800 dark:text-slate-200">
              {{ problem.icd10_description }}
              <span v-if="problem.is_primary_diagnosis" class="text-xs text-blue-600 dark:text-blue-400 ml-1">(Primary)</span>
            </span>
          </li>
        </ul>
      </div>

    </div>
  </div>
</template>
