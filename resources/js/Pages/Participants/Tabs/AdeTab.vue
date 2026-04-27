<script setup lang="ts">
// ─── AdeTab.vue ────────────────────────────────────────────────────────────
// ADE: Adverse Drug Events. Harmful medication reactions captured
// per-event with severity (mild → fatal), causality (definite →
// unlikely), and a "reported to FDA MedWatch" flag. Severity ≥ severe
// auto-creates a corresponding Allergy record (Phase C5 backend).
//
// Routes:
//   GET  /participants/{p}/ade
//   POST /participants/{p}/ade
//   POST /ade/{ade}/mark-reported
// ───────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Participant { id: number }
const props = defineProps<{ participant: Participant }>()

const SEVERITIES = ['mild', 'moderate', 'severe', 'life_threatening', 'fatal']
const CAUSALITIES = ['definite', 'probable', 'possible', 'unlikely']

const loading = ref(true)
const events = ref<any[]>([])
const meds = ref<any[]>([])

const showForm = ref(false)
const form = ref({
  medication_id: '' as number | string,
  onset_date: new Date().toISOString().slice(0, 10),
  severity: 'mild',
  causality: 'possible',
  reaction_description: '',
  outcome_text: '',
})
const saving = ref(false)
const error = ref<string | null>(null)

function refresh() {
  loading.value = true
  Promise.all([
    axios.get(`/participants/${props.participant.id}/ade`),
    axios.get(`/participants/${props.participant.id}/medications`).catch(() => ({ data: { medications: [] } })),
  ])
    .then(([r1, r2]) => {
      events.value = r1.data.events ?? []
      meds.value = r2.data.medications ?? r2.data ?? []
    })
    .finally(() => loading.value = false)
}
onMounted(refresh)

async function submit() {
  saving.value = true
  error.value = null
  try {
    const p: any = { ...form.value }
    if (p.medication_id === '') delete p.medication_id
    if (!p.outcome_text) delete p.outcome_text
    await axios.post(`/participants/${props.participant.id}/ade`, p)
    showForm.value = false
    form.value.reaction_description = ''
    form.value.outcome_text = ''
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Save failed'
  } finally {
    saving.value = false
  }
}

async function markReported(id: number) {
  const tracking = prompt('MedWatch tracking number?')
  if (!tracking) return
  try {
    await axios.post(`/ade/${id}/mark-reported`, { medwatch_tracking_number: tracking })
    refresh()
  } catch (e: any) {
    alert(e.response?.data?.message ?? 'Failed')
  }
}

function severityColor(s: string): string {
  if (['severe', 'life_threatening', 'fatal'].includes(s)) return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
  if (s === 'moderate') return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
  return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
}

function medwatchRequired(e: any): boolean {
  return ['severe', 'life_threatening', 'fatal'].includes(e.severity)
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Adverse Drug Events</h2>
      <button type="button" class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" @click="showForm = !showForm">
        <PlusIcon class="h-4 w-4" />
        {{ showForm ? 'Cancel' : 'Record ADE' }}
      </button>
    </div>

    <div v-if="showForm" class="rounded border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-950/40 p-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs mb-1">Medication (optional)</label>
          <select v-model="form.medication_id" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
            <option value="">- (unknown) -</option>
            <option v-for="m in meds" :key="m.id" :value="m.id">{{ m.drug_name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs mb-1">Onset date</label>
          <input type="date" v-model="form.onset_date" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
        <div>
          <label class="block text-xs mb-1">Severity</label>
          <select v-model="form.severity" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
            <option v-for="s in SEVERITIES" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs mb-1">Causality</label>
          <select v-model="form.causality" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
            <option v-for="c in CAUSALITIES" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
      </div>
      <textarea v-model="form.reaction_description" rows="3" class="mt-3 block w-full rounded border-gray-300 dark:border-slate-600 text-sm" placeholder="Reaction description (required, 5+ chars)" />
      <textarea v-model="form.outcome_text" rows="2" class="mt-2 block w-full rounded border-gray-300 dark:border-slate-600 text-sm" placeholder="Outcome (optional)" />
      <div v-if="error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ error }}</div>
      <div class="mt-3 flex justify-end">
        <button :disabled="saving" class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="submit">
          {{ saving ? 'Saving…' : 'Save ADE' }}
        </button>
      </div>
    </div>

    <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs uppercase text-gray-500 dark:text-slate-400">
          <tr>
            <th class="px-3 py-2">Onset</th>
            <th class="px-3 py-2">Medication</th>
            <th class="px-3 py-2">Reaction</th>
            <th class="px-3 py-2">Severity</th>
            <th class="px-3 py-2">Causality</th>
            <th class="px-3 py-2">MedWatch</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
          <tr v-for="e in events" :key="e.id">
            <td class="px-3 py-2">{{ e.onset_date }}</td>
            <td class="px-3 py-2">{{ e.medication?.drug_name ?? '-' }}</td>
            <td class="px-3 py-2 max-w-xs truncate">{{ e.reaction_description }}</td>
            <td class="px-3 py-2">
              <span class="inline-block rounded px-2 py-0.5 text-xs" :class="severityColor(e.severity)">
                {{ e.severity }}
              </span>
            </td>
            <td class="px-3 py-2">{{ e.causality }}</td>
            <td class="px-3 py-2">
              <span v-if="e.reported_to_medwatch_at" class="text-xs text-green-700 dark:text-green-300">
                Reported ({{ e.medwatch_tracking_number }})
              </span>
              <button
                v-else-if="medwatchRequired(e)"
                class="text-xs text-blue-600 dark:text-blue-400 hover:underline"
                @click="markReported(e.id)"
              >
                Mark reported
              </button>
              <span v-else class="text-xs text-gray-400">-</span>
            </td>
          </tr>
          <tr v-if="!loading && events.length === 0">
            <td colspan="6" class="px-3 py-4 text-center text-gray-500 dark:text-slate-400">
              No ADEs on record.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
