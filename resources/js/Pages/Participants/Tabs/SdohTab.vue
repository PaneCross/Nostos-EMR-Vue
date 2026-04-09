<script setup lang="ts">
// ─── SdohTab.vue ──────────────────────────────────────────────────────────────
// Social Determinants of Health screening. 6 domains: housing, food, transport,
// utilities, safety, social_isolation. Risk level indicators per domain.
// History list with most recent at top. Add new SDOH record form.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface SocialDeterminant {
  id: number; domain: string; risk_level: string; response_value: string | null
  notes: string | null; screened_at: string
  screened_by: { id: number; first_name: string; last_name: string } | null
}

interface Participant { id: number }

const props = defineProps<{
  participant: Participant
}>()

const DOMAIN_LABELS: Record<string, string> = {
  housing:          'Housing',
  food:             'Food Security',
  transportation:   'Transportation',
  utilities:        'Utilities',
  safety:           'Safety',
  social_isolation: 'Social Isolation',
  employment:       'Employment',
  education:        'Education',
  financial_strain: 'Financial Strain',
  interpersonal_safety: 'Interpersonal Safety',
}

const RISK_COLORS: Record<string, string> = {
  high:     'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300',
  medium:   'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300',
  low:      'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
  none:     'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
  unknown:  'bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500',
}

const records = ref<SocialDeterminant[]>([])
const loading = ref(true)
const loadError = ref('')
const showAddForm = ref(false)
const saving = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    const r = await axios.get(`/participants/${props.participant.id}/social-determinants`)
    records.value = r.data
  } catch {
    loadError.value = 'Failed to load SDOH records.'
  } finally {
    loading.value = false
  }
})

const form = ref({
  domain: 'housing',
  risk_level: 'unknown',
  response_value: '',
  notes: '',
  screened_at: new Date().toISOString().slice(0, 10),
})

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function submit() {
  saving.value = true; error.value = ''
  try {
    const res = await axios.post(`/participants/${props.participant.id}/social-determinants`, {
      ...form.value,
      response_value: form.value.response_value || null,
      notes: form.value.notes || null,
    })
    records.value.unshift(res.data)
    showAddForm.value = false
    form.value = { domain: 'housing', risk_level: 'unknown', response_value: '', notes: '', screened_at: new Date().toISOString().slice(0, 10) }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save.'
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">Loading SDOH records...</div>
    <div v-else-if="loadError" class="py-8 text-center text-red-500 dark:text-red-400 text-sm">{{ loadError }}</div>
    <template v-else>
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Social Determinants of Health</h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon class="w-3 h-3" />
        Add Screening
      </button>
    </div>

    <!-- Add form -->
    <div v-if="showAddForm" class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">New SDOH Screening</h3>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Domain</label>
          <select v-model="form.domain" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option v-for="(label, key) in DOMAIN_LABELS" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Risk Level</label>
          <select v-model="form.risk_level" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
            <option value="none">None</option>
            <option value="unknown">Unknown</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Screened Date</label>
          <input v-model="form.screened_at" type="date" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Response</label>
          <input v-model="form.response_value" type="text" placeholder="Optional" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
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

    <!-- SDOH records -->
    <div v-if="records.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No SDOH screenings on file.</div>
    <div v-else class="space-y-2">
      <div
        v-for="record in records"
        :key="record.id"
        class="flex items-start gap-3 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
      >
        <span :class="['text-xs font-medium px-2 py-0.5 rounded shrink-0 mt-0.5', RISK_COLORS[record.risk_level] ?? RISK_COLORS.unknown]">
          {{ record.risk_level.toUpperCase() }}
        </span>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ DOMAIN_LABELS[record.domain] ?? record.domain }}</span>
          </div>
          <div v-if="record.response_value" class="text-xs text-gray-600 dark:text-slate-400 mt-0.5">{{ record.response_value }}</div>
          <div v-if="record.notes" class="text-xs text-gray-500 dark:text-slate-500 mt-0.5">{{ record.notes }}</div>
          <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
            {{ fmtDate(record.screened_at) }}
            <span v-if="record.screened_by"> by {{ record.screened_by.first_name }} {{ record.screened_by.last_name }}</span>
          </div>
        </div>
      </div>
    </div>
    </template>
  </div>
</template>
