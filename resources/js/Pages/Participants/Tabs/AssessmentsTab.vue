<script setup lang="ts">
// ─── AssessmentsTab.vue ───────────────────────────────────────────────────────
// Assessment history lazy-loaded via API on mount. Overdue + due-soon banners
// at top. Add assessment form with structured subscale scoring for Braden,
// MoCA, OHAT. Score auto-computed for structured types. Blue form background.
// Field names: completed_at, next_due_date, authored_by.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Assessment {
  id: number
  assessment_type: string
  score: number | null
  responses: Record<string, unknown> | null
  completed_at: string
  next_due_date: string | null
  notes: string | null
  authored_by: { id: number; first_name: string; last_name: string } | null
}

interface Participant { id: number }

const props = defineProps<{ participant: Participant }>()

const ASSESSMENT_LABELS: Record<string, string> = {
  initial_comprehensive: 'Initial Comprehensive',
  adl_functional:        'ADL Functional',
  mmse_cognitive:        'MMSE Cognitive',
  phq9_depression:       'PHQ-9 Depression',
  gad7_anxiety:          'GAD-7 Anxiety',
  nutritional:           'Nutritional',
  fall_risk_morse:       'Fall Risk (Morse)',
  pain_scale:            'Pain Scale',
  annual_reassessment:   'Annual Reassessment',
  custom:                'Custom',
  braden_scale:          'Braden Scale (Pressure Injury Risk)',
  moca_cognitive:        'MoCA (Cognitive Assessment)',
  oral_health:           'Oral Health Screening (OHAT)',
}

const BRADEN_SUBSCALES = [
  { key: 'sensory_perception', label: 'Sensory Perception', max: 4 },
  { key: 'moisture',           label: 'Moisture',           max: 4 },
  { key: 'activity',           label: 'Activity',           max: 4 },
  { key: 'mobility',           label: 'Mobility',           max: 4 },
  { key: 'nutrition',          label: 'Nutrition',          max: 4 },
  { key: 'friction_shear',     label: 'Friction & Shear',   max: 3 },
]
const MOCA_SUBSCALES = [
  { key: 'visuospatial',   label: 'Visuospatial / Executive', max: 5 },
  { key: 'naming',         label: 'Naming',                   max: 3 },
  { key: 'attention',      label: 'Attention',                max: 6 },
  { key: 'language',       label: 'Language',                 max: 3 },
  { key: 'abstraction',    label: 'Abstraction',              max: 2 },
  { key: 'delayed_recall', label: 'Delayed Recall',           max: 5 },
  { key: 'orientation',    label: 'Orientation',              max: 6 },
]
const OHAT_SUBSCALES = [
  { key: 'lips',          label: 'Lips',           max: 2 },
  { key: 'tongue',        label: 'Tongue',         max: 2 },
  { key: 'gums_tissues',  label: 'Gums / Tissues', max: 2 },
  { key: 'saliva',        label: 'Saliva',         max: 2 },
  { key: 'natural_teeth', label: 'Natural Teeth',  max: 2 },
  { key: 'dentures',      label: 'Dentures',       max: 2 },
  { key: 'oral_hygiene',  label: 'Oral Hygiene',   max: 2 },
  { key: 'dental_pain',   label: 'Dental Pain',    max: 2 },
]

function getSubscales(type: string) {
  if (type === 'braden_scale')  return BRADEN_SUBSCALES
  if (type === 'moca_cognitive') return MOCA_SUBSCALES
  if (type === 'oral_health')   return OHAT_SUBSCALES
  return []
}

function isStructuredType(type: string) {
  return ['braden_scale', 'moca_cognitive', 'oral_health'].includes(type)
}

// ── State ─────────────────────────────────────────────────────────────────────
const assessments  = ref<Assessment[]>([])
const loading      = ref(true)
const loadError    = ref<string | null>(null)
const showForm     = ref(false)
const saving       = ref(false)
const subscales    = ref<Record<string, string>>({})
const eduBonus     = ref(false)

const today = new Date().toISOString().slice(0, 10)

