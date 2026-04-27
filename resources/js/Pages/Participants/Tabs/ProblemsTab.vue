<script setup lang="ts">
// ─── ProblemsTab.vue ──────────────────────────────────────────────────────────
// Problem list with ICD-10 codes. Active/chronic problems shown first. Add
// problem form uses a searchable ICD-10 select. Problem status can be toggled
// (active, resolved, inactive, chronic). Primary diagnosis shown with a badge.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Problem {
  id: number; icd10_code: string; icd10_description: string
  category: string | null; status: string; onset_date: string | null
  is_primary_diagnosis: boolean; notes: string | null
  added_by: { id: number; first_name: string; last_name: string } | null
}

interface Icd10Code { code: string; description: string; category: string | null }
interface Participant { id: number }

const props = defineProps<{
  participant: Participant
  problems: Problem[]
  icd10Codes?: Icd10Code[]
}>()

// Matches React: active=red, chronic=orange, resolved=green, ruled_out=gray
const STATUS_COLORS: Record<string, string> = {
  active:    'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
  chronic:   'bg-orange-100 dark:bg-orange-950/60 text-orange-700 dark:text-orange-300',
  resolved:  'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
  ruled_out: 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400',
  inactive:  'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400',
}

const STATUS_DOT: Record<string, string> = {
  active:    'bg-red-500',
  chronic:   'bg-orange-500',
  resolved:  'bg-green-500',
  ruled_out: 'bg-gray-400',
  inactive:  'bg-gray-400',
}

const STATUS_ORDER = ['active', 'chronic', 'resolved', 'ruled_out', 'inactive'] as const

const problems = ref<Problem[]>(props.problems)
const showAddForm = ref(false)
const saving = ref(false)
const error = ref('')
const icdSearch = ref('')
const selectedCode = ref<Icd10Code | null>(null)
const showSuggestions = ref(false)

const form = ref({
  status: 'active', onset_date: '', is_primary_diagnosis: false, notes: '',
})

// Group problems by status in display order
const grouped = computed(() =>
  Object.fromEntries(
    STATUS_ORDER.map(s => [s, problems.value.filter(p => p.status === s)])
  )
)

const icdMatches = computed(() => {
  if (!icdSearch.value || icdSearch.value.length < 2) return []
  const q = icdSearch.value.toLowerCase()
  return (props.icd10Codes ?? [])
    .filter(c => c.code.toUpperCase().startsWith(icdSearch.value.toUpperCase()) || c.description.toLowerCase().includes(q))
    .slice(0, 15)
})

function selectIcd(code: Icd10Code) {
  selectedCode.value = code
  icdSearch.value = code.code
  showSuggestions.value = false
}

function clearSelectedCode() {
  selectedCode.value = null
  icdSearch.value = ''
}

