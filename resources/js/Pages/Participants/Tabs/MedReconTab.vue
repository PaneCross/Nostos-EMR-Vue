<script setup lang="ts">
// ─── MedReconTab.vue ──────────────────────────────────────────────────────────
// 5-step medication reconciliation wizard (CMS PACE regulation requirement).
// Step 1: Select prior source + reconciliation type → POST .../start
// Step 2: Enter prior medications (from discharge summary / pharmacy printout)
// Step 3: View generated comparison (matched / prior-only / current-only)
// Step 4: Apply decisions per medication (keep / discontinue / add / modify)
// Step 5: Provider approval → locks the record permanently
//
// Data flow: lazy-loaded on mount from med-reconciliation history.
// An in-progress reconciliation skips directly to the relevant step.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface PriorMed {
  drug_name: string
  dose: string | null
  dose_unit: string | null
  frequency: string | null
  route: string | null
  prescriber: string | null
  notes: string | null
}

interface MedRec {
  id: number
  status: string
  reconciliation_type: string
  prior_source: string
  prior_medications: PriorMed[]
  reconciled_medications: unknown[]
  has_discrepancies: boolean
  reconciled_by: { first_name: string; last_name: string } | null
  approved_by: { first_name: string; last_name: string } | null
  approved_at: string | null
  created_at: string
}

interface ComparisonResult {
  matched: Array<{ prior: PriorMed; current: Record<string, unknown>; recommendation: string }>
  priorOnly: Array<{ prior: PriorMed; recommendation: string }>
  currentOnly: Array<{ current: Record<string, unknown>; recommendation: string }>
}

interface Decision {
  drug_name: string
  medication_id: number | null
  action: string
  notes: string
  new_dose?: string
  new_frequency?: string
  new_route?: string
  prior_medication?: Record<string, unknown>
}

const props = defineProps<{ participant: { id: number } }>()

const PRIOR_SOURCES = [
  { value: 'discharge_summary', label: 'Discharge Summary' },
  { value: 'pharmacy_printout', label: 'Pharmacy Printout' },
  { value: 'patient_reported',  label: 'Patient/Family Reported' },
  { value: 'transfer_records',  label: 'Transfer Records' },
]

const RECON_TYPES = [
  { value: 'enrollment',    label: 'Enrollment' },
  { value: 'post_hospital', label: 'Post-Hospital' },
  { value: 'idt_review',    label: 'IDT Review' },
  { value: 'routine',       label: 'Routine' },
]

const FREQUENCIES = ['daily','twice_daily','three_times_daily','four_times_daily','weekly','monthly','as_needed','nightly','every_other_day']
const ROUTES = ['oral','sublingual','topical','inhaled','intravenous','intramuscular','subcutaneous','transdermal','ophthalmic','otic','nasal','rectal']

// ── State ─────────────────────────────────────────────────────────────────────
const step       = ref<1 | 2 | 3 | 4 | 5>(1)
const rec        = ref<MedRec | null>(null)
const comparison = ref<ComparisonResult | null>(null)
const history    = ref<MedRec[]>([])
const loading    = ref(true)
const saving     = ref(false)
const error      = ref<string | null>(null)

// Step 1 form
const startForm = ref({ prior_source: '', type: '' })

// Step 2: prior medications
const blankPriorMed = (): PriorMed => ({ drug_name: '', dose: '', dose_unit: '', frequency: '', route: 'oral', prescriber: '', notes: '' })
const priorMeds = ref<PriorMed[]>([blankPriorMed()])

// Step 4: decisions
const decisions = ref<Decision[]>([])

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const r = await axios.get(`/participants/${props.participant.id}/med-reconciliation/history`)
    const recs: MedRec[] = r.data.data ?? []
    history.value = recs
    // If there's an active rec, jump directly to its step
    const active = recs.find(r => r.status === 'in_progress' || r.status === 'decisions_made')
    if (active) {
      rec.value = active
      priorMeds.value = active.prior_medications.length ? active.prior_medications : [blankPriorMed()]
      step.value = active.status === 'decisions_made' ? 4 : 2
    }
  } catch {
    error.value = 'Failed to load reconciliation history.'
  } finally {
    loading.value = false
  }
})

