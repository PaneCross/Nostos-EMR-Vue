<script setup lang="ts">
// ─── IadlTab.vue ─────────────────────────────────────────────────────────────
// Phase J1 — Lawton IADL assessment. 8 binary items + interpretation bands.
// Uses existing endpoints:
//   GET  /participants/{p}/iadl   → { records, trend, baseline, current }
//   POST /participants/{p}/iadl   → record + suggestions
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Participant { id: number }
const props = defineProps<{ participant: Participant }>()

const ITEMS = [
  'telephone', 'shopping', 'food_preparation', 'housekeeping',
  'laundry', 'transportation', 'medications', 'finances',
] as const
const ITEM_LABELS: Record<string, string> = {
  telephone: 'Telephone use',
  shopping: 'Shopping',
  food_preparation: 'Food preparation',
  housekeeping: 'Housekeeping',
  laundry: 'Laundry',
  transportation: 'Transportation',
  medications: 'Responsibility for medications',
  finances: 'Ability to handle finances',
}

const loading = ref(true)
const records = ref<any[]>([])
const baseline = ref<any>(null)
const current = ref<any>(null)
const trend = ref<any[]>([])
const suggestions = ref<any[]>([])

const showForm = ref(false)
const form = ref<Record<string, 0 | 1 | null>>(
  Object.fromEntries(ITEMS.map(i => [i, null])) as Record<string, null>,
)
const notes = ref('')
const saving = ref(false)
const error = ref<string | null>(null)

function refresh() {
  loading.value = true
  axios.get(`/participants/${props.participant.id}/iadl`)
    .then(r => {
      records.value = r.data.records ?? []
      baseline.value = r.data.baseline ?? null
      current.value = r.data.current ?? null
      trend.value = r.data.trend ?? []
    })
    .finally(() => loading.value = false)
}
onMounted(refresh)

const canSave = computed(() => ITEMS.every(i => form.value[i] === 0 || form.value[i] === 1))

async function submit() {
  if (!canSave.value) return
  saving.value = true
  error.value = null
  try {
    const payload: any = { notes: notes.value || null }
    for (const i of ITEMS) payload[i] = form.value[i]
    const r = await axios.post(`/participants/${props.participant.id}/iadl`, payload)
    suggestions.value = r.data.suggestions ?? []
    showForm.value = false
    for (const i of ITEMS) form.value[i] = null
    notes.value = ''
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Save failed'
  } finally {
    saving.value = false
  }
}

function bandColor(interp: string): string {
  switch (interp) {
    case 'independent':         return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
    case 'mild_impairment':     return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
    case 'moderate_impairment': return 'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300'
    case 'severe_impairment':   return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
    default: return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
  }
}

const sparkPath = computed(() => {
  if (trend.value.length < 2) return ''
  const rev = [...trend.value].reverse() // oldest → newest
  const max = 8, min = 0
  const w = 200, h = 40
  return rev.map((pt, i) => {
    const x = (i / (rev.length - 1)) * w
    const y = h - ((pt.total_score - min) / (max - min)) * h
    return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`
  }).join(' ')
})
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">IADL — Lawton Scale</h2>
      <button
        type="button"
        class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
        @click="showForm = !showForm"
      >
        <PlusIcon class="h-4 w-4" />
        {{ showForm ? 'Cancel' : 'Record IADL' }}
      </button>
    </div>

    <!-- Form -->
    <div v-if="showForm" class="rounded border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-950/40 p-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div
          v-for="item in ITEMS"
          :key="item"
          class="flex items-center justify-between rounded bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 p-2"
        >
          <span class="text-sm text-gray-700 dark:text-slate-200">{{ ITEM_LABELS[item] }}</span>
          <div class="flex items-center gap-3">
            <label class="flex items-center gap-1 text-xs">
              <input type="radio" :name="item" :value="1" v-model="form[item]" class="h-3 w-3" />
              <span>Independent (1)</span>
            </label>
            <label class="flex items-center gap-1 text-xs">
              <input type="radio" :name="item" :value="0" v-model="form[item]" class="h-3 w-3" />
              <span>Impaired (0)</span>
            </label>
          </div>
        </div>
      </div>
      <textarea
        v-model="notes"
        rows="2"
        class="mt-3 block w-full rounded border-gray-300 dark:border-slate-600 text-sm"
        placeholder="Notes (optional)"
      />
      <div v-if="error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ error }}</div>
      <div class="mt-3 flex justify-end">
        <button
          type="button"
          :disabled="!canSave || saving"
          class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
          @click="submit"
        >
          {{ saving ? 'Saving…' : 'Save IADL' }}
        </button>
      </div>
    </div>

    <!-- Summary + trend -->
    <div v-if="!loading" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-2">Current</h3>
        <div v-if="current" class="space-y-1 text-sm">
          <div>Score: <span class="font-semibold">{{ current.total_score }}</span> / 8</div>
          <div>
            <span class="inline-block rounded px-2 py-0.5 text-xs" :class="bandColor(current.interpretation)">
              {{ (current.interpretation ?? '').replace(/_/g, ' ') }}
            </span>
          </div>
          <div class="text-xs text-gray-500 dark:text-slate-400">
            {{ new Date(current.recorded_at).toLocaleString() }}
          </div>
        </div>
        <p v-else class="text-sm text-gray-500 dark:text-slate-400">No IADL records on file.</p>
      </div>

      <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-2">Trend (oldest → newest)</h3>
        <svg v-if="sparkPath" width="200" height="40" class="text-blue-600 dark:text-blue-400">
          <path :d="sparkPath" stroke="currentColor" stroke-width="2" fill="none" />
        </svg>
        <p v-else class="text-sm text-gray-500 dark:text-slate-400">Need at least 2 records for a trend.</p>
      </div>
    </div>

    <!-- Referral suggestions -->
    <div v-if="suggestions.length" class="rounded border border-amber-300 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-3">
      <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-200 mb-1">Referral suggestions</h3>
      <ul class="list-disc ml-5 text-sm text-amber-900 dark:text-amber-200 space-y-1">
        <li v-for="s in suggestions" :key="s.dept">
          <span class="font-semibold">{{ s.dept }}:</span> {{ s.goal }}
        </li>
      </ul>
    </div>

    <!-- History -->
    <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">
          <tr>
            <th class="px-3 py-2">Date</th>
            <th class="px-3 py-2">Score</th>
            <th class="px-3 py-2">Interpretation</th>
            <th class="px-3 py-2">Recorded by</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
          <tr v-for="r in records" :key="r.id">
            <td class="px-3 py-2">{{ new Date(r.recorded_at).toLocaleDateString() }}</td>
            <td class="px-3 py-2">{{ r.total_score }}/8</td>
            <td class="px-3 py-2">
              <span class="inline-block rounded px-2 py-0.5 text-xs" :class="bandColor(r.interpretation)">
                {{ (r.interpretation ?? '').replace(/_/g, ' ') }}
              </span>
            </td>
            <td class="px-3 py-2 text-gray-500 dark:text-slate-400">
              {{ r.recorded_by ? `${r.recorded_by.first_name} ${r.recorded_by.last_name}` : '—' }}
            </td>
          </tr>
          <tr v-if="!loading && records.length === 0">
            <td colspan="4" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-slate-400">
              No IADL records yet.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
