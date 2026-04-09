<script setup lang="ts">
// ─── MedicationsTab.vue ───────────────────────────────────────────────────────
// Active medications list with drug interaction alert banners. Lazy-loads via
// API on mount. Add medication form with reference typeahead search + 4-col
// dosing grid. Discontinue with confirm dialog. Acknowledged interactions
// require a clinical note (modal). Reviewed interactions are collapsible.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Medication {
  id: number
  drug_name: string
  rxnorm_code: string | null
  dose: number | null
  dose_unit: string | null
  route: string | null
  frequency: string | null
  is_prn: boolean
  is_controlled: boolean
  controlled_schedule: string | null
  status: string
  start_date: string | null
  end_date: string | null
}

interface InteractionAlert {
  id: number
  drug_name_1: string
  drug_name_2: string
  severity: string
  description: string | null
  acknowledged_at: string | null
  acknowledged_by_name: string | null
  acknowledgement_note: string | null
}

interface MedRefResult {
  drug_name: string
  rxnorm_code: string | null
  drug_class: string | null
  common_dose: number | null
  dose_unit: string | null
  route: string | null
  frequency: string | null
  is_controlled: boolean
  controlled_schedule: string | null
}

interface Participant { id: number }

const props = defineProps<{ participant: Participant }>()

const SEVERITY_COLORS: Record<string, string> = {
  critical: 'bg-red-50 dark:bg-red-950/60 border-red-300 dark:border-red-700 text-red-800 dark:text-red-300',
  major:    'bg-orange-50 dark:bg-orange-950/60 border-orange-300 dark:border-orange-700 text-orange-800 dark:text-orange-300',
  moderate: 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-700 text-amber-800 dark:text-amber-300',
  minor:    'bg-yellow-50 dark:bg-yellow-950/40 border-yellow-200 dark:border-yellow-700 text-yellow-800 dark:text-yellow-300',
}

const MED_STATUS_COLORS: Record<string, string> = {
  active:       'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
  prn:          'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
  on_hold:      'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
  discontinued: 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400',
}

// ── State ─────────────────────────────────────────────────────────────────────
const medications    = ref<Medication[]>([])
const alerts         = ref<InteractionAlert[]>([])
const reviewedAlerts = ref<InteractionAlert[]>([])
const loading        = ref(true)
const showReviewed   = ref(false)
const showAddForm    = ref(false)
const saving         = ref(false)

// Drug reference typeahead
const searchQuery   = ref('')
const searchResults = ref<MedRefResult[]>([])
let searchTimer: ReturnType<typeof setTimeout> | null = null

// Acknowledgement modal
const ackModal = ref<{ open: boolean; alert: InteractionAlert | null; note: string }>({
  open: false, alert: null, note: '',
})
const ackSaving = ref(false)
const ackError  = ref('')

const blankForm = () => ({
  drug_name: '', rxnorm_code: '', dose: '', dose_unit: 'mg', route: 'oral',
  frequency: 'daily', is_prn: false, is_controlled: false, controlled_schedule: '',
  start_date: new Date().toISOString().slice(0, 10),
})
const form = ref(blankForm())

// ── Computed ──────────────────────────────────────────────────────────────────
const activeMeds   = computed(() => medications.value.filter(m => m.status === 'active' || m.status === 'prn'))
const inactiveMeds = computed(() => medications.value.filter(m => m.status === 'discontinued' || m.status === 'on_hold'))

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const [medResp, alertResp] = await Promise.all([
      axios.get(`/participants/${props.participant.id}/medications`),
      axios.get(`/participants/${props.participant.id}/medications/interactions`),
    ])
    medications.value    = medResp.data.medications ?? medResp.data
    alerts.value         = alertResp.data.active    ?? []
    reviewedAlerts.value = alertResp.data.reviewed  ?? []
  } catch {
    // leave empty
  } finally {
    loading.value = false
  }
})

// ── Drug reference typeahead ──────────────────────────────────────────────────
function onSearchInput() {
  if (searchTimer) clearTimeout(searchTimer)
  if (searchQuery.value.length < 2) { searchResults.value = []; return }
  searchTimer = setTimeout(async () => {
    try {
      const r = await axios.get('/medications/reference/search', { params: { q: searchQuery.value } })
      searchResults.value = r.data
    } catch {
      searchResults.value = []
    }
  }, 300)
}

