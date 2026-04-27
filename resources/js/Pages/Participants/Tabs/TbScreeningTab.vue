<script setup lang="ts">
// ─── TbScreeningTab.vue ────────────────────────────────────────────────────
// TB (Tuberculosis) screening. PACE regulation 42 CFR §460.71 requires
// annual screening for every participant. Supported test types: PPD
// (Mantoux skin test: induration_mm required), QuantiFERON, T-SPOT,
// chest X-ray, symptom-only. Backend job warns at 60/30/today and
// flags overdue.
//
// Routes: GET/POST /participants/{p}/tb-screenings.
// ───────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Participant { id: number }
const props = defineProps<{ participant: Participant }>()

const TYPES = [
  { value: 'ppd', label: 'PPD (Mantoux)' },
  { value: 'quantiferon', label: 'QuantiFERON' },
  { value: 't_spot', label: 'T-SPOT' },
  { value: 'chest_xray', label: 'Chest X-ray' },
  { value: 'symptom_only', label: 'Symptom only' },
]
const RESULTS = ['positive', 'negative', 'indeterminate']

const loading = ref(true)
const records = ref<any[]>([])
const latest = ref<any>(null)
const daysUntilDue = ref<number | null>(null)

const showForm = ref(false)
const form = ref({
  screening_type: 'ppd',
  performed_date: new Date().toISOString().slice(0, 10),
  result: 'negative',
  induration_mm: '' as string | number,
  follow_up_text: '',
  notes: '',
})
const saving = ref(false)
const error = ref<string | null>(null)

function refresh() {
  loading.value = true
  axios.get(`/participants/${props.participant.id}/tb-screenings`)
    .then(r => {
      records.value = r.data.records ?? []
      latest.value = r.data.latest ?? null
      daysUntilDue.value = r.data.days_until_due ?? null
    })
    .finally(() => loading.value = false)
}
onMounted(refresh)

const dueChipColor = computed(() => {
  if (daysUntilDue.value == null) return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
  if (daysUntilDue.value < 0) return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
  if (daysUntilDue.value <= 30) return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
  return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
})

async function submit() {
  saving.value = true
  error.value = null
  try {
    const payload: any = { ...form.value }
    if (payload.induration_mm === '') delete payload.induration_mm
    if (!payload.follow_up_text) delete payload.follow_up_text
    if (!payload.notes) delete payload.notes
    await axios.post(`/participants/${props.participant.id}/tb-screenings`, payload)
    showForm.value = false
    form.value.induration_mm = ''
    form.value.follow_up_text = ''
    form.value.notes = ''
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Save failed'
  } finally {
    saving.value = false
  }
}

function resultColor(r: string): string {
  if (r === 'positive') return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
  if (r === 'indeterminate') return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
  return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">TB Screening</h2>
        <span v-if="latest" class="inline-block rounded px-2 py-0.5 text-xs" :class="dueChipColor">
          <template v-if="daysUntilDue == null">-</template>
          <template v-else-if="daysUntilDue < 0">{{ Math.abs(daysUntilDue) }}d overdue</template>
          <template v-else-if="daysUntilDue === 0">Due today</template>
          <template v-else>Due in {{ daysUntilDue }}d</template>
        </span>
      </div>
      <button
        type="button"
        class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
        @click="showForm = !showForm"
      >
        <PlusIcon class="h-4 w-4" />
        {{ showForm ? 'Cancel' : 'Record screening' }}
      </button>
    </div>

    <div v-if="showForm" class="rounded border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-950/40 p-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-gray-600 dark:text-slate-300 mb-1">Screening type</label>
          <select v-model="form.screening_type" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
            <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs text-gray-600 dark:text-slate-300 mb-1">Performed date</label>
          <input type="date" v-model="form.performed_date" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
        <div>
          <label class="block text-xs text-gray-600 dark:text-slate-300 mb-1">Result</label>
          <select v-model="form.result" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
            <option v-for="r in RESULTS" :key="r" :value="r">{{ r }}</option>
          </select>
        </div>
        <div v-if="form.screening_type === 'ppd'">
          <label class="block text-xs text-gray-600 dark:text-slate-300 mb-1">Induration (mm)</label>
          <input
            type="number"
            step="0.1"
            min="0"
            max="99.9"
            v-model="form.induration_mm"
            class="w-full rounded border-gray-300 dark:border-slate-600 text-sm"
          />
        </div>
      </div>
      <textarea
        v-model="form.follow_up_text"
        rows="2"
        class="mt-3 block w-full rounded border-gray-300 dark:border-slate-600 text-sm"
        placeholder="Follow-up plan (optional)"
      />
      <textarea
        v-model="form.notes"
        rows="2"
        class="mt-2 block w-full rounded border-gray-300 dark:border-slate-600 text-sm"
        placeholder="Notes (optional)"
      />
      <div v-if="error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ error }}</div>
      <div class="mt-3 flex justify-end">
        <button type="button" :disabled="saving" class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="submit">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </div>

    <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs uppercase text-gray-500 dark:text-slate-400">
          <tr>
            <th class="px-3 py-2">Date</th>
            <th class="px-3 py-2">Type</th>
            <th class="px-3 py-2">Result</th>
            <th class="px-3 py-2">Induration</th>
            <th class="px-3 py-2">Next due</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
          <tr v-for="r in records" :key="r.id">
            <td class="px-3 py-2">{{ r.performed_date }}</td>
            <td class="px-3 py-2">{{ r.screening_type }}</td>
            <td class="px-3 py-2">
              <span class="inline-block rounded px-2 py-0.5 text-xs" :class="resultColor(r.result)">
                {{ r.result }}
              </span>
            </td>
            <td class="px-3 py-2">{{ r.induration_mm ?? '-' }}</td>
            <td class="px-3 py-2">{{ r.next_due_date ?? '-' }}</td>
          </tr>
          <tr v-if="!loading && records.length === 0">
            <td colspan="5" class="px-3 py-4 text-center text-gray-500 dark:text-slate-400">
              No TB screenings on record.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
