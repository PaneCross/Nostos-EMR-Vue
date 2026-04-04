<script setup lang="ts">
// ─── AssessmentsTab.vue ───────────────────────────────────────────────────────
// Assessment history list showing type, score, and status. New assessment form
// with domain-specific score fields. Most recent per domain shown first.
// Assessments are append-only — no edit or delete.
// ─────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Assessment {
  id: number; assessment_type: string; score: number | null
  score_details: Record<string, unknown> | null; status: string
  assessed_at: string; notes: string | null
  assessed_by: { id: number; first_name: string; last_name: string } | null
}

interface Participant { id: number }

const props = defineProps<{
  participant: Participant
  assessments: Assessment[]
}>()

const ASSESSMENT_LABELS: Record<string, string> = {
  mds:              'MDS (Minimum Data Set)',
  functional_status:'Functional Status',
  fall_risk:        'Fall Risk (Morse)',
  cognitive:        'Cognitive (MMSE/MoCA)',
  depression:       'Depression (PHQ-9)',
  pain:             'Pain Assessment',
  nutrition:        'Nutrition Screening',
  adl:              'ADL Assessment',
  iadl:             'IADL Assessment',
  caregiver:        'Caregiver Assessment',
  skin:             'Skin Integrity',
  wound:            'Wound Assessment',
  behavioral:       'Behavioral Assessment',
}

const STATUS_COLORS: Record<string, string> = {
  completed: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
  draft:     'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300',
  reviewed:  'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
}

const assessments = ref<Assessment[]>(props.assessments)
const showAddForm = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({
  assessment_type: 'functional_status',
  score: '',
  assessed_at: new Date().toISOString().slice(0, 16),
  notes: '',
})

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val)
  return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}

async function submit() {
  saving.value = true; error.value = ''
  const payload: Record<string, unknown> = {
    assessment_type: form.value.assessment_type,
    assessed_at: form.value.assessed_at,
    notes: form.value.notes || null,
    score: form.value.score !== '' ? parseFloat(form.value.score) : null,
  }
  try {
    const res = await axios.post(`/participants/${props.participant.id}/assessments`, payload)
    assessments.value.unshift(res.data)
    showAddForm.value = false
    form.value = { assessment_type: 'functional_status', score: '', assessed_at: new Date().toISOString().slice(0, 16), notes: '' }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save assessment.'
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Assessments</h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon class="w-3 h-3" />
        New Assessment
      </button>
    </div>

    <!-- Add assessment form -->
    <div v-if="showAddForm" class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">New Assessment</h3>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Type</label>
          <select v-model="form.assessment_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option v-for="(label, key) in ASSESSMENT_LABELS" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Score</label>
          <input v-model="form.score" type="number" step="0.1" placeholder="Optional" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Assessed At</label>
          <input v-model="form.assessed_at" type="datetime-local" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Notes</label>
          <textarea v-model="form.notes" rows="2" placeholder="Optional findings or observations" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
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

    <!-- Assessment list -->
    <div v-if="assessments.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No assessments on file.</div>
    <div v-else class="space-y-2">
      <div
        v-for="assessment in assessments"
        :key="assessment.id"
        class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
      >
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-medium text-gray-900 dark:text-slate-100">
              {{ ASSESSMENT_LABELS[assessment.assessment_type] ?? assessment.assessment_type }}
            </span>
            <span :class="['text-xs px-1.5 py-0.5 rounded', STATUS_COLORS[assessment.status] ?? '']">{{ assessment.status }}</span>
          </div>
          <div class="text-right shrink-0">
            <div v-if="assessment.score !== null" class="text-base font-bold text-gray-900 dark:text-slate-100">{{ assessment.score }}</div>
            <div class="text-xs text-gray-400 dark:text-slate-500">{{ fmtDate(assessment.assessed_at) }}</div>
          </div>
        </div>
        <div class="flex items-center gap-2 mt-1">
          <span v-if="assessment.assessed_by" class="text-xs text-gray-400 dark:text-slate-500">
            By {{ assessment.assessed_by.first_name }} {{ assessment.assessed_by.last_name }}
          </span>
        </div>
        <p v-if="assessment.notes" class="text-xs text-gray-500 dark:text-slate-400 mt-1">{{ assessment.notes }}</p>
      </div>
    </div>
  </div>
</template>