const blankForm = () => ({
  assessment_type: 'phq9_depression',
  score: '',
  completed_at: today,
  next_due_date: '',
  notes: '',
})
const form = ref(blankForm())

// ── Computed ──────────────────────────────────────────────────────────────────
const now = new Date()

const overdueList = computed(() =>
  assessments.value.filter(a => a.next_due_date && new Date(a.next_due_date.slice(0, 10)) < now)
)

const dueSoonList = computed(() =>
  assessments.value.filter(a => {
    if (!a.next_due_date) return false
    const d = new Date(a.next_due_date.slice(0, 10))
    return d >= now && d <= new Date(Date.now() + 14 * 86_400_000)
  })
)

const computedSubscaleScore = computed(() => {
  const defs = getSubscales(form.value.assessment_type)
  const total = defs.reduce((sum, s) => sum + (parseInt(subscales.value[s.key] ?? '0') || 0), 0)
  if (form.value.assessment_type === 'moca_cognitive' && eduBonus.value) return Math.min(total + 1, 30)
  return total
})

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const r = await axios.get(`/participants/${props.participant.id}/assessments`)
    assessments.value = r.data.data ?? r.data
  } catch {
    loadError.value = 'Failed to load assessments.'
  } finally {
    loading.value = false
  }
})

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtDate(val: string | null): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function resetForm() {
  form.value    = blankForm()
  subscales.value = {}
  eduBonus.value  = false
}