function applyRef(r: MedRefResult) {
  form.value.drug_name           = r.drug_name
  form.value.rxnorm_code         = r.rxnorm_code ?? ''
  form.value.dose                = r.common_dose?.toString() ?? ''
  form.value.dose_unit           = r.dose_unit ?? 'mg'
  form.value.route               = r.route ?? 'oral'
  form.value.frequency           = r.frequency ?? 'daily'
  form.value.is_controlled       = r.is_controlled
  form.value.controlled_schedule = r.controlled_schedule ?? ''
  searchQuery.value   = r.drug_name
  searchResults.value = []
}

// ── Add medication ────────────────────────────────────────────────────────────
async function submit() {
  saving.value = true
  try {
    const resp = await axios.post(`/participants/${props.participant.id}/medications`, {
      ...form.value,
      drug_name: searchQuery.value || form.value.drug_name,
      dose: form.value.dose ? parseFloat(form.value.dose as string) : null,
    })
    medications.value.unshift(resp.data.medication ?? resp.data)
    if (resp.data.new_alerts?.length) {
      alerts.value = [...resp.data.new_alerts, ...alerts.value]
    }
    showAddForm.value = false
    form.value        = blankForm()
    searchQuery.value = ''
  } catch {
    // keep form open
  } finally {
    saving.value = false
  }
}

function cancelAdd() {
  showAddForm.value   = false
  form.value          = blankForm()
  searchQuery.value   = ''
  searchResults.value = []
}

// ── Discontinue ───────────────────────────────────────────────────────────────
async function handleDiscontinue(med: Medication) {
  const reason = window.prompt(`Discontinue ${med.drug_name}?\n\nEnter reason:`)
  if (!reason?.trim()) return
  try {
    await axios.put(`/participants/${props.participant.id}/medications/${med.id}/discontinue`, {
      reason: reason.trim(),
    })
    medications.value = medications.value.map(m =>
      m.id === med.id ? { ...m, status: 'discontinued' } : m
    )
  } catch {
    // noop
  }
}

// ── Acknowledgement modal ─────────────────────────────────────────────────────
function openAck(alert: InteractionAlert) {
  ackModal.value = { open: true, alert, note: '' }
  ackError.value = ''
}

async function submitAck() {
  if (!ackModal.value.alert) return
  if (!ackModal.value.note.trim()) {
    ackError.value = 'A clinical note is required before acknowledging.'
    return
  }
  ackSaving.value = true
  ackError.value  = ''
  try {
    const resp = await axios.post(
      `/participants/${props.participant.id}/medications/interactions/${ackModal.value.alert.id}/acknowledge`,
      { acknowledgement_note: ackModal.value.note.trim() }
    )
    alerts.value = alerts.value.filter(a => a.id !== ackModal.value.alert!.id)
    reviewedAlerts.value = [
      { ...resp.data, acknowledgement_note: ackModal.value.note.trim() },
      ...reviewedAlerts.value,
    ]
    ackModal.value = { open: false, alert: null, note: '' }
  } catch {
    ackError.value = 'Failed to save acknowledgement. Please try again.'
  } finally {
    ackSaving.value = false
  }
}
</script>