// ── Step 1: Start ─────────────────────────────────────────────────────────────
async function handleStart(e: Event) {
  e.preventDefault()
  saving.value = true
  error.value = null
  try {
    const r = await axios.post(`/participants/${props.participant.id}/med-reconciliation/start`, startForm.value)
    rec.value = r.data
    step.value = 2
  } catch {
    error.value = 'Failed to start reconciliation.'
  } finally {
    saving.value = false
  }
}

// ── Step 2: Save prior meds, then load comparison ─────────────────────────────
async function handleSavePriorMeds(e: Event) {
  e.preventDefault()
  if (!rec.value) return
  saving.value = true
  error.value = null
  try {
    const filtered = priorMeds.value.filter(m => m.drug_name.trim() !== '')
    await axios.post(`/participants/${props.participant.id}/med-reconciliation/prior-meds`, { medications: filtered })
    priorMeds.value = filtered
    await loadComparison()
  } catch {
    error.value = 'Failed to save prior medications.'
    saving.value = false
  }
}

// ── Load comparison from backend (Step 3) ─────────────────────────────────────
async function loadComparison() {
  loading.value = true
  error.value = null
  try {
    const r = await axios.get(`/participants/${props.participant.id}/med-reconciliation/comparison`)
    comparison.value = r.data.comparison
    // Pre-populate decisions from comparison
    const d: Decision[] = []
    r.data.comparison.matched.forEach((m: ComparisonResult['matched'][0]) => {
      d.push({ drug_name: m.prior.drug_name, medication_id: (m.current as any).id ?? null, action: 'keep', notes: '' })
    })
    r.data.comparison.priorOnly.forEach((m: ComparisonResult['priorOnly'][0]) => {
      d.push({ drug_name: m.prior.drug_name, medication_id: null, action: 'add', notes: '', prior_medication: m.prior as unknown as Record<string, unknown> })
    })
    r.data.comparison.currentOnly.forEach((m: ComparisonResult['currentOnly'][0]) => {
      d.push({ drug_name: (m.current as any).drug_name, medication_id: (m.current as any).id ?? null, action: 'keep', notes: '' })
    })
    decisions.value = d
    step.value = 3
  } catch {
    error.value = 'Failed to load comparison. Make sure prior medications are saved.'
  } finally {
    loading.value = false
    saving.value = false
  }
}

// ── Step 4: Apply decisions ────────────────────────────────────────────────────
async function handleApplyDecisions(e: Event) {
  e.preventDefault()
  saving.value = true
  error.value = null
  try {
    const r = await axios.post(`/participants/${props.participant.id}/med-reconciliation/decisions`, { decisions: decisions.value })
    rec.value = r.data
    step.value = 5
  } catch (err: any) {
    error.value = err.response?.data?.message ?? 'Failed to apply decisions.'
  } finally {
    saving.value = false
  }
}

