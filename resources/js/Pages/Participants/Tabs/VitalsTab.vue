<script setup lang="ts">
// ─── Tabs/VitalsTab.vue ───────────────────────────────────────────────────────
// Vitals recording and history. Initial vitals come from Inertia prop. New
// vitals are submitted via POST /participants/{id}/vitals. Shows the 10 most
// recent readings in a table with out-of-range highlighting. Transfer events
// appear as timeline separators when the participant has moved between sites.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Vital {
  id: number; recorded_at: string
  systolic_bp: number | null; diastolic_bp: number | null
  heart_rate: number | null; respiratory_rate: number | null
  temperature_f: number | null; weight_lbs: number | null
  oxygen_saturation: number | null; pain_scale: number | null
  blood_glucose: number | null
}

const props = defineProps<{
  participantId: number
  initialVitals: Vital[]
  completedTransfers: { effective_date: string; from_site_name: string | null; to_site_name: string | null }[]
}>()

const vitals = ref<Vital[]>(props.initialVitals)
watch(() => props.initialVitals, v => { vitals.value = v })

// Add vitals form
const showForm   = ref(false)
const submitting = ref(false)
const formError  = ref<string | null>(null)

const form = ref({
  systolic_bp: '', diastolic_bp: '', heart_rate: '',
  respiratory_rate: '', temperature_f: '', weight_lbs: '',
  oxygen_saturation: '', pain_scale: '', blood_glucose: '',
})

function resetForm() {
  Object.keys(form.value).forEach(k => { (form.value as Record<string, string>)[k] = '' })
  formError.value = null
}

function fmtDatetime(val: string): string {
  return new Date(val).toLocaleString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
  })
}

function bpClass(sys: number | null, dia: number | null): string {
  if (sys == null || dia == null) return ''
  if (sys >= 160 || dia >= 100 || sys < 90) return 'text-red-600 dark:text-red-400 font-semibold'
  if (sys >= 140 || dia >= 90) return 'text-amber-600 dark:text-amber-400'
  return ''
}

function o2Class(val: number | null): string {
  if (val == null) return ''
  return val < 92 ? 'text-red-600 dark:text-red-400 font-semibold' : ''
}

function hrClass(val: number | null): string {
  if (val == null) return ''
  return (val < 50 || val > 120) ? 'text-amber-600 dark:text-amber-400' : ''
}