async function submit() {
  if (!selectedCode.value) { error.value = 'ICD-10 code is required.'; return }
  saving.value = true; error.value = ''
  try {
    const res = await axios.post(`/participants/${props.participant.id}/problems`, {
      icd10_code:           selectedCode.value.code,
      icd10_description:    selectedCode.value.description,
      category:             selectedCode.value.category,
      status:               form.value.status,
      onset_date:           form.value.onset_date || null,
      is_primary_diagnosis: form.value.is_primary_diagnosis,
      notes:                form.value.notes || null,
    })
    problems.value.unshift(res.data)
    showAddForm.value = false
    selectedCode.value = null
    icdSearch.value = ''
    form.value = { status: 'active', onset_date: '', is_primary_diagnosis: false, notes: '' }
    router.reload({ only: ['problems'] })
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save.'
  } finally {
    // Phase W1: Audit-11 H1: clear saving on every path.
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">
        Diagnoses ({{ problems.length }})
      </h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon class="w-3 h-3" />
        {{ showAddForm ? 'Cancel' : 'Add Diagnosis' }}
      </button>
    </div>

    <!-- Add problem form -->
    <div v-if="showAddForm" class="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4 space-y-3">

      <!-- ICD-10 typeahead -->
      <div class="relative">
        <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">ICD-10 Code / Diagnosis *</label>

        <!-- Selected code chip -->
        <div v-if="selectedCode" class="flex items-center gap-2 bg-white dark:bg-slate-800 border border-blue-300 dark:border-blue-700 rounded px-3 py-2">
          <span class="font-mono text-sm text-blue-700 dark:text-blue-300">{{ selectedCode.code }}</span>
          <span class="text-sm text-gray-700 dark:text-slate-300 flex-1">{{ selectedCode.description }}</span>
          <button type="button" class="text-xs text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300" @click="clearSelectedCode">&#x2715;</button>
        </div>

        <!-- Search input -->
        <div v-else class="relative">
          <input
            v-model="icdSearch"
            type="text"
            placeholder="Search by code or description (e.g. I10 or hypertension)..."
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-3 py-1.5 bg-white dark:bg-slate-800"
            @input="showSuggestions = true"
            @focus="showSuggestions = true"
            @blur="setTimeout(() => { showSuggestions = false }, 200)"
          />
          <ul v-if="showSuggestions && icdMatches.length > 0" class="absolute z-10 w-full mt-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg max-h-48 overflow-y-auto">
            <li v-for="code in icdMatches" :key="code.code">
              <button
                type="button"
                class="w-full text-left px-3 py-2 hover:bg-blue-50 dark:hover:bg-slate-700 text-sm flex items-start gap-2"
                @mousedown="selectIcd(code)"
              >
                <span class="font-mono text-blue-600 dark:text-blue-400 flex-shrink-0">{{ code.code }}</span>
                <span class="text-gray-700 dark:text-slate-300 flex-1">{{ code.description }}</span>
                <span v-if="code.category" class="text-xs text-gray-400 dark:text-slate-500 flex-shrink-0">{{ code.category }}</span>
              </button>
            </li>
          </ul>
        </div>
      </div>

      <!-- Status / Onset / Primary -->
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Status</label>
          <select name="status" v-model="form.status" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800">
            <option value="active">Active</option>
            <option value="chronic">Chronic</option>
            <option value="resolved">Resolved</option>
            <option value="ruled_out">Ruled Out</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Onset Date</label>
          <input v-model="form.onset_date" type="date" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800" />
        </div>
        <div class="flex items-end pb-1.5">
          <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-slate-400 cursor-pointer">
            <input v-model="form.is_primary_diagnosis" type="checkbox" />
            Primary diagnosis
          </label>
        </div>
      </div>

      <p v-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
      <div class="flex justify-end gap-2">
        <button
          type="button"
          class="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
          @click="showAddForm = false; clearSelectedCode()"
        >
          Cancel
        </button>
        <button
          :disabled="saving || !selectedCode"
          class="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
          @click="submit"
        >
          {{ saving ? 'Saving...' : 'Save Diagnosis' }}
        </button>
      </div>
    </div>

    <!-- Problems grouped by status -->
    <div v-if="problems.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No problems on file.</div>
    <template v-else>
      <div v-for="status in STATUS_ORDER" :key="status" class="mb-5">
        <template v-if="grouped[status]?.length">
          <h4 class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-2 flex items-center gap-2">
            <span :class="['inline-block w-2 h-2 rounded-full', STATUS_DOT[status] ?? 'bg-gray-400']" />
            {{ status.replace('_', ' ') }} ({{ grouped[status].length }})
          </h4>
          <div class="space-y-1.5">
            <div
              v-for="problem in grouped[status]"
              :key="problem.id"
              class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-2.5 flex items-center gap-3"
            >
              <span class="font-mono text-sm text-blue-600 dark:text-blue-400 flex-shrink-0">{{ problem.icd10_code }}</span>
              <span class="text-sm text-gray-800 dark:text-slate-200 flex-1">{{ problem.icd10_description }}</span>
              <span
                v-if="problem.is_primary_diagnosis"
                class="text-xs bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 px-1.5 py-0.5 rounded flex-shrink-0"
              >Primary</span>
              <span :class="['text-xs px-1.5 py-0.5 rounded font-medium flex-shrink-0', STATUS_COLORS[problem.status] ?? 'bg-gray-100 dark:bg-slate-800 text-gray-500']">
                {{ problem.status.replace('_', ' ') }}
              </span>
            </div>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>
