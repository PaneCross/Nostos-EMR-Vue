<script setup lang="ts">
// ─── ProceduresTab.vue ────────────────────────────────────────────────────────
// Procedure history with CPT codes. Source badge: internal (performed here),
// external (outside record), or patient_reported. Add procedure form.
// Append-only audit trail per clinical documentation standards.
// ─────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Procedure {
  id: number; procedure_name: string; cpt_code: string | null
  performed_date: string; source: string; provider_name: string | null
  facility_name: string | null; notes: string | null
  recorded_by: { id: number; first_name: string; last_name: string } | null
}

interface Participant { id: number }

const props = defineProps<{
  participant: Participant
  procedures: Procedure[]
}>()

const SOURCE_BADGES: Record<string, string> = {
  internal:         'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
  external:         'bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300',
  patient_reported: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
}

const SOURCE_LABELS: Record<string, string> = {
  internal: 'Internal', external: 'External', patient_reported: 'Patient Reported',
}

const procedures = ref<Procedure[]>(props.procedures)
const showAddForm = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({
  procedure_name: '',
  cpt_code: '',
  performed_date: new Date().toISOString().slice(0, 10),
  source: 'internal',
  provider_name: '',
  facility_name: '',
  notes: '',
})

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function submit() {
  if (!form.value.procedure_name.trim()) { error.value = 'Procedure name is required.'; return }
  saving.value = true; error.value = ''
  try {
    const payload = {
      ...form.value,
      cpt_code: form.value.cpt_code || null,
      provider_name: form.value.provider_name || null,
      facility_name: form.value.facility_name || null,
      notes: form.value.notes || null,
    }
    const res = await axios.post(`/participants/${props.participant.id}/procedures`, payload)
    procedures.value.unshift(res.data)
    showAddForm.value = false
    form.value = { procedure_name: '', cpt_code: '', performed_date: new Date().toISOString().slice(0, 10), source: 'internal', provider_name: '', facility_name: '', notes: '' }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save procedure.'
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Procedures</h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon class="w-3 h-3" />
        Add Procedure
      </button>
    </div>

    <!-- Add form -->
    <div v-if="showAddForm" class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Add Procedure</h3>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Procedure Name *</label>
          <input v-model="form.procedure_name" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">CPT Code</label>
          <input v-model="form.cpt_code" type="text" placeholder="Optional" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Performed Date</label>
          <input v-model="form.performed_date" type="date" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Source</label>
          <select v-model="form.source" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option value="internal">Internal</option>
            <option value="external">External</option>
            <option value="patient_reported">Patient Reported</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Provider Name</label>
          <input v-model="form.provider_name" type="text" placeholder="Optional" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Facility</label>
          <input v-model="form.facility_name" type="text" placeholder="Optional" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Notes</label>
          <textarea v-model="form.notes" rows="2" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
      </div>
      <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
      <div class="flex gap-2">
        <button :disabled="saving" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="submit">
          {{ saving ? 'Saving...' : 'Save' }}
        </button>
        <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors" @click="showAddForm = false">Cancel</button>
      </div>
    </div>

    <!-- Procedure list -->
    <div v-if="procedures.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No procedures on file.</div>
    <div v-else class="space-y-1.5">
      <div
        v-for="proc in procedures"
        :key="proc.id"
        class="flex items-start gap-3 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
      >
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ proc.procedure_name }}</span>
            <span v-if="proc.cpt_code" class="font-mono text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-1.5 py-0.5 rounded">{{ proc.cpt_code }}</span>
            <span :class="['text-xs px-1.5 py-0.5 rounded', SOURCE_BADGES[proc.source] ?? '']">{{ SOURCE_LABELS[proc.source] ?? proc.source }}</span>
          </div>
          <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5 flex gap-2 flex-wrap">
            <span>{{ fmtDate(proc.performed_date) }}</span>
            <span v-if="proc.provider_name">{{ proc.provider_name }}</span>
            <span v-if="proc.facility_name">{{ proc.facility_name }}</span>
          </div>
          <p v-if="proc.notes" class="text-xs text-gray-500 dark:text-slate-400 mt-1">{{ proc.notes }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
