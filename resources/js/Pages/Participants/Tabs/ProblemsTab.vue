<script setup lang="ts">
// ─── ProblemsTab.vue ──────────────────────────────────────────────────────────
// Problem list with ICD-10 codes. Active/chronic problems shown first. Add
// problem form uses a searchable ICD-10 select. Problem status can be toggled
// (active, resolved, inactive, chronic). Primary diagnosis shown with a badge.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
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

const STATUS_COLORS: Record<string, string> = {
  active:    'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
  chronic:   'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
  resolved:  'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
  inactive:  'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-500',
  ruled_out: 'bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-600',
}

const problems = ref<Problem[]>(props.problems)
const showAddForm = ref(false)
const saving = ref(false)
const error = ref('')
const filterStatus = ref('')
const icdSearch = ref('')

const form = ref({
  icd10_code: '', icd10_description: '', status: 'active',
  onset_date: '', is_primary_diagnosis: false, notes: '',
})

const filteredProblems = computed(() =>
  filterStatus.value ? problems.value.filter(p => p.status === filterStatus.value) : problems.value
)

const icdMatches = computed(() => {
  if (!icdSearch.value || icdSearch.value.length < 2) return []
  const q = icdSearch.value.toLowerCase()
  return (props.icd10Codes ?? [])
    .filter(c => c.code.toLowerCase().includes(q) || c.description.toLowerCase().includes(q))
    .slice(0, 10)
})

function selectIcd(code: Icd10Code) {
  form.value.icd10_code = code.code
  form.value.icd10_description = code.description
  icdSearch.value = `${code.code} - ${code.description}`
}

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function submit() {
  if (!form.value.icd10_code) { error.value = 'ICD-10 code is required.'; return }
  saving.value = true; error.value = ''
  try {
    const res = await axios.post(`/participants/${props.participant.id}/problems`, form.value)
    problems.value.unshift(res.data)
    showAddForm.value = false
    form.value = { icd10_code: '', icd10_description: '', status: 'active', onset_date: '', is_primary_diagnosis: false, notes: '' }
    icdSearch.value = ''
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save.'
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-4 gap-2 flex-wrap">
      <div class="flex items-center gap-2">
        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Problem List</h2>
        <span class="text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-2 py-0.5 rounded">{{ filteredProblems.length }}</span>
      </div>
      <div class="flex items-center gap-2">
        <select v-model="filterStatus" class="text-xs border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="chronic">Chronic</option>
          <option value="resolved">Resolved</option>
          <option value="inactive">Inactive</option>
        </select>
        <button
          class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          @click="showAddForm = !showAddForm"
        >
          <PlusIcon class="w-3 h-3" />
          Add Problem
        </button>
      </div>
    </div>

    <!-- Add problem form -->
    <div v-if="showAddForm" class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Add Problem</h3>

      <!-- ICD-10 search -->
      <div class="relative mb-3">
        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">ICD-10 Code Search *</label>
        <input
          v-model="icdSearch"
          type="text"
          placeholder="Search code or description..."
          class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
        />
        <div v-if="icdMatches.length > 0" class="absolute z-10 w-full mt-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg max-h-40 overflow-y-auto">
          <button
            v-for="code in icdMatches"
            :key="code.code"
            class="w-full text-left px-3 py-2 text-xs hover:bg-gray-50 dark:hover:bg-slate-700 border-b border-gray-100 dark:border-slate-700 last:border-0"
            @click="selectIcd(code)"
          >
            <span class="font-mono font-semibold text-blue-600 dark:text-blue-400">{{ code.code }}</span>
            <span class="ml-2 text-gray-700 dark:text-slate-300">{{ code.description }}</span>
          </button>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Status</label>
          <select v-model="form.status" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option value="active">Active</option>
            <option value="chronic">Chronic</option>
            <option value="resolved">Resolved</option>
            <option value="inactive">Inactive</option>
            <option value="ruled_out">Ruled Out</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Onset Date</label>
          <input v-model="form.onset_date" type="date" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
      </div>
      <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-slate-300 mb-3 cursor-pointer">
        <input v-model="form.is_primary_diagnosis" type="checkbox" class="rounded border-gray-300" />
        Primary Diagnosis
      </label>
      <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
      <div class="flex gap-2">
        <button :disabled="saving" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="submit">
          {{ saving ? 'Saving...' : 'Save' }}
        </button>
        <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors" @click="showAddForm = false">Cancel</button>
      </div>
    </div>

    <!-- Problem list -->
    <div v-if="filteredProblems.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No problems found.</div>
    <div v-else class="space-y-1.5">
      <div
        v-for="problem in filteredProblems"
        :key="problem.id"
        class="flex items-start gap-3 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
      >
        <span class="font-mono text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-1.5 py-0.5 rounded mt-0.5 shrink-0">
          {{ problem.icd10_code }}
        </span>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ problem.icd10_description }}</span>
            <span v-if="problem.is_primary_diagnosis" class="text-xs bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded">Primary</span>
            <span :class="['text-xs px-1.5 py-0.5 rounded font-medium', STATUS_COLORS[problem.status] ?? '']">{{ problem.status }}</span>
          </div>
          <div v-if="problem.onset_date" class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">Onset: {{ fmtDate(problem.onset_date) }}</div>
          <div v-if="problem.notes" class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ problem.notes }}</div>
        </div>
      </div>
    </div>
  </div>
</template>
