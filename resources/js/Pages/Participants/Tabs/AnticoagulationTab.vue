<script setup lang="ts">
// ─── AnticoagulationTab.vue ──────────────────────────────────────────────────
// Phase J2 — Anticoagulation plan + INR tracking (warfarin + DOACs).
//   GET  /participants/{p}/anticoagulation
//   POST /participants/{p}/anticoagulation/plans
//   POST /anticoagulation-plans/{plan}/stop
//   POST /participants/{p}/anticoagulation/inr
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Participant { id: number }
const props = defineProps<{ participant: Participant }>()

const AGENTS = ['warfarin', 'apixaban', 'rivaroxaban', 'dabigatran', 'edoxaban', 'enoxaparin', 'other']

const loading = ref(true)
const activePlan = ref<any>(null)
const plans = ref<any[]>([])
const inrTrend = ref<any[]>([])
const showPlanForm = ref(false)
const showInrForm = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const planForm = ref({
  agent: 'warfarin',
  target_inr_low: '',
  target_inr_high: '',
  monitoring_interval_days: 14,
  start_date: new Date().toISOString().slice(0, 10),
  notes: '',
})
const inrForm = ref({
  value: '',
  drawn_at: new Date().toISOString().slice(0, 16),
  dose_adjustment_text: '',
  notes: '',
})

function refresh() {
  loading.value = true
  axios.get(`/participants/${props.participant.id}/anticoagulation`)
    .then(r => {
      activePlan.value = r.data.active_plan ?? null
      plans.value = r.data.plans ?? []
      inrTrend.value = r.data.inr_trend ?? []
    })
    .finally(() => loading.value = false)
}
onMounted(refresh)

