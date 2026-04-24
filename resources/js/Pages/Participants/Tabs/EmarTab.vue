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

// Phase I2 — BCMA scan modal state
const bcmaOpen = ref(false)
const bcmaRecord = ref<EmarRow | null>(null)
const bcmaParticipantBarcode = ref('')
const bcmaMedBarcode = ref('')
const bcmaOverrideReason = ref('')
const bcmaResult = ref<{ status: string; expected?: any; scanned?: any } | null>(null)
const bcmaSubmitting = ref(false)

function openBcmaModal(record: EmarRow) {
  bcmaRecord.value = record
  bcmaParticipantBarcode.value = ''
  bcmaMedBarcode.value = ''
  bcmaOverrideReason.value = ''
  bcmaResult.value = null
  bcmaOpen.value = true
  // Focus is handled via template ref-based autofocus below
}
function closeBcmaModal() {
  bcmaOpen.value = false
  bcmaRecord.value = null
  bcmaResult.value = null
}
async function submitBcmaScan() {
  if (! bcmaRecord.value) return
  bcmaSubmitting.value = true
  try {
    const payload: Record<string, string> = {
      participant_barcode: bcmaParticipantBarcode.value,
      medication_barcode: bcmaMedBarcode.value,
    }
    if (bcmaOverrideReason.value.trim().length >= 10) {
      payload.override_reason = bcmaOverrideReason.value.trim()
    }
    const res = await axios.post(`/emar/${bcmaRecord.value.id}/scan-verify`, payload, {
      validateStatus: s => s < 500,
    })
    bcmaResult.value = res.data
  } catch (e: any) {
    bcmaResult.value = { status: 'error' }
  } finally {
    bcmaSubmitting.value = false
  }
}

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
                <div class="inline-flex gap-1">
                  <button
                    v-if="record.status === 'scheduled' || record.status === 'late'"
                    class="text-xs px-2 py-1 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 rounded hover:bg-slate-50 dark:hover:bg-slate-700"
                    @click="openBcmaModal(record)"
                    data-testid="bcma-scan-btn"
                  >
                    Scan
                  </button>
                  <button
                    v-if="record.status === 'scheduled' || record.status === 'late'"
                    class="text-xs px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700"
                    @click="startCharting(record)"
                  >
                    Chart
                  </button>
                </div>
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

    <!-- Phase I2 — BCMA scan modal -->
    <Teleport to="body">
      <div v-if="bcmaOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="closeBcmaModal">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
          <div class="flex items-start justify-between mb-4">
            <div>
              <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">BCMA — Scan to administer</h2>
              <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                {{ bcmaRecord?.medication?.drug_name }} · {{ formatTime(bcmaRecord?.scheduled_time ?? null) }}
              </p>
            </div>
            <button class="text-gray-400 hover:text-gray-600" @click="closeBcmaModal" aria-label="Close">
              ✕
            </button>
          </div>

          <form @submit.prevent="submitBcmaScan" class="space-y-3">
            <div>
              <label class="text-xs font-medium text-gray-600 dark:text-slate-400 block">Participant barcode (wristband)</label>
              <input v-model="bcmaParticipantBarcode" type="text" autofocus
                class="mt-1 w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-900 dark:text-slate-100 font-mono"
                placeholder="Scan or type wristband value…"
                data-testid="bcma-participant-input"
              />
            </div>
            <div>
              <label class="text-xs font-medium text-gray-600 dark:text-slate-400 block">Medication barcode</label>
              <input v-model="bcmaMedBarcode" type="text"
                class="mt-1 w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-900 dark:text-slate-100 font-mono"
                placeholder="Scan or type label value…"
                data-testid="bcma-med-input"
              />
            </div>

            <!-- Result banner -->
            <div v-if="bcmaResult" class="rounded-lg p-3 text-sm"
              :class="{
                'bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300': bcmaResult.status === 'ok',
                'bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300': bcmaResult.status === 'override',
                'bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300': ['mismatch','missing_scan','not_scannable','error'].includes(bcmaResult.status),
              }"
            >
              <p class="font-semibold text-xs uppercase">{{ bcmaResult.status }}</p>
              <p v-if="bcmaResult.status === 'ok'" class="mt-1">Scan verified. Proceed to Chart to record administration.</p>
              <p v-else-if="bcmaResult.status === 'missing_scan'" class="mt-1">Both barcodes are required.</p>
              <p v-else-if="bcmaResult.status === 'mismatch'" class="mt-1">Scanned barcodes don't match the eMAR record. If you're certain of participant + drug identity, enter an override reason below and resubmit.</p>
              <p v-else-if="bcmaResult.status === 'not_scannable'" class="mt-1">Participant has no barcode on file. Generate a wristband or run the backfill command.</p>
              <p v-else-if="bcmaResult.status === 'override'" class="mt-1">Override accepted and logged. Proceed to Chart.</p>
            </div>

            <!-- Override reason (only shown after mismatch) -->
            <div v-if="bcmaResult?.status === 'mismatch'">
              <label class="text-xs font-medium text-gray-600 dark:text-slate-400 block">Override reason (≥10 chars)</label>
              <textarea v-model="bcmaOverrideReason" rows="2"
                class="mt-1 w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-900 dark:text-slate-100"
                placeholder="Why are you overriding the scan mismatch?"
              />
              <p class="text-xs text-gray-500 dark:text-slate-400 mt-1">Override is logged as a high-priority audit event and alerts QA in real time.</p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
              <button type="button" class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700" @click="closeBcmaModal">Cancel</button>
              <button type="submit" :disabled="bcmaSubmitting" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50">
                {{ bcmaSubmitting ? 'Verifying…' : 'Verify scan' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </div>
</template>