// ── Submit ────────────────────────────────────────────────────────────────────
async function submit(e: Event) {
  e.preventDefault()
  saving.value = true
  try {
    const structured = isStructuredType(form.value.assessment_type)
    const finalScore = structured
      ? computedSubscaleScore.value
      : (form.value.score === '' ? null : Number(form.value.score))
    const responses = structured
      ? { ...subscales.value, education_bonus: eduBonus.value, notes: form.value.notes }
      : { notes: form.value.notes }

    const r = await axios.post(`/participants/${props.participant.id}/assessments`, {
      assessment_type: form.value.assessment_type,
      score:           finalScore,
      completed_at:    form.value.completed_at,
      next_due_date:   form.value.next_due_date || null,
      responses,
    })
    assessments.value.unshift(r.data)
    showForm.value = false
    resetForm()
  } catch {
    // keep form open
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6">

    <!-- Loading / error -->
    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">Loading assessments...</div>
    <div v-else-if="loadError" class="py-8 text-center text-red-500 dark:text-red-400 text-sm">{{ loadError }}</div>

    <template v-else>
      <!-- Overdue alert banner -->
      <div
        v-if="overdueList.length > 0"
        class="mb-4 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded-lg px-4 py-3"
      >
        <p class="text-xs font-semibold text-red-700 dark:text-red-300 mb-1">Overdue Assessments ({{ overdueList.length }})</p>
        <ul class="text-xs text-red-600 dark:text-red-400 space-y-0.5">
          <li v-for="a in overdueList" :key="a.id">
            {{ ASSESSMENT_LABELS[a.assessment_type] ?? a.assessment_type }} - due {{ fmtDate(a.next_due_date) }}
          </li>
        </ul>
      </div>

      <!-- Due soon banner -->
      <div
        v-if="dueSoonList.length > 0"
        class="mb-4 bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 rounded-lg px-4 py-3"
      >
        <p class="text-xs font-semibold text-amber-700 dark:text-amber-300 mb-1">Due Within 14 Days ({{ dueSoonList.length }})</p>
        <ul class="text-xs text-amber-600 dark:text-amber-400 space-y-0.5">
          <li v-for="a in dueSoonList" :key="a.id">
            {{ ASSESSMENT_LABELS[a.assessment_type] ?? a.assessment_type }} - due {{ fmtDate(a.next_due_date) }}
          </li>
        </ul>
      </div>

      <!-- Header -->
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">
          Assessments ({{ assessments.length }})
        </h3>
        <button
          class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          @click="showForm ? (showForm = false, resetForm()) : (showForm = true)"
        >
          <PlusIcon v-if="!showForm" class="w-3 h-3" />
          {{ showForm ? 'Cancel' : 'New Assessment' }}
        </button>
      </div>

      <!-- New assessment form -->
      <form
        v-if="showForm"
        class="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4 grid grid-cols-2 gap-3"
        @submit.prevent="submit"
      >
        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Type</label>
          <select
            v-model="form.assessment_type"
            class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
          >
            <option v-for="(label, key) in ASSESSMENT_LABELS" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>

        <!-- Structured subscale section -->
        <div v-if="isStructuredType(form.assessment_type)" class="col-span-2 bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-800 rounded p-3">
          <p class="text-xs font-semibold text-indigo-700 dark:text-indigo-300 mb-2">
            Subscale Scores - Total: {{ computedSubscaleScore }}
          </p>
          <div class="grid grid-cols-3 gap-2">
            <div v-for="s in getSubscales(form.assessment_type)" :key="s.key">
              <label class="text-xs text-gray-600 dark:text-slate-400">{{ s.label }} (0-{{ s.max }})</label>
              <input
                v-model="subscales[s.key]"
                type="number"
                :min="0"
                :max="s.max"
                class="w-full mt-0.5 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-800 dark:text-slate-100"
              />
            </div>
          </div>
          <label v-if="form.assessment_type === 'moca_cognitive'" class="flex items-center gap-2 mt-2 text-xs text-gray-600 dark:text-slate-400">
            <input v-model="eduBonus" type="checkbox" />
            Add +1 education bonus (12 years or fewer of formal education)
          </label>
        </div>

        <!-- Manual score for non-structured types -->
        <div v-else>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Score (optional)</label>
          <input
            v-model="form.score"
            type="number"
            class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
          />
        </div>

        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Completed Date *</label>
          <input
            v-model="form.completed_at"
            type="date"
            required
            :max="today"
            class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
          />
        </div>

        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Next Due Date</label>
          <input
            v-model="form.next_due_date"
            type="date"
            :min="today"
            class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
          />
        </div>

        <div class="col-span-2">
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Notes / Findings</label>
          <textarea
            v-model="form.notes"
            rows="3"
            class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100 resize-none"
          />
        </div>

        <div class="col-span-2 flex justify-end gap-2">
          <button
            type="button"
            class="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700"
            @click="showForm = false; resetForm()"
          >
            Cancel
          </button>
          <button
            type="submit"
            :disabled="saving"
            class="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            {{ saving ? 'Saving...' : 'Save Assessment' }}
          </button>
        </div>
      </form>

      <!-- Assessment list -->
      <div class="space-y-2">
        <p v-if="assessments.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">No assessments on file.</p>
        <div
          v-for="a in assessments"
          :key="a.id"
          class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3 flex items-start justify-between gap-3"
        >
          <div>
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium text-gray-900 dark:text-slate-100">
                {{ ASSESSMENT_LABELS[a.assessment_type] ?? a.assessment_type }}
              </span>
              <span v-if="a.score != null" class="text-xs bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 px-1.5 py-0.5 rounded">
                Score: {{ a.score }}
              </span>
            </div>
            <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
              Completed {{ fmtDate(a.completed_at) }}
              <template v-if="a.authored_by">
                · {{ a.authored_by.first_name }} {{ a.authored_by.last_name }}
              </template>
            </div>
            <p v-if="a.notes" class="text-xs text-gray-500 dark:text-slate-400 mt-1">{{ a.notes }}</p>
          </div>
          <template v-if="a.next_due_date">
            <span :class="['text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0', (() => {
              const d = new Date(a.next_due_date!.slice(0, 10))
              if (d < now) return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
              if (d <= new Date(Date.now() + 14 * 86_400_000)) return 'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-700 dark:text-yellow-300'
              return 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'
            })()]">
              {{ new Date(a.next_due_date!.slice(0, 10)) < now ? 'OVERDUE' : 'Due' }}
              {{ fmtDate(a.next_due_date) }}
            </span>
          </template>
        </div>
      </div>
    </template>
  </div>
</template>
