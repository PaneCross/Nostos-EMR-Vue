<script setup lang="ts">
// ─── EmarTab.vue ──────────────────────────────────────────────────────────────
// Electronic Medication Administration Record grid for a single date.
// Date picker defaults to today. Records loaded lazily on mount + on date change.
// Each row = one scheduled dose. "Chart" button opens an inline form row below.
// Controlled substances show "Witness req." badge. Status badge color-coded.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import axios from 'axios'

interface EmarRow {
  id: number
  scheduled_time: string | null
  administered_at: string | null
  status: string
  dose_given: string | null
  route_given: string | null
  reason_not_given: string | null
  notes: string | null
  medication: {
    id: number
    drug_name: string
    dose: number | null
    dose_unit: string | null
    route: string | null
    frequency: string | null
    is_controlled: boolean
    controlled_schedule: string | null
  } | null
  administered_by: { first_name: string; last_name: string } | null
  witness: { first_name: string; last_name: string } | null
}

interface Participant { id: number }

const props = defineProps<{ participant: Participant }>()

const EMAR_STATUS_COLORS: Record<string, string> = {
  scheduled:     'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
  given:         'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
  refused:       'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
  held:          'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
  not_available: 'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300',
  late:          'bg-red-200 dark:bg-red-900/80 text-red-800 dark:text-red-300',
  missed:        'bg-red-100 dark:bg-red-900/60 text-red-600 dark:text-red-400',
}

const today   = new Date().toISOString().slice(0, 10)
const date    = ref(today)
const records = ref<EmarRow[]>([])
const loading = ref(true)

// Inline charting state
const chartingId = ref<number | null>(null)
const chartForm  = ref<Record<string, string>>({})

function formatTime(iso: string | null): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

async function loadEmar(d: string) {
  loading.value = true
  try {
    const r = await axios.get(`/participants/${props.participant.id}/emar`, { params: { date: d } })
    records.value = r.data
  } catch {
    records.value = []
  } finally {
    loading.value = false
  }
}

onMounted(() => loadEmar(today))

function handleDateChange(d: string) {
  date.value    = d
  chartingId.value = null
  loadEmar(d)
}

function startCharting(record: EmarRow) {
  chartingId.value = record.id
  chartForm.value  = {
    status:          'given',
    administered_at: new Date().toISOString().slice(0, 16),
  }
}

async function submitCharting(e: Event) {
  e.preventDefault()
  if (!chartingId.value) return
  try {
    await axios.post(
      `/participants/${props.participant.id}/emar/${chartingId.value}/administer`,
      chartForm.value,
    )
    await loadEmar(date.value)
    chartingId.value = null
  } catch {
    // noop
  }
}
</script>

<template>
  <div class="space-y-4 p-6">
    <!-- Date selector -->
    <div class="flex items-center gap-3">
      <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">eMAR</h3>
      <input
        :value="date"
        type="date"
        :max="today"
        class="text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-800 dark:text-slate-100"
        @change="handleDateChange(($event.target as HTMLInputElement).value)"
      />
      <button
        class="text-xs text-blue-600 dark:text-blue-400 hover:underline"
        @click="handleDateChange(today)"
      >
        Today
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="py-8 text-center text-gray-400 dark:text-slate-500 text-sm">
      Loading eMAR...
    </div>

    <!-- Empty state -->
    <div v-else-if="records.length === 0" class="py-8 text-center text-gray-400 dark:text-slate-500 text-sm">
      No eMAR records for {{ date }}.
      <p class="text-xs mt-1">Records are generated nightly for scheduled medications.</p>
    </div>

    <!-- eMAR table -->
    <div v-else class="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 dark:bg-slate-700/50 text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide">
          <tr>
            <th class="px-4 py-2 text-left">Time</th>
            <th class="px-4 py-2 text-left">Medication</th>
            <th class="px-4 py-2 text-left">Ordered Dose</th>
            <th class="px-4 py-2 text-left">Status</th>
            <th class="px-4 py-2 text-left">Administered By</th>
            <th class="px-4 py-2"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <template v-for="record in records" :key="record.id">
            <!-- Main row -->
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/50">
              <td class="px-4 py-2.5 text-gray-700 dark:text-slate-300 font-mono text-xs whitespace-nowrap">
                {{ formatTime(record.scheduled_time) }}
              </td>
              <td class="px-4 py-2.5">
                <span class="font-medium text-gray-900 dark:text-slate-100">
                  {{ record.medication?.drug_name ?? '-' }}
                </span>
                <span
                  v-if="record.medication?.is_controlled"
                  class="ml-2 text-xs px-1.5 py-0.5 bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 rounded"
                >
                  C-{{ record.medication.controlled_schedule }} · Witness req.
                </span>
              </td>
              <td class="px-4 py-2.5 text-gray-600 dark:text-slate-400 text-xs">
                <template v-if="record.medication?.dose">
                  {{ record.medication.dose }} {{ record.medication.dose_unit }}
                </template>
                <template v-else>-</template>
                <template v-if="record.medication?.route"> ({{ record.medication.route }})</template>
              </td>
              <td class="px-4 py-2.5">
                <span :class="['inline-block text-xs px-2 py-0.5 rounded-full font-medium', EMAR_STATUS_COLORS[record.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400']">
                  {{ record.status }}
                </span>
              </td>
              <td class="px-4 py-2.5 text-gray-500 dark:text-slate-400 text-xs">
                <template v-if="record.administered_by">
                  {{ record.administered_by.first_name }} {{ record.administered_by.last_name }}
                </template>
                <template v-else>-</template>
                <span v-if="record.witness" class="block text-gray-400 dark:text-slate-500">
                  Witness: {{ record.witness.first_name }} {{ record.witness.last_name }}
                </span>
              </td>
              <td class="px-4 py-2.5 text-right">
                <button
                  v-if="record.status === 'scheduled' || record.status === 'late'"
                  class="text-xs px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700"
                  @click="startCharting(record)"
                >
                  Chart
                </button>
              </td>
            </tr>

            <!-- Inline charting row -->
            <tr v-if="chartingId === record.id">
              <td colspan="6" class="px-4 py-3 bg-blue-50 dark:bg-blue-950/60 border-t border-blue-200 dark:border-blue-800">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitCharting">
                  <div>
                    <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Status</label>
                    <select name="status"
                      v-model="chartForm.status"
                      class="block mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-800 dark:text-slate-100"
                    >
                      <option v-for="s in ['given','refused','held','not_available','missed']" :key="s" :value="s">{{ s }}</option>
                    </select>
                  </div>
                  <div v-if="chartForm.status === 'given'">
                    <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Given At</label>
                    <input
                      v-model="chartForm.administered_at"
                      type="datetime-local"
                      class="block mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-800 dark:text-slate-100"
                    />
                  </div>
                  <div v-if="['refused','held','not_available','missed'].includes(chartForm.status)">
                    <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Reason *</label>
                    <input
                      v-model="chartForm.reason_not_given"
                      type="text"
                      required
                      placeholder="Reason..."
                      class="block mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1 w-48 bg-white dark:bg-slate-800 dark:text-slate-100"
                    />
                  </div>
                  <div class="flex gap-2">
                    <button
                      type="button"
                      class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700"
                      @click="chartingId = null"
                    >
                      Cancel
                    </button>
                    <button
                      type="submit"
                      class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded hover:bg-blue-700"
                    >
                      Save
                    </button>
                  </div>
                </form>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>
