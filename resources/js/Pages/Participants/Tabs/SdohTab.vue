<script setup lang="ts">
// ─── SdohTab.vue ──────────────────────────────────────────────────────────────
// Social Determinants of Health (SDOH) screening per PRAPARE / USCDI v3.
// Each record is a full point-in-time assessment with 6 domains + safety/notes.
// Schema: emr_social_determinants: one row per assessment (not per domain).
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import { PlusIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

interface SdohRecord {
  id: number
  assessed_at: string | null
  housing_stability: string
  food_security: string
  transportation_access: string
  social_isolation_risk: string
  caregiver_strain: string
  financial_strain: string
  safety_concerns: string | null
  notes: string | null
  assessed_by: { id: number; first_name: string; last_name: string } | null
}

const props = defineProps<{ participant: { id: number } }>()

// ── Risk level color maps ─────────────────────────────────────────────────────

/** High-risk values that get red badges */
const HIGH_RISK: Record<string, string[]> = {
  housing_stability:     ['unstable', 'homeless'],
  food_security:         ['insecure'],
  transportation_access: ['none'],
  social_isolation_risk: ['high'],
  caregiver_strain:      ['severe'],
  financial_strain:      ['severe'],
}
const MEDIUM_RISK: Record<string, string[]> = {
  housing_stability:     ['at_risk'],
  food_security:         ['at_risk'],
  transportation_access: ['limited'],
  social_isolation_risk: ['moderate'],
  caregiver_strain:      ['moderate'],
  financial_strain:      ['moderate'],
}

function riskColor(domain: string, value: string): string {
  if (value === 'unknown') return 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'
  if (HIGH_RISK[domain]?.includes(value))   return 'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300'
  if (MEDIUM_RISK[domain]?.includes(value)) return 'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300'
  return 'bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300'
}

function valueLabel(val: string): string {
  const map: Record<string, string> = {
    stable: 'Stable', at_risk: 'At Risk', unstable: 'Unstable', homeless: 'Homeless',
    secure: 'Secure', insecure: 'Insecure',
    adequate: 'Adequate', limited: 'Limited', none: 'None',
    low: 'Low', moderate: 'Moderate', high: 'High',
    mild: 'Mild', severe: 'Severe', unknown: 'Unknown',
  }
  return map[val] ?? val.replace(/_/g, ' ')
}

const DOMAINS: { key: keyof Omit<SdohRecord, 'id'|'assessed_at'|'safety_concerns'|'notes'|'assessed_by'>; label: string }[] = [
  { key: 'housing_stability',     label: 'Housing Stability' },
  { key: 'food_security',         label: 'Food Security' },
  { key: 'transportation_access', label: 'Transportation' },
  { key: 'social_isolation_risk', label: 'Social Isolation' },
  { key: 'caregiver_strain',      label: 'Caregiver Strain' },
  { key: 'financial_strain',      label: 'Financial Strain' },
]

// ── State ─────────────────────────────────────────────────────────────────────

const records    = ref<SdohRecord[]>([])
const loading    = ref(true)
const loadError  = ref('')
const showForm   = ref(false)
const saving     = ref(false)
const formError  = ref('')
const expandedId = ref<number | null>(null)

const form = ref({
  assessed_at:           new Date().toISOString().slice(0, 10),
  housing_stability:     'unknown',
  food_security:         'unknown',
  transportation_access: 'unknown',
  social_isolation_risk: 'unknown',
  caregiver_strain:      'unknown',
  financial_strain:      'unknown',
  safety_concerns:       '',
  notes:                 '',
})

onMounted(async () => {
  try {
    const r = await axios.get(`/participants/${props.participant.id}/social-determinants`)
    records.value = Array.isArray(r.data) ? r.data : []
  } catch {
    loadError.value = 'Failed to load SDOH screenings.'
  } finally {
    loading.value = false
  }
})

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

function hasElevatedRisk(r: SdohRecord): boolean {
  return HIGH_RISK.housing_stability.includes(r.housing_stability) ||
    HIGH_RISK.food_security.includes(r.food_security) ||
    HIGH_RISK.transportation_access.includes(r.transportation_access) ||
    HIGH_RISK.social_isolation_risk.includes(r.social_isolation_risk) ||
    HIGH_RISK.caregiver_strain.includes(r.caregiver_strain) ||
    HIGH_RISK.financial_strain.includes(r.financial_strain) ||
    !!r.safety_concerns
}

function hasModerateRisk(r: SdohRecord): boolean {
  if (hasElevatedRisk(r)) return false
  return MEDIUM_RISK.housing_stability.includes(r.housing_stability) ||
    MEDIUM_RISK.food_security.includes(r.food_security) ||
    MEDIUM_RISK.transportation_access.includes(r.transportation_access) ||
    MEDIUM_RISK.social_isolation_risk.includes(r.social_isolation_risk) ||
    MEDIUM_RISK.caregiver_strain.includes(r.caregiver_strain) ||
    MEDIUM_RISK.financial_strain.includes(r.financial_strain)
}

function overallBadgeClass(r: SdohRecord): string {
  if (hasElevatedRisk(r))  return 'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300'
  if (hasModerateRisk(r))  return 'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300'
  return 'bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300'
}

function overallLabel(r: SdohRecord): string {
  if (hasElevatedRisk(r))  return 'Elevated Risk'
  if (hasModerateRisk(r))  return 'Moderate Risk'
  return 'Low Risk'
}

async function submit() {
  saving.value = true; formError.value = ''
  try {
    const payload = {
      ...form.value,
      safety_concerns: form.value.safety_concerns || null,
      notes:           form.value.notes || null,
    }
    const res = await axios.post(`/participants/${props.participant.id}/social-determinants`, payload)
    records.value.unshift(res.data)
    showForm.value = false
    form.value = {
      assessed_at: new Date().toISOString().slice(0, 10),
      housing_stability: 'unknown', food_security: 'unknown',
      transportation_access: 'unknown', social_isolation_risk: 'unknown',
      caregiver_strain: 'unknown', financial_strain: 'unknown',
      safety_concerns: '', notes: '',
    }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    formError.value = e.response?.data?.message ?? 'Failed to save.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6 max-w-4xl space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Social Determinants of Health</h2>
        <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">PRAPARE / USCDI v3 screening assessments</p>
      </div>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showForm = !showForm"
      >
        <PlusIcon class="w-3 h-3" />
        Add Screening
      </button>
    </div>

    <!-- Loading / error -->
    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">Loading SDOH screenings...</div>
    <div v-else-if="loadError" class="py-8 text-center text-red-500 dark:text-red-400 text-sm">{{ loadError }}</div>

    <!-- Add form -->
    <div v-if="showForm && !loading" class="bg-gray-50 dark:bg-slate-700/50 rounded-xl border border-gray-200 dark:border-slate-600 p-5">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-4">New SDOH Screening</h3>

      <!-- Domain selects grid -->
      <div class="grid grid-cols-2 gap-3 mb-4">
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Assessment Date</label>
          <input v-model="form.assessed_at" type="date"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" />
        </div>
        <div />
        <div v-for="d in DOMAINS" :key="d.key">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">{{ d.label }}</label>
          <select name="select" v-model="(form as Record<string,string>)[d.key]"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100">
            <option
              v-for="opt in d.key === 'housing_stability' ? ['stable','at_risk','unstable','homeless','unknown']
                         : d.key === 'food_security'         ? ['secure','at_risk','insecure','unknown']
                         : d.key === 'transportation_access' ? ['adequate','limited','none','unknown']
                         : d.key === 'social_isolation_risk' ? ['low','moderate','high','unknown']
                         : ['none','mild','moderate','severe','unknown']"
              :key="opt" :value="opt"
            >{{ valueLabel(opt) }}</option>
          </select>
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Safety Concerns</label>
          <input v-model="form.safety_concerns" type="text" placeholder="Describe any safety concerns (optional)"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" />
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Notes</label>
          <textarea v-model="form.notes" rows="2" placeholder="Additional notes (optional)"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100 resize-none" />
        </div>
      </div>

      <p v-if="formError" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ formError }}</p>
      <div class="flex gap-2">
        <button :disabled="saving" @click="submit"
          class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">
          {{ saving ? 'Saving...' : 'Save Screening' }}
        </button>
        <button @click="showForm = false"
          class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
          Cancel
        </button>
      </div>
    </div>

    <!-- Empty state -->
    <div v-if="!loading && records.length === 0"
      class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-12 text-center">
      <p class="text-sm text-gray-500 dark:text-slate-400">No SDOH screenings on file.</p>
      <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Add a screening using the button above.</p>
    </div>

    <!-- Screening records -->
    <div v-if="!loading && records.length > 0" class="space-y-3">
      <div
        v-for="r in records"
        :key="r.id"
        class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden"
      >
        <!-- Record header (clickable) -->
        <button
          class="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors text-left"
          @click="expandedId = expandedId === r.id ? null : r.id"
        >
          <div class="flex items-center gap-3">
            <span :class="['text-xs font-semibold px-2.5 py-1 rounded-full shrink-0', overallBadgeClass(r)]">
              {{ overallLabel(r) }}
            </span>
            <div>
              <div class="text-sm font-medium text-gray-900 dark:text-slate-100">
                {{ fmtDate(r.assessed_at) }}
              </div>
              <div v-if="r.assessed_by" class="text-xs text-gray-500 dark:text-slate-400">
                Assessed by {{ r.assessed_by.first_name }} {{ r.assessed_by.last_name }}
              </div>
            </div>
          </div>
          <!-- Summary chips -->
          <div class="flex items-center gap-2">
            <div class="hidden sm:flex gap-1.5 flex-wrap justify-end max-w-sm">
              <span
                v-for="d in DOMAINS"
                :key="d.key"
                :class="['text-xs px-1.5 py-0.5 rounded', riskColor(d.key, (r as Record<string,string>)[d.key])]"
              >{{ valueLabel((r as Record<string,string>)[d.key]) }}</span>
            </div>
            <ChevronDownIcon :class="['w-4 h-4 text-gray-400 dark:text-slate-500 shrink-0 transition-transform', expandedId === r.id ? 'rotate-180' : '']" />
          </div>
        </button>

        <!-- Expanded detail -->
        <div v-if="expandedId === r.id" class="border-t border-gray-100 dark:border-slate-700 px-4 py-4">
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-3">
            <div v-for="d in DOMAINS" :key="d.key" class="flex flex-col gap-1">
              <span class="text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide">{{ d.label }}</span>
              <span :class="['text-xs font-medium px-2 py-0.5 rounded w-fit', riskColor(d.key, (r as Record<string,string>)[d.key])]">
                {{ valueLabel((r as Record<string,string>)[d.key]) }}
              </span>
            </div>
          </div>
          <div v-if="r.safety_concerns" class="mt-2 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 px-3 py-2">
            <p class="text-xs font-semibold text-red-700 dark:text-red-300 mb-0.5">Safety Concerns</p>
            <p class="text-sm text-red-800 dark:text-red-200">{{ r.safety_concerns }}</p>
          </div>
          <div v-if="r.notes" class="mt-2 text-xs text-gray-500 dark:text-slate-400 italic">
            Notes: {{ r.notes }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