function submitVitals() {
  submitting.value = true
  formError.value = null
  const payload = Object.fromEntries(
    Object.entries(form.value)
      .filter(([, v]) => v !== '')
      .map(([k, v]) => [k, Number(v)])
  )
  router.post(`/participants/${props.participantId}/vitals`, payload, {
    preserveScroll: true,
    onSuccess: () => { showForm.value = false; resetForm() },
    onError: (e: Record<string, string>) => {
      formError.value = Object.values(e)[0] ?? 'Validation error.'
    },
    onFinish: () => { submitting.value = false },
  })
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Vitals ({{ vitals.length }} records)</h3>
      <button
        class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-1.5"
        aria-label="Record new vitals"
        @click="showForm = !showForm"
      >
        <PlusIcon class="w-4 h-4" />
        Record Vitals
      </button>
    </div>

    <!-- Add vitals form -->
    <div v-if="showForm" class="mb-4 bg-gray-50 dark:bg-slate-700/50 border border-gray-200 dark:border-slate-600 rounded-lg p-4">
      <h4 class="text-xs font-semibold text-gray-700 dark:text-slate-300 mb-3">New Vitals Entry</h4>
      <p v-if="formError" class="text-sm text-red-600 dark:text-red-400 mb-2">{{ formError }}</p>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Systolic BP</label>
          <input v-model="form.systolic_bp" type="number" min="50" max="280" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" placeholder="mmHg" />
        </div>
        <div>
          <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Diastolic BP</label>
          <input v-model="form.diastolic_bp" type="number" min="30" max="180" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" placeholder="mmHg" />
        </div>
        <div>
          <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Heart Rate</label>
          <input v-model="form.heart_rate" type="number" min="20" max="300" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" placeholder="bpm" />
        </div>
        <div>
          <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Resp. Rate</label>
          <input v-model="form.respiratory_rate" type="number" min="4" max="60" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" placeholder="/min" />
        </div>
        <div>
          <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Temperature (F)</label>
          <input v-model="form.temperature_f" type="number" step="0.1" min="90" max="110" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" placeholder="°F" />
        </div>
        <div>
          <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Weight (lbs)</label>
          <input v-model="form.weight_lbs" type="number" step="0.1" min="50" max="700" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" placeholder="lbs" />
        </div>
        <div>
          <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">O2 Saturation</label>
          <input v-model="form.oxygen_saturation" type="number" min="50" max="100" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" placeholder="%" />
        </div>
        <div>
          <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Pain Scale (0-10)</label>
          <input v-model="form.pain_scale" type="number" min="0" max="10" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" placeholder="0-10" />
        </div>
        <div>
          <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Blood Glucose</label>
          <input v-model="form.blood_glucose" type="number" min="20" max="600" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" placeholder="mg/dL" />
        </div>
      </div>
      <div class="flex justify-end gap-2 mt-3">
        <button class="text-xs px-3 py-1.5 bg-gray-200 dark:bg-slate-600 text-gray-700 dark:text-slate-200 rounded-lg hover:bg-gray-300 transition-colors" @click="showForm = false; resetForm()">Cancel</button>
        <button
          :disabled="submitting"
          class="text-xs px-3 py-1.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg transition-colors"
          @click="submitVitals"
        >{{ submitting ? 'Saving...' : 'Save Vitals' }}</button>
      </div>
    </div>

    <p v-if="vitals.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">No vitals on file.</p>
    <div v-else class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
      <table class="text-sm w-full overflow-x-auto">
        <thead class="bg-gray-50 dark:bg-slate-700/50">
          <tr>
            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap">Date/Time</th>
            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">BP</th>
            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">HR</th>
            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">RR</th>
            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Temp</th>
            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Wt</th>
            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">O2%</th>
            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Pain</th>
            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Gluc.</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <tr v-for="v in vitals" :key="v.id" class="bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700/50">
            <td class="px-3 py-2 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">{{ fmtDatetime(v.recorded_at) }}</td>
            <td :class="`px-3 py-2 text-xs font-mono ${bpClass(v.systolic_bp, v.diastolic_bp)}`">
              {{ v.systolic_bp != null && v.diastolic_bp != null ? `${v.systolic_bp}/${v.diastolic_bp}` : '-' }}
            </td>
            <td :class="`px-3 py-2 text-xs font-mono ${hrClass(v.heart_rate)}`">{{ v.heart_rate ?? '-' }}</td>
            <td class="px-3 py-2 text-xs font-mono text-gray-700 dark:text-slate-300">{{ v.respiratory_rate ?? '-' }}</td>
            <td class="px-3 py-2 text-xs font-mono text-gray-700 dark:text-slate-300">{{ v.temperature_f != null ? `${v.temperature_f}°F` : '-' }}</td>
            <td class="px-3 py-2 text-xs font-mono text-gray-700 dark:text-slate-300">{{ v.weight_lbs != null ? `${v.weight_lbs} lb` : '-' }}</td>
            <td :class="`px-3 py-2 text-xs font-mono ${o2Class(v.oxygen_saturation)}`">{{ v.oxygen_saturation != null ? `${v.oxygen_saturation}%` : '-' }}</td>
            <td class="px-3 py-2 text-xs font-mono text-gray-700 dark:text-slate-300">{{ v.pain_scale ?? '-' }}</td>
            <td class="px-3 py-2 text-xs font-mono text-gray-700 dark:text-slate-300">{{ v.blood_glucose != null ? `${v.blood_glucose}` : '-' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