const nextDrawChip = computed(() => {
  if (!activePlan.value || activePlan.value.agent !== 'warfarin') return null
  const interval = activePlan.value.monitoring_interval_days ?? 14
  const latest = inrTrend.value[0]
  if (!latest) return { text: 'No INR on record', color: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' }
  const drawn = new Date(latest.drawn_at)
  const daysSince = Math.floor((Date.now() - drawn.getTime()) / 86400000)
  const remaining = interval - daysSince
  if (remaining < 0) return { text: `${Math.abs(remaining)}d overdue`, color: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' }
  if (remaining <= 3) return { text: `Due in ${remaining}d`, color: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' }
  return { text: `Next draw in ${remaining}d`, color: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' }
})

async function savePlan() {
  saving.value = true
  error.value = null
  try {
    const p: any = { ...planForm.value }
    if (p.target_inr_low === '') delete p.target_inr_low
    if (p.target_inr_high === '') delete p.target_inr_high
    await axios.post(`/participants/${props.participant.id}/anticoagulation/plans`, p)
    showPlanForm.value = false
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Save failed'
  } finally {
    saving.value = false
  }
}

async function saveInr() {
  saving.value = true
  error.value = null
  try {
    const p: any = { ...inrForm.value }
    if (!p.dose_adjustment_text) delete p.dose_adjustment_text
    if (!p.notes) delete p.notes
    await axios.post(`/participants/${props.participant.id}/anticoagulation/inr`, p)
    showInrForm.value = false
    inrForm.value.value = ''
    inrForm.value.dose_adjustment_text = ''
    inrForm.value.notes = ''
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Save failed'
  } finally {
    saving.value = false
  }
}

async function stopPlan(id: number) {
  const reason = prompt('Reason for stopping this plan?')
  if (!reason) return
  await axios.post(`/anticoagulation-plans/${id}/stop`, {
    stop_date: new Date().toISOString().slice(0, 10),
    stop_reason: reason,
  })
  refresh()
}

function inRange(v: any): boolean {
  return v?.in_range === true
}

const sparkPath = computed(() => {
  if (inrTrend.value.length < 2) return ''
  const rev = [...inrTrend.value].reverse() // oldest first
  const values = rev.map(r => parseFloat(r.value))
  const min = Math.min(...values, 1), max = Math.max(...values, 4)
  const w = 240, h = 50
  return rev.map((r, i) => {
    const x = (i / (rev.length - 1)) * w
    const y = h - ((parseFloat(r.value) - min) / (max - min || 1)) * h
    return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`
  }).join(' ')
})
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Anticoagulation</h2>
      <div class="flex gap-2">
        <button type="button" class="rounded bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" @click="showPlanForm = !showPlanForm">
          {{ showPlanForm ? 'Cancel' : 'New plan' }}
        </button>
        <button
          type="button"
          :disabled="!activePlan"
          class="inline-flex items-center gap-1 rounded bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
          @click="showInrForm = !showInrForm"
        >
          <PlusIcon class="h-4 w-4" />
          Record INR
        </button>
      </div>
    </div>

    <!-- Active plan card -->
    <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-2">Active plan</h3>
      <div v-if="activePlan" class="flex flex-wrap items-center gap-4 text-sm">
        <div><span class="font-semibold">Agent:</span> {{ activePlan.agent }}</div>
        <div v-if="activePlan.target_inr_low">
          <span class="font-semibold">Target:</span> {{ activePlan.target_inr_low }} – {{ activePlan.target_inr_high }}
        </div>
        <div><span class="font-semibold">Since:</span> {{ activePlan.start_date }}</div>
        <span v-if="nextDrawChip" class="inline-block rounded px-2 py-0.5 text-xs" :class="nextDrawChip.color">
          {{ nextDrawChip.text }}
        </span>
        <button class="ml-auto text-sm text-red-600 hover:underline dark:text-red-400" @click="stopPlan(activePlan.id)">
          Stop plan
        </button>
      </div>
      <p v-else class="text-sm text-gray-500 dark:text-slate-400">No active plan.</p>
    </div>

    <!-- Plan form -->
    <div v-if="showPlanForm" class="rounded border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-950/40 p-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs mb-1">Agent</label>
          <select v-model="planForm.agent" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
            <option v-for="a in AGENTS" :key="a" :value="a">{{ a }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs mb-1">Start date</label>
          <input type="date" v-model="planForm.start_date" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
        <div v-if="planForm.agent === 'warfarin'">
          <label class="block text-xs mb-1">Target INR (low)</label>
          <input type="number" step="0.1" v-model="planForm.target_inr_low" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
        <div v-if="planForm.agent === 'warfarin'">
          <label class="block text-xs mb-1">Target INR (high)</label>
          <input type="number" step="0.1" v-model="planForm.target_inr_high" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
        <div>
          <label class="block text-xs mb-1">Monitoring interval (days)</label>
          <input type="number" min="1" max="180" v-model="planForm.monitoring_interval_days" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
      </div>
      <textarea v-model="planForm.notes" rows="2" class="mt-3 block w-full rounded border-gray-300 dark:border-slate-600 text-sm" placeholder="Notes (optional)" />
      <div v-if="error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ error }}</div>
      <div class="mt-3 flex justify-end">
        <button :disabled="saving" class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="savePlan">
          {{ saving ? 'Saving…' : 'Create plan' }}
        </button>
      </div>
    </div>

    <!-- INR form -->
    <div v-if="showInrForm" class="rounded border border-green-200 dark:border-green-900 bg-green-50 dark:bg-green-950/40 p-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs mb-1">INR value</label>
          <input type="number" step="0.1" min="0.5" max="15" v-model="inrForm.value" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
        <div>
          <label class="block text-xs mb-1">Drawn at</label>
          <input type="datetime-local" v-model="inrForm.drawn_at" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
      </div>
      <textarea v-model="inrForm.dose_adjustment_text" rows="2" class="mt-3 block w-full rounded border-gray-300 dark:border-slate-600 text-sm" placeholder="Dose adjustment (optional)" />
      <div class="mt-3 flex justify-end">
        <button :disabled="saving" class="rounded bg-green-600 px-3 py-1.5 text-sm text-white hover:bg-green-700 disabled:opacity-50" @click="saveInr">
          {{ saving ? 'Saving…' : 'Record INR' }}
        </button>
      </div>
    </div>

    <!-- INR trend sparkline + table -->
    <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-2">INR trend (last 10)</h3>
      <svg v-if="sparkPath" width="240" height="50" class="text-blue-600 dark:text-blue-400">
        <path :d="sparkPath" stroke="currentColor" stroke-width="2" fill="none" />
      </svg>
      <p v-else class="text-sm text-gray-500 dark:text-slate-400 mb-2">Need at least 2 values for a trend.</p>

      <table class="mt-3 min-w-full text-sm">
        <thead class="text-xs uppercase text-gray-500 dark:text-slate-400">
          <tr>
            <th class="px-2 py-1 text-left">Drawn</th>
            <th class="px-2 py-1 text-left">Value</th>
            <th class="px-2 py-1 text-left">In range</th>
            <th class="px-2 py-1 text-left">Dose adjustment</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
          <tr v-for="r in inrTrend" :key="r.id">
            <td class="px-2 py-1">{{ new Date(r.drawn_at).toLocaleString() }}</td>
            <td class="px-2 py-1">{{ r.value }}</td>
            <td class="px-2 py-1">
              <span v-if="r.in_range !== null" class="inline-block rounded px-2 py-0.5 text-xs" :class="inRange(r) ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'">
                {{ inRange(r) ? 'In range' : 'Out of range' }}
              </span>
              <span v-else class="text-gray-400">—</span>
            </td>
            <td class="px-2 py-1 text-gray-500 dark:text-slate-400">{{ r.dose_adjustment_text || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