// ── Step 5: Provider approval ─────────────────────────────────────────────────
async function handleApprove() {
  saving.value = true
  error.value = null
  try {
    const r = await axios.post(`/participants/${props.participant.id}/med-reconciliation/approve`)
    rec.value = r.data
    history.value = [r.data, ...history.value.filter(x => x.id !== r.data.id)]
    step.value = 1
    comparison.value = null
    startForm.value = { prior_source: '', type: '' }
    priorMeds.value = [blankPriorMed()]
  } catch (err: any) {
    error.value = err.response?.data?.message ?? 'Failed to approve reconciliation.'
  } finally {
    saving.value = false
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function priorSourceLabel(value: string): string {
  return PRIOR_SOURCES.find(s => s.value === value)?.label ?? value
}
function reconTypeLabel(value: string): string {
  return RECON_TYPES.find(t => t.value === value)?.label ?? value
}
function updateDecision(i: number, field: string, value: string) {
  decisions.value = decisions.value.map((d, j) => j === i ? { ...d, [field]: value } : d)
}
</script>

<template>
  <div class="p-6 space-y-4">

    <!-- Loading -->
    <div v-if="loading" class="py-10 text-center text-gray-400 dark:text-slate-500 text-sm">
      Loading...
    </div>

    <template v-else>
      <!-- Error banner -->
      <div
        v-if="error"
        class="mb-4 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm px-4 py-3 rounded"
      >
        {{ error }}
      </div>

      <!-- Step indicator -->
      <div class="flex items-center gap-2 mb-2">
        <template v-for="s in [1,2,3,4,5]" :key="s">
          <div :class="[
            'w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0',
            step === s ? 'bg-blue-600 text-white' :
            step > s  ? 'bg-green-500 text-white' :
                        'bg-gray-200 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
          ]">{{ s }}</div>
          <div v-if="s < 5" :class="['h-0.5 flex-1', step > s ? 'bg-green-500' : 'bg-gray-200 dark:bg-slate-700']" />
        </template>
      </div>
      <div class="flex gap-6 text-xs text-gray-500 dark:text-slate-400 mb-6">
        <span
          v-for="(label, i) in ['Start', 'Prior Meds', 'Compare', 'Decisions', 'Approve']"
          :key="i"
          :class="['flex-1', step === i + 1 ? 'text-blue-600 dark:text-blue-400 font-semibold' : '']"
        >{{ label }}</span>
      </div>

      <!-- ── Step 1: Start ── -->
      <div v-if="step === 1" class="max-w-lg">
        <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Start Medication Reconciliation</h3>
        <p class="text-sm text-gray-500 dark:text-slate-400 mb-4">
          Select the source of the prior medication list and the reconciliation type.
        </p>
        <form class="space-y-4" @submit.prevent="handleStart">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Prior Medication Source</label>
            <select name="prior_source"
              v-model="startForm.prior_source"
              required
              class="block w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-slate-800 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              <option value="">Select source...</option>
              <option v-for="s in PRIOR_SOURCES" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Reconciliation Type</label>
            <select name="type"
              v-model="startForm.type"
              required
              class="block w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-slate-800 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              <option value="">Select type...</option>
              <option v-for="t in RECON_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </div>
          <button
            type="submit"
            :disabled="saving"
            class="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50"
          >
            {{ saving ? 'Starting...' : 'Start Reconciliation \u2192' }}
          </button>
        </form>

        <!-- Reconciliation history -->
        <div v-if="history.length > 0" class="mt-8">
          <h4 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Reconciliation History</h4>
          <div class="space-y-2">
            <div
              v-for="h in history"
              :key="h.id"
              class="flex items-center justify-between border border-gray-200 dark:border-slate-700 rounded px-3 py-2 text-sm"
            >
              <div>
                <span class="font-medium dark:text-slate-200">{{ reconTypeLabel(h.reconciliation_type) }}</span>
                <span class="text-gray-400 dark:text-slate-500 mx-2">·</span>
                <span class="text-gray-500 dark:text-slate-400">{{ priorSourceLabel(h.prior_source) }}</span>
              </div>
              <div class="flex items-center gap-2">
                <span
                  v-if="h.has_discrepancies"
                  class="text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded"
                >Discrepancies</span>
                <span :class="[
                  'text-xs px-1.5 py-0.5 rounded font-medium',
                  h.status === 'approved'       ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' :
                  h.status === 'decisions_made' ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300' :
                                                  'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
                ]">
                  {{ h.status === 'approved' ? 'Approved' : h.status === 'decisions_made' ? 'Pending Approval' : 'In Progress' }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Step 2: Enter prior medications ── -->
      <div v-else-if="step === 2 && rec">
        <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Enter Prior Medications</h3>
        <p class="text-sm text-gray-500 dark:text-slate-400 mb-4">
          Enter medications from the <strong>{{ priorSourceLabel(rec.prior_source) || 'source document' }}</strong>.
          Add all medications listed on the source document.
        </p>
        <form @submit.prevent="handleSavePriorMeds">
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm border border-gray-200 dark:border-slate-700 rounded">
              <thead class="bg-gray-50 dark:bg-slate-700/50">
                <tr>
                  <th v-for="h in ['Drug Name *','Dose','Unit','Frequency','Route','Prescriber','Notes','']" :key="h"
                      class="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-slate-400">{{ h }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(m, i) in priorMeds" :key="i" class="border-t border-gray-100 dark:border-slate-700/50">
                  <td class="px-1 py-1">
                    <input
                      v-model="priorMeds[i].drug_name"
                      required
                      placeholder="Drug name"
                      class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-32 bg-white dark:bg-slate-700"
                    />
                  </td>
                  <td class="px-1 py-1">
                    <input
                      v-model="priorMeds[i].dose"
                      placeholder="Dose"
                      class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-16 bg-white dark:bg-slate-700"
                    />
                  </td>
                  <td class="px-1 py-1">
                    <input
                      v-model="priorMeds[i].dose_unit"
                      placeholder="mg"
                      class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-14 bg-white dark:bg-slate-700"
                    />
                  </td>
                  <td class="px-1 py-1">
                    <select name="select"
                      v-model="priorMeds[i].frequency"
                      class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm bg-white dark:bg-slate-700"
                    >
                      <option value=""></option>
                      <option v-for="f in FREQUENCIES" :key="f" :value="f">{{ f.replace(/_/g, ' ') }}</option>
                    </select>
                  </td>
                  <td class="px-1 py-1">
                    <select name="select"
                      v-model="priorMeds[i].route"
                      class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm bg-white dark:bg-slate-700"
                    >
                      <option v-for="r in ROUTES" :key="r" :value="r">{{ r }}</option>
                    </select>
                  </td>
                  <td class="px-1 py-1">
                    <input
                      v-model="priorMeds[i].prescriber"
                      placeholder="Prescriber"
                      class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-28 bg-white dark:bg-slate-700"
                    />
                  </td>
                  <td class="px-1 py-1">
                    <input
                      v-model="priorMeds[i].notes"
                      placeholder="Notes"
                      class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-28 bg-white dark:bg-slate-700"
                    />
                  </td>
                  <td class="px-1 py-1">
                    <button
                      v-if="priorMeds.length > 1"
                      type="button"
                      class="text-red-400 hover:text-red-600 text-xs"
                      @click="priorMeds.splice(i, 1)"
                    >&#x2715;</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <button
            type="button"
            class="mt-2 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-700"
            @click="priorMeds.push(blankPriorMed())"
          >+ Add medication</button>
          <div class="flex gap-3 mt-4">
            <button
              type="button"
              class="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700"
              @click="step = 1"
            >&#x2190; Back</button>
            <button
              type="submit"
              :disabled="saving"
              class="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50"
            >{{ saving ? 'Saving...' : 'Generate Comparison \u2192' }}</button>
          </div>
        </form>
      </div>

      <!-- ── Step 3: View comparison ── -->
      <div v-else-if="step === 3 && comparison">
        <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Medication Comparison</h3>
        <p class="text-sm text-gray-500 dark:text-slate-400 mb-4">
          Review prior medications vs current active medications. Proceed to Step 4 to apply decisions.
        </p>

        <!-- Matched -->
        <div v-if="comparison.matched.length > 0" class="mb-4">
          <h4 class="text-xs font-semibold text-green-700 dark:text-green-300 uppercase mb-1">
            Matched ({{ comparison.matched.length }})
          </h4>
          <div class="space-y-1">
            <div
              v-for="(m, i) in comparison.matched"
              :key="i"
              class="flex items-center gap-2 bg-green-50 dark:bg-green-950/60 border border-green-200 dark:border-green-800 rounded px-3 py-2 text-sm"
            >
              <span class="w-2 h-2 rounded-full bg-green-500 flex-shrink-0" />
              <span class="font-medium w-40 dark:text-slate-100">{{ m.prior.drug_name }}</span>
              <span class="text-gray-400 dark:text-slate-500 text-xs">Prior: {{ m.prior.dose }} {{ m.prior.dose_unit }} {{ m.prior.frequency }}</span>
              <span class="text-gray-400 dark:text-slate-500 text-xs ml-auto">Current: {{ (m.current as any).dose }} {{ (m.current as any).dose_unit }} {{ (m.current as any).frequency }}</span>
              <span class="ml-2 text-xs bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300 px-1.5 py-0.5 rounded">Keep</span>
            </div>
          </div>
        </div>

        <!-- Prior only -->
        <div v-if="comparison.priorOnly.length > 0" class="mb-4">
          <h4 class="text-xs font-semibold text-amber-700 dark:text-amber-300 uppercase mb-1">
            Prior List Only: Not in Current ({{ comparison.priorOnly.length }})
          </h4>
          <div class="space-y-1">
            <div
              v-for="(m, i) in comparison.priorOnly"
              :key="i"
              class="flex items-center gap-2 bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 rounded px-3 py-2 text-sm"
            >
              <span class="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0" />
              <span class="font-medium w-40 dark:text-slate-100">{{ m.prior.drug_name }}</span>
              <span class="text-gray-400 dark:text-slate-500 text-xs">{{ m.prior.dose }} {{ m.prior.dose_unit }} {{ m.prior.frequency }}</span>
              <span class="ml-auto text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded">Add or Ignore</span>
            </div>
          </div>
        </div>

        <!-- Current only -->
        <div v-if="comparison.currentOnly.length > 0" class="mb-4">
          <h4 class="text-xs font-semibold text-blue-700 dark:text-blue-300 uppercase mb-1">
            Current Only: Not on Prior List ({{ comparison.currentOnly.length }})
          </h4>
          <div class="space-y-1">
            <div
              v-for="(m, i) in comparison.currentOnly"
              :key="i"
              class="flex items-center gap-2 bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded px-3 py-2 text-sm"
            >
              <span class="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0" />
              <span class="font-medium w-40 dark:text-slate-100">{{ (m.current as any).drug_name }}</span>
              <span class="text-gray-400 dark:text-slate-500 text-xs">{{ (m.current as any).dose }} {{ (m.current as any).dose_unit }} {{ (m.current as any).frequency }}</span>
              <span class="ml-auto text-xs bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded">Keep or Discontinue</span>
            </div>
          </div>
        </div>

        <div class="flex gap-3 mt-4">
          <button
            class="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700"
            @click="step = 2"
          >&#x2190; Back</button>
          <button
            class="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700"
            @click="step = 4"
          >Apply Decisions &#x2192;</button>
        </div>
      </div>

      <!-- ── Step 4: Apply decisions ── -->
      <div v-else-if="step === 4">
        <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Apply Decisions</h3>
        <p class="text-sm text-gray-500 dark:text-slate-400 mb-4">
          For each medication, choose an action. Decisions will be applied to the active medication list.
        </p>
        <form @submit.prevent="handleApplyDecisions">
          <div class="space-y-2">
            <div
              v-for="(d, i) in decisions"
              :key="i"
              class="border border-gray-200 dark:border-slate-700 rounded px-3 py-3 flex flex-wrap gap-3 items-start"
            >
              <div class="font-medium text-sm w-36 dark:text-slate-200">{{ d.drug_name }}</div>
              <div>
                <select name="select"
                  :value="d.action"
                  class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm bg-white dark:bg-slate-700"
                  @change="updateDecision(i, 'action', ($event.target as HTMLSelectElement).value)"
                >
                  <option value="keep">Keep</option>
                  <option value="discontinue">Discontinue</option>
                  <option value="add">Add</option>
                  <option value="modify">Modify</option>
                </select>
              </div>
              <template v-if="d.action === 'modify'">
                <input
                  :value="d.new_dose ?? ''"
                  placeholder="New dose"
                  class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-20 bg-white dark:bg-slate-700"
                  @input="updateDecision(i, 'new_dose', ($event.target as HTMLInputElement).value)"
                />
                <input
                  :value="d.new_frequency ?? ''"
                  placeholder="New frequency"
                  class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-28 bg-white dark:bg-slate-700"
                  @input="updateDecision(i, 'new_frequency', ($event.target as HTMLInputElement).value)"
                />
                <input
                  :value="d.new_route ?? ''"
                  placeholder="New route (opt)"
                  class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-24 bg-white dark:bg-slate-700"
                  @input="updateDecision(i, 'new_route', ($event.target as HTMLInputElement).value)"
                />
              </template>
              <input
                :value="d.notes"
                placeholder="Notes (optional)"
                class="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm flex-1 min-w-32 bg-white dark:bg-slate-700"
                @input="updateDecision(i, 'notes', ($event.target as HTMLInputElement).value)"
              />
            </div>
          </div>
          <div class="flex gap-3 mt-4">
            <button
              type="button"
              class="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700"
              @click="step = 3"
            >&#x2190; Back</button>
            <button
              type="submit"
              :disabled="saving"
              class="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50"
            >{{ saving ? 'Saving...' : 'Submit Decisions \u2192' }}</button>
          </div>
        </form>
      </div>

      <!-- ── Step 5: Provider approval ── -->
      <div v-else-if="step === 5 && rec" class="max-w-lg">
        <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Provider Approval</h3>
        <p class="text-sm text-gray-500 dark:text-slate-400 mb-4">
          Review the reconciliation summary and approve to lock the record. Once approved, no further changes can be made.
        </p>
        <div class="border border-gray-200 dark:border-slate-700 rounded p-4 bg-gray-50 dark:bg-slate-800 mb-4 text-sm space-y-1">
          <div>
            <span class="text-gray-500 dark:text-slate-400">Type:</span>
            <span class="font-medium dark:text-slate-200 ml-1">{{ reconTypeLabel(rec.reconciliation_type) }}</span>
          </div>
          <div>
            <span class="text-gray-500 dark:text-slate-400">Source:</span>
            <span class="font-medium dark:text-slate-200 ml-1">{{ priorSourceLabel(rec.prior_source) }}</span>
          </div>
          <div>
            <span class="text-gray-500 dark:text-slate-400">Reconciled by:</span>
            <span class="font-medium dark:text-slate-200 ml-1">
              {{ rec.reconciled_by ? `${rec.reconciled_by.first_name} ${rec.reconciled_by.last_name}` : '-' }}
            </span>
          </div>
          <div
            v-if="rec.has_discrepancies"
            class="mt-2 bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300 text-xs px-3 py-2 rounded flex items-center gap-1"
          >
            <ExclamationTriangleIcon class="w-3.5 h-3.5 flex-shrink-0" />
            This reconciliation has documented discrepancies requiring clinical follow-up.
          </div>
        </div>
        <div class="flex gap-3">
          <button
            class="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700"
            @click="step = 4"
          >&#x2190; Back</button>
          <button
            :disabled="saving"
            class="bg-green-600 text-white text-sm px-4 py-2 rounded hover:bg-green-700 disabled:opacity-50"
            @click="handleApprove"
          >{{ saving ? 'Approving...' : '\u2713 Approve & Lock Record' }}</button>
        </div>
      </div>
    </template>
  </div>
</template>