<template>
  <div class="space-y-6 p-6">

    <!-- Acknowledgement modal -->
    <div
      v-if="ackModal.open && ackModal.alert"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
    >
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">
          Acknowledge Drug Interaction
        </h3>
        <p class="text-sm text-gray-600 dark:text-slate-400 mb-4">
          <span class="font-medium">{{ ackModal.alert.drug_name_1 }} + {{ ackModal.alert.drug_name_2 }}</span>
          &mdash; {{ ackModal.alert.severity }}
        </p>
        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
          Clinical note <span class="text-red-500">*</span>
        </label>
        <textarea
          v-model="ackModal.note"
          rows="4"
          class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg p-2 bg-white dark:bg-slate-700 dark:text-slate-100 resize-none"
          placeholder="Describe why continuing both medications is clinically appropriate..."
          @input="ackError = ''"
        />
        <p v-if="ackError" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ ackError }}</p>
        <div class="flex justify-end gap-3 mt-4">
          <button
            :disabled="ackSaving"
            class="text-sm px-4 py-2 text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
            @click="ackModal = { open: false, alert: null, note: '' }"
          >
            Cancel
          </button>
          <button
            :disabled="ackSaving"
            class="text-sm px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 disabled:opacity-50"
            @click="submitAck"
          >
            {{ ackSaving ? 'Saving...' : 'Acknowledge & Save' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">
      Loading medications...
    </div>

    <template v-else>
      <!-- Active interaction alert banners -->
      <div v-if="alerts.length > 0" class="space-y-2">
        <div
          v-for="alert in alerts"
          :key="alert.id"
          :class="['border rounded-lg p-3 flex items-start justify-between gap-4', SEVERITY_COLORS[alert.severity] ?? 'bg-gray-50 dark:bg-slate-800 border-gray-200 dark:border-slate-700']"
        >
          <div>
            <span class="font-semibold text-sm uppercase">{{ alert.severity }}:</span>
            <span class="ml-2 text-sm">{{ alert.drug_name_1 }} + {{ alert.drug_name_2 }}</span>
            <p v-if="alert.description" class="text-xs mt-0.5 opacity-80">{{ alert.description }}</p>
          </div>
          <button
            class="text-xs px-2 py-1 bg-white dark:bg-slate-800 border border-current rounded hover:opacity-80 whitespace-nowrap shrink-0"
            @click="openAck(alert)"
          >
            Acknowledge
          </button>
        </div>
      </div>

      <!-- Reviewed interactions collapsible -->
      <div
        v-if="reviewedAlerts.length > 0"
        class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden"
      >
        <button
          class="w-full flex items-center justify-between px-4 py-2.5 bg-gray-50 dark:bg-slate-700/50 text-sm font-medium text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 transition-colors"
          @click="showReviewed = !showReviewed"
        >
          <span>Reviewed Interactions ({{ reviewedAlerts.length }})</span>
          <span class="text-xs text-gray-400 dark:text-slate-500">{{ showReviewed ? 'Hide' : 'Show' }}</span>
        </button>
        <div v-if="showReviewed" class="divide-y divide-gray-100 dark:divide-slate-700">
          <div v-for="alert in reviewedAlerts" :key="alert.id" class="px-4 py-3">
            <div class="flex items-start gap-2">
              <span :class="['text-[10px] font-bold uppercase px-1.5 py-0.5 rounded border', SEVERITY_COLORS[alert.severity] ?? 'bg-gray-100 dark:bg-slate-800 border-gray-200 dark:border-slate-700']">
                {{ alert.severity }}
              </span>
              <span class="text-sm font-medium text-gray-800 dark:text-slate-200">
                {{ alert.drug_name_1 }} + {{ alert.drug_name_2 }}
              </span>
            </div>
            <p v-if="alert.description" class="text-xs text-gray-500 dark:text-slate-400 mt-1">{{ alert.description }}</p>
            <div class="mt-2 bg-gray-50 dark:bg-slate-700/40 rounded p-2 text-xs">
              <p class="text-gray-600 dark:text-slate-300 italic">
                "{{ alert.acknowledgement_note ?? 'No note recorded.' }}"
              </p>
              <p class="text-gray-400 dark:text-slate-500 mt-1">
                Acknowledged by {{ alert.acknowledged_by_name ?? 'Unknown' }}
                <template v-if="alert.acknowledged_at">
                  on {{ new Date(alert.acknowledged_at).toLocaleDateString() }}
                </template>
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Header -->
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">
          Active Medications
          <span
            v-if="activeMeds.length > 0"
            class="ml-2 text-xs px-2 py-0.5 bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 rounded-full"
          >{{ activeMeds.length }}</span>
        </h3>
        <button
          class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          @click="showAddForm ? cancelAdd() : (showAddForm = true)"
        >
          <PlusIcon v-if="!showAddForm" class="w-3 h-3" />
          {{ showAddForm ? 'Cancel' : 'Add Medication' }}
        </button>
      </div>

      <!-- Add medication form -->
      <form
        v-if="showAddForm"
        class="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 space-y-4"
        @submit.prevent="submit"
      >
        <!-- Drug name typeahead -->
        <div class="relative">
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Drug Name *</label>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search medications..."
            required
            class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
            @input="onSearchInput"
          />
          <div
            v-if="searchResults.length > 0"
            class="absolute z-20 w-full mt-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg max-h-48 overflow-y-auto"
          >
            <button
              v-for="r in searchResults"
              :key="r.drug_name"
              type="button"
              class="w-full text-left px-3 py-2 text-sm hover:bg-blue-50 dark:hover:bg-slate-700 border-b border-gray-100 dark:border-slate-700 last:border-0"
              @mousedown="applyRef(r)"
            >
              <span class="font-medium dark:text-slate-200">{{ r.drug_name }}</span>
              <span v-if="r.drug_class" class="ml-2 text-xs text-gray-400 dark:text-slate-500">{{ r.drug_class }}</span>
              <span v-if="r.common_dose" class="ml-2 text-xs text-gray-500 dark:text-slate-400">{{ r.common_dose }} {{ r.dose_unit }}</span>
            </button>
          </div>
        </div>

        <!-- Dosing row -->
        <div class="grid grid-cols-4 gap-3">
          <div>
            <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Dose</label>
            <input
              v-model="form.dose"
              type="number"
              step="0.001"
              class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
            />
          </div>
          <div>
            <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Unit</label>
            <select
              v-model="form.dose_unit"
              class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
            >
              <option v-for="u in ['mg','mcg','ml','units','tab','cap','patch','drop']" :key="u">{{ u }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Route</label>
            <select
              v-model="form.route"
              class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
            >
              <option v-for="r in ['oral','IV','IM','subcut','topical','inhaled','sublingual','rectal','nasal','optic','otic']" :key="r">{{ r }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Frequency</label>
            <select
              v-model="form.frequency"
              class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
            >
              <option v-for="f in ['daily','BID','TID','QID','Q4H','Q6H','Q8H','Q12H','PRN','weekly','monthly','once']" :key="f">{{ f }}</option>
            </select>
          </div>
        </div>

        <!-- PRN toggle -->
        <label class="flex items-center gap-2 text-sm cursor-pointer dark:text-slate-300">
          <input v-model="form.is_prn" type="checkbox" />
          PRN (as needed)
        </label>

        <!-- Start date -->
        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Start Date *</label>
          <input
            v-model="form.start_date"
            type="date"
            required
            class="mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
          />
        </div>

        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
            @click="cancelAdd"
          >
            Cancel
          </button>
          <button
            type="submit"
            :disabled="saving"
            class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {{ saving ? 'Saving...' : 'Add Medication' }}
          </button>
        </div>
      </form>

      <!-- Active medications table -->
      <div v-if="activeMeds.length === 0 && !showAddForm" class="py-8 text-center text-gray-400 dark:text-slate-500 text-sm">
        No active medications on file.
      </div>
      <div v-else-if="activeMeds.length > 0" class="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 dark:bg-slate-700/50 text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-2 text-left">Medication</th>
              <th class="px-4 py-2 text-left">Dose / Route</th>
              <th class="px-4 py-2 text-left">Frequency</th>
              <th class="px-4 py-2 text-left">Status</th>
              <th class="px-4 py-2 text-left">Start Date</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr
              v-for="med in activeMeds"
              :key="med.id"
              class="hover:bg-gray-50 dark:hover:bg-slate-700/50"
            >
              <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-slate-100">
                {{ med.drug_name }}
                <span
                  v-if="med.is_controlled"
                  class="ml-2 text-xs px-1.5 py-0.5 bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 rounded"
                >
                  C-{{ med.controlled_schedule }}
                </span>
              </td>
              <td class="px-4 py-2.5 text-gray-600 dark:text-slate-400">
                {{ med.dose ? `${med.dose} ${med.dose_unit}` : '-' }}
                <template v-if="med.route"> ({{ med.route }})</template>
              </td>
              <td class="px-4 py-2.5 text-gray-600 dark:text-slate-400">{{ med.frequency ?? '-' }}</td>
              <td class="px-4 py-2.5">
                <span :class="['inline-block text-xs px-2 py-0.5 rounded-full font-medium', MED_STATUS_COLORS[med.status] ?? 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400']">
                  {{ med.status }}
                </span>
              </td>
              <td class="px-4 py-2.5 text-gray-500 dark:text-slate-400 text-xs">{{ med.start_date ?? '-' }}</td>
              <td class="px-4 py-2.5 text-right">
                <button
                  class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 hover:underline"
                  @click="handleDiscontinue(med)"
                >
                  Discontinue
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Discontinued / on-hold medications -->
      <details v-if="inactiveMeds.length > 0" class="mt-2">
        <summary class="text-xs font-medium text-gray-500 dark:text-slate-400 cursor-pointer hover:text-gray-700 dark:hover:text-slate-300">
          Discontinued / On-Hold ({{ inactiveMeds.length }})
        </summary>
        <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700 opacity-70">
          <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
              <tr v-for="med in inactiveMeds" :key="med.id" class="bg-gray-50 dark:bg-slate-800/50">
                <td class="px-4 py-2 text-gray-400 dark:text-slate-500 line-through">{{ med.drug_name }}</td>
                <td class="px-4 py-2 text-xs text-gray-400 dark:text-slate-500">
                  {{ med.dose ? `${med.dose} ${med.dose_unit}` : '-' }}
                </td>
                <td class="px-4 py-2 text-xs text-gray-400 dark:text-slate-500">{{ med.frequency ?? '-' }}</td>
                <td class="px-4 py-2">
                  <span class="text-xs text-gray-400 dark:text-slate-500">{{ med.status }}</span>
                </td>
                <td class="px-4 py-2 text-xs text-gray-400 dark:text-slate-500">{{ med.end_date ?? '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
    </template>
  </div>
</template>
