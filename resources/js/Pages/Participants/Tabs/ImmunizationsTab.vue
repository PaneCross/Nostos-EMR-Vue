<script setup lang="ts">
// ─── ImmunizationsTab.vue ─────────────────────────────────────────────────────
// Immunization record list. Add immunization form with VIS (Vaccine Information
// Statement) tracking — vis_given (bool) and vis_publication_date. Highlights
// annual flu and pneumococcal vaccines. Append-only per 42 CFR 460.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'

interface Immunization {
  id: number; vaccine_name: string; vaccine_code: string | null
  lot_number: string | null; site_given: string | null; route: string | null
  administered_date: string; vis_given: boolean; vis_publication_date: string | null
  notes: string | null
  administered_by: { id: number; first_name: string; last_name: string } | null
}

interface Participant { id: number }

const props = defineProps<{
  participant: Participant
}>()

const immunizations = ref<Immunization[]>([])
const loading = ref(true)
const loadError = ref('')

onMounted(async () => {
  try {
    const r = await axios.get(`/participants/${props.participant.id}/immunizations`)
    immunizations.value = r.data
  } catch {
    loadError.value = 'Failed to load immunizations.'
  } finally {
    loading.value = false
  }
})
const showAddForm = ref(false)

// Phase 8 (MVP roadmap): simulated state IIS HL7 VXU submission
async function submitToIis(immunizationId: number) {
  const state = window.prompt('State code for IIS submission (e.g. CA, NY, FL):', 'CA')
  if (!state) return
  try {
    const r = await axios.post(
      `/participants/${props.participant.id}/immunizations/${immunizationId}/iis-submit`,
      { state_code: state.trim().toUpperCase() }
    )
    const mcid = r.data?.submission?.message_control_id
    window.alert(`VXU generated and marked submitted (simulated).\nMessage ID: ${mcid}\n\n${r.data?.submission?.honest_label ?? ''}`)
  } catch (e: any) {
    window.alert(e?.response?.data?.message || 'Failed to submit VXU.')
  }
}
const saving = ref(false)
const error = ref('')

const form = ref({
  vaccine_name: '',
  vaccine_code: '',
  lot_number: '',
  site_given: 'left_deltoid',
  route: 'im',
  administered_date: new Date().toISOString().slice(0, 10),
  vis_given: false,
  vis_publication_date: '',
  notes: '',
})

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

const COMMON_VACCINES = [
  'Influenza (Flu)', 'Pneumococcal PCV15', 'Pneumococcal PPSV23',
  'COVID-19 (Updated)', 'Zoster (Shingrix)', 'Tdap', 'Hepatitis B',
]

async function submit() {
  if (!form.value.vaccine_name.trim()) { error.value = 'Vaccine name is required.'; return }
  saving.value = true; error.value = ''
  try {
    const payload = {
      ...form.value,
      vaccine_code: form.value.vaccine_code || null,
      lot_number: form.value.lot_number || null,
      vis_publication_date: form.value.vis_publication_date || null,
      notes: form.value.notes || null,
    }
    const res = await axios.post(`/participants/${props.participant.id}/immunizations`, payload)
    immunizations.value.unshift(res.data)
    showAddForm.value = false
    form.value = { vaccine_name: '', vaccine_code: '', lot_number: '', site_given: 'left_deltoid', route: 'im', administered_date: new Date().toISOString().slice(0, 10), vis_given: false, vis_publication_date: '', notes: '' }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save immunization.'
  } finally {
    // Phase W1 — Audit-11 H1: clear saving on every path.
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">Loading immunizations...</div>
    <div v-else-if="loadError" class="py-8 text-center text-red-500 dark:text-red-400 text-sm">{{ loadError }}</div>
    <template v-else>
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Immunizations</h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon class="w-3 h-3" />
        Record Immunization
      </button>
    </div>

    <!-- Add form -->
    <div v-if="showAddForm" class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Record Immunization</h3>
      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Vaccine *</label>
        <input
          v-model="form.vaccine_name"
          type="text"
          list="common-vaccines"
          placeholder="Search or type vaccine name"
          class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
        />
        <datalist id="common-vaccines">
          <option v-for="v in COMMON_VACCINES" :key="v" :value="v" />
        </datalist>
      </div>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Administered Date</label>
          <input v-model="form.administered_date" type="date" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Lot Number</label>
          <input v-model="form.lot_number" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Route</label>
          <select name="route" v-model="form.route" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option value="im">IM</option>
            <option value="subq">SubQ</option>
            <option value="intranasal">Intranasal</option>
            <option value="oral">Oral</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Site Given</label>
          <select name="site_given" v-model="form.site_given" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option value="left_deltoid">Left Deltoid</option>
            <option value="right_deltoid">Right Deltoid</option>
            <option value="left_thigh">Left Thigh</option>
            <option value="right_thigh">Right Thigh</option>
            <option value="left_arm">Left Arm</option>
            <option value="right_arm">Right Arm</option>
            <option value="intranasal">Intranasal</option>
          </select>
        </div>
      </div>
      <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-slate-300 mb-3 cursor-pointer">
        <input v-model="form.vis_given" type="checkbox" class="rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700" />
        VIS provided to patient/caregiver
      </label>
      <div v-if="form.vis_given" class="mb-3">
        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">VIS Publication Date</label>
        <input v-model="form.vis_publication_date" type="date" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
      </div>
      <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
      <div class="flex gap-2">
        <button :disabled="saving" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="submit">
          {{ saving ? 'Saving...' : 'Save' }}
        </button>
        <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors" @click="showAddForm = false">Cancel</button>
      </div>
    </div>

    <!-- Immunization list -->
    <div v-if="immunizations.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No immunizations on file.</div>
    <div v-else class="space-y-1.5">
      <div
        v-for="imm in immunizations"
        :key="imm.id"
        class="flex items-start gap-3 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
      >
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ imm.vaccine_name }}</span>
            <span v-if="imm.vis_given" class="inline-flex items-center gap-0.5 text-xs text-green-600 dark:text-green-400">
              <CheckCircleIcon class="w-3 h-3" /> VIS
            </span>
          </div>
          <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5 flex gap-2 flex-wrap">
            <span>{{ fmtDate(imm.administered_date) }}</span>
            <span v-if="imm.lot_number">Lot: {{ imm.lot_number }}</span>
            <span v-if="imm.route">{{ imm.route.toUpperCase() }}</span>
            <span v-if="imm.site_given">{{ imm.site_given.replace('_', ' ') }}</span>
            <span v-if="imm.administered_by">by {{ imm.administered_by.first_name }} {{ imm.administered_by.last_name }}</span>
          </div>
          <p v-if="imm.notes" class="text-xs text-gray-500 dark:text-slate-500 mt-0.5">{{ imm.notes }}</p>
        </div>
        <button
          type="button"
          @click="submitToIis(imm.id)"
          class="text-xs px-2 py-1 rounded border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/40 whitespace-nowrap"
          title="Generate HL7 VXU for state IIS submission (simulated)"
        >
          Submit to IIS
        </button>
      </div>
    </div>
    </template>
  </div>
</template>
