<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ClipboardDocumentListIcon, ChevronDownIcon, ChevronUpIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
  participant: { id: number; first_name: string; last_name: string }
}>()

interface AdlRecord {
  id: number
  assessed_at: string
  assessment_type: string | null
  assessed_by: { first_name: string; last_name: string } | null
  bathing: string | null
  dressing: string | null
  grooming: string | null
  oral_hygiene: string | null
  toileting: string | null
  continence: string | null
  transferring: string | null
  ambulation: string | null
  feeding: string | null
  meal_preparation: string | null
  housekeeping: string | null
  laundry: string | null
  medication_management: string | null
  transportation: string | null
  notes: string | null
}

const records = ref<AdlRecord[]>([])
const loading = ref(true)
const error = ref('')
const expanded = ref<number | null>(null)

const ADL_FIELDS: Array<{ key: keyof AdlRecord; label: string }> = [
  { key: 'bathing',               label: 'Bathing' },
  { key: 'dressing',              label: 'Dressing' },
  { key: 'grooming',              label: 'Grooming' },
  { key: 'oral_hygiene',          label: 'Oral Hygiene' },
  { key: 'toileting',             label: 'Toileting' },
  { key: 'continence',            label: 'Continence' },
  { key: 'transferring',          label: 'Transferring' },
  { key: 'ambulation',            label: 'Ambulation' },
  { key: 'feeding',               label: 'Feeding' },
  { key: 'meal_preparation',      label: 'Meal Preparation' },
  { key: 'housekeeping',          label: 'Housekeeping' },
  { key: 'laundry',               label: 'Laundry' },
  { key: 'medication_management', label: 'Medication Management' },
  { key: 'transportation',        label: 'Transportation' },
]

const DEPENDENCY_COLORS: Record<string, string> = {
  independent:       'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
  supervision:       'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
  limited_assist:    'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300',
  extensive_assist:  'bg-orange-100 dark:bg-orange-950/40 text-orange-700 dark:text-orange-300',
  total_dependence:  'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
  activity_did_not_occur: 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
}

onMounted(async () => {
  try {
    const res = await axios.get(`/participants/${props.participant.id}/adl-records`)
    records.value = res.data
  } catch {
    error.value = 'Unable to load ADL records.'
  } finally {
    loading.value = false
  }
})

function fmtDate(val: string): string {
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

function depLabel(val: string | null): string {
  if (!val) return '-'
  return val.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}
</script>

<template>
  <div class="p-6 max-w-4xl space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Activities of Daily Living</h2>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <!-- Error -->
    <div v-else-if="error" class="rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-300">
      {{ error }}
    </div>

    <!-- Empty -->
    <div v-else-if="records.length === 0"
      class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-12 text-center"
    >
      <ClipboardDocumentListIcon class="w-8 h-8 mx-auto text-gray-300 dark:text-slate-600 mb-2" />
      <p class="text-sm text-gray-500 dark:text-slate-400">No ADL assessments on file.</p>
    </div>

    <!-- Records list -->
    <div v-else class="space-y-3">
      <div
        v-for="rec in records"
        :key="rec.id"
        class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden"
      >
        <!-- Header row -->
        <button
          class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
          @click="expanded = expanded === rec.id ? null : rec.id"
        >
          <div class="flex items-center gap-3">
            <span class="text-sm font-medium text-gray-800 dark:text-slate-200">{{ fmtDate(rec.assessed_at) }}</span>
            <span v-if="rec.assessment_type" class="text-xs text-gray-500 dark:text-slate-400 capitalize">{{ rec.assessment_type.replace(/_/g, ' ') }}</span>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="rec.assessed_by" class="text-xs text-gray-500 dark:text-slate-400">
              {{ rec.assessed_by.first_name }} {{ rec.assessed_by.last_name }}
            </span>
            <ChevronDownIcon v-if="expanded !== rec.id" class="w-4 h-4 text-gray-400" />
            <ChevronUpIcon v-else class="w-4 h-4 text-gray-400" />
          </div>
        </button>

        <!-- Expanded detail -->
        <div v-if="expanded === rec.id" class="border-t border-gray-100 dark:border-slate-700 px-4 py-4">
          <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
            <div v-for="field in ADL_FIELDS" :key="field.key" class="flex flex-col">
              <span class="text-[10px] text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-0.5">{{ field.label }}</span>
              <span
                v-if="rec[field.key]"
                :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium', DEPENDENCY_COLORS[(rec[field.key] as string)] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300']"
              >
                {{ depLabel(rec[field.key] as string) }}
              </span>
              <span v-else class="text-xs text-gray-400 dark:text-slate-500">-</span>
            </div>
          </div>
          <div v-if="rec.notes" class="mt-3 text-xs text-gray-600 dark:text-slate-400 bg-gray-50 dark:bg-slate-700/50 rounded px-3 py-2">
            {{ rec.notes }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
