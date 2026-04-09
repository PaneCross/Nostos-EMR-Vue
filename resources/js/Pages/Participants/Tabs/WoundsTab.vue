<script setup lang="ts">
// ─── WoundsTab.vue ────────────────────────────────────────────────────────────
// Wound care management. Open wounds listed with stage severity color coding.
// Add assessment per wound. Close wound action. New wound form.
// Healed wounds shown collapsed at bottom.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface WoundRecord {
  id: number
  location: string
  wound_type: string
  wound_type_label: string
  pressure_injury_stage: string | null
  stage_label: string | null
  status: string
  first_identified_date: string | null
  healed_date: string | null
  notes: string | null
  documented_by: string | null
  assessment_count: number
  last_assessment_at: string | null
}

interface Participant { id: number }

const props = defineProps<{ participant: Participant }>()

const STAGE_COLORS: Record<string, string> = {
  stage_1:    'border-yellow-300 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-950/20',
  stage_2:    'border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/20',
  stage_3:    'border-orange-400 dark:border-orange-600 bg-orange-50 dark:bg-orange-950/20',
  stage_4:    'border-red-400 dark:border-red-600 bg-red-50 dark:bg-red-950/20',
  unstageable:'border-purple-400 dark:border-purple-600 bg-purple-50 dark:bg-purple-950/20',
  deep_tissue:'border-red-600 dark:border-red-800 bg-red-100 dark:bg-red-950/40',
}

const wounds = ref<WoundRecord[]>([])
const loading = ref(true)
const loadError = ref('')
const showAddWound = ref(false)
const savingWound = ref(false)
const woundError = ref('')
const addAssessmentForId = ref<number | null>(null)
const savingAssessment = ref(false)
const assessmentError = ref('')

const woundForm = ref({
  location: '', wound_type: 'pressure_injury', pressure_injury_stage: 'stage_2',
  first_identified_date: new Date().toISOString().slice(0, 10), notes: '',
})

const assessmentForm = ref({
  assessed_at: new Date().toISOString().slice(0, 10),
  length_cm: '', width_cm: '', depth_cm: '',
  status_change: '', notes: '',
})

const openWounds = computed(() => wounds.value.filter(w => w.status === 'open'))
const healedWounds = computed(() => wounds.value.filter(w => w.status === 'healed'))

onMounted(async () => {
  try {
    const r = await axios.get(`/participants/${props.participant.id}/wounds`)
    wounds.value = [...(r.data.open ?? []), ...(r.data.healed ?? [])]
  } catch {
    loadError.value = 'Failed to load wound records.'
  } finally {
    loading.value = false
  }
})

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function submitWound() {
  if (!woundForm.value.location.trim()) { woundError.value = 'Location is required.'; return }
  savingWound.value = true; woundError.value = ''
  try {
    const res = await axios.post(`/participants/${props.participant.id}/wounds`, {
      location:               woundForm.value.location,
      wound_type:             woundForm.value.wound_type,
      pressure_injury_stage:  woundForm.value.pressure_injury_stage,
      first_identified_date:  woundForm.value.first_identified_date,
      notes:                  woundForm.value.notes || null,
    })
    wounds.value.unshift(res.data)
    showAddWound.value = false
    woundForm.value = { location: '', wound_type: 'pressure_injury', pressure_injury_stage: 'stage_2', first_identified_date: new Date().toISOString().slice(0, 10), notes: '' }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    woundError.value = e.response?.data?.message ?? 'Failed to save wound.'
    savingWound.value = false
  }
}

async function submitAssessment(wound: WoundRecord) {
  savingAssessment.value = true; assessmentError.value = ''
  try {
    const payload = {
      assessed_at:   assessmentForm.value.assessed_at,
      length_cm:     assessmentForm.value.length_cm !== '' ? parseFloat(assessmentForm.value.length_cm) : null,
      width_cm:      assessmentForm.value.width_cm !== '' ? parseFloat(assessmentForm.value.width_cm) : null,
      depth_cm:      assessmentForm.value.depth_cm !== '' ? parseFloat(assessmentForm.value.depth_cm) : null,
      status_change: assessmentForm.value.status_change || null,
      notes:         assessmentForm.value.notes || null,
    }
    const res = await axios.post(`/participants/${props.participant.id}/wounds/${wound.id}/assess`, payload)
    // Update the wound record from the response
    const idx = wounds.value.findIndex(w => w.id === wound.id)
    if (idx !== -1) {
      wounds.value[idx] = { ...wounds.value[idx], ...res.data.wound, assessment_count: (wounds.value[idx].assessment_count ?? 0) + 1, last_assessment_at: new Date().toISOString() }
    }
    addAssessmentForId.value = null
    assessmentForm.value = { assessed_at: new Date().toISOString().slice(0, 10), length_cm: '', width_cm: '', depth_cm: '', status_change: '', notes: '' }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    assessmentError.value = e.response?.data?.message ?? 'Failed to save assessment.'
    savingAssessment.value = false
  }
}

async function closeWound(wound: WoundRecord) {
  if (!confirm(`Mark wound at "${wound.location}" as healed/closed?`)) return
  try {
    const res = await axios.post(`/participants/${props.participant.id}/wounds/${wound.id}/close`)
    const idx = wounds.value.findIndex(w => w.id === wound.id)
    if (idx !== -1) wounds.value[idx] = { ...wounds.value[idx], ...res.data }
  } catch {
    alert('Failed to close wound.')
  }
}
</script>

<template>
  <div class="p-6">
    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">Loading wound records...</div>
    <div v-else-if="loadError" class="py-8 text-center text-red-500 dark:text-red-400 text-sm">{{ loadError }}</div>

    <template v-else>
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Wound Care</h2>
        <button
          class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          @click="showAddWound = !showAddWound"
        >
          <PlusIcon class="w-3 h-3" />
          New Wound
        </button>
      </div>

      <!-- New wound form -->
      <div v-if="showAddWound" class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">New Wound</h3>
        <div class="grid grid-cols-2 gap-3 mb-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Location *</label>
            <input v-model="woundForm.location" type="text" placeholder="e.g. Left sacrum" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700 dark:text-slate-200" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Type</label>
            <select v-model="woundForm.wound_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700 dark:text-slate-200">
              <option value="pressure_injury">Pressure Injury</option>
              <option value="venous_ulcer">Venous Ulcer</option>
              <option value="arterial_ulcer">Arterial Ulcer</option>
              <option value="diabetic_ulcer">Diabetic Ulcer</option>
              <option value="surgical">Surgical</option>
              <option value="traumatic">Traumatic</option>
              <option value="skin_tear">Skin Tear</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Stage</label>
            <select v-model="woundForm.pressure_injury_stage" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700 dark:text-slate-200">
              <option value="stage_1">Stage 1</option>
              <option value="stage_2">Stage 2</option>
              <option value="stage_3">Stage 3</option>
              <option value="stage_4">Stage 4</option>
              <option value="unstageable">Unstageable</option>
              <option value="deep_tissue">Deep Tissue</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Onset Date</label>
            <input v-model="woundForm.first_identified_date" type="date" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700 dark:text-slate-200" />
          </div>
        </div>
        <p v-if="woundError" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ woundError }}</p>
        <div class="flex gap-2">
          <button :disabled="savingWound" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="submitWound">
            {{ savingWound ? 'Saving...' : 'Save' }}
          </button>
          <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors" @click="showAddWound = false">Cancel</button>
        </div>
      </div>

      <!-- Open wounds -->
      <div v-if="openWounds.length === 0 && !showAddWound" class="py-8 text-center text-gray-400 dark:text-slate-500 text-sm">No open wounds.</div>
      <div class="space-y-3 mb-6">
        <div
          v-for="wound in openWounds"
          :key="wound.id"
          :class="['border rounded-lg overflow-hidden', STAGE_COLORS[wound.pressure_injury_stage ?? ''] ?? 'border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800']"
        >
          <div class="flex items-start gap-3 px-4 py-3">
            <div class="flex-1">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ wound.location }}</span>
                <span class="text-xs bg-white/80 dark:bg-slate-900/60 text-gray-600 dark:text-slate-300 px-1.5 py-0.5 rounded capitalize">{{ wound.wound_type_label || wound.wound_type.replace(/_/g, ' ') }}</span>
                <span v-if="wound.stage_label" class="text-xs font-medium text-gray-700 dark:text-slate-300">{{ wound.stage_label }}</span>
              </div>
              <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 flex gap-3 flex-wrap">
                <span>Onset: {{ fmtDate(wound.first_identified_date) }}</span>
                <span>{{ wound.assessment_count }} assessment{{ wound.assessment_count !== 1 ? 's' : '' }}</span>
                <span v-if="wound.last_assessment_at">Last: {{ fmtDate(wound.last_assessment_at) }}</span>
              </div>
              <p v-if="wound.notes" class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">{{ wound.notes }}</p>
            </div>
            <div class="flex gap-2 shrink-0">
              <button
                class="text-xs px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors"
                @click="addAssessmentForId = addAssessmentForId === wound.id ? null : wound.id"
              >Assess</button>
              <button
                class="text-xs px-2 py-1 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 rounded hover:bg-green-50 dark:hover:bg-green-950/30 transition-colors"
                @click="closeWound(wound)"
              >Close</button>
            </div>
          </div>

          <!-- Assessment form -->
          <div v-if="addAssessmentForId === wound.id" class="border-t border-gray-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/30 px-4 py-3">
            <h4 class="text-xs font-semibold text-gray-700 dark:text-slate-300 mb-2">New Assessment</h4>
            <div class="grid grid-cols-3 gap-2 mb-2">
              <div>
                <label class="block text-xs text-gray-600 dark:text-slate-400 mb-0.5">Length (cm)</label>
                <input v-model="assessmentForm.length_cm" type="number" step="0.1" class="w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-1.5 py-1 bg-white dark:bg-slate-700 dark:text-slate-200" />
              </div>
              <div>
                <label class="block text-xs text-gray-600 dark:text-slate-400 mb-0.5">Width (cm)</label>
                <input v-model="assessmentForm.width_cm" type="number" step="0.1" class="w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-1.5 py-1 bg-white dark:bg-slate-700 dark:text-slate-200" />
              </div>
              <div>
                <label class="block text-xs text-gray-600 dark:text-slate-400 mb-0.5">Depth (cm)</label>
                <input v-model="assessmentForm.depth_cm" type="number" step="0.1" class="w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-1.5 py-1 bg-white dark:bg-slate-700 dark:text-slate-200" />
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2 mb-2">
              <div>
                <label class="block text-xs text-gray-600 dark:text-slate-400 mb-0.5">Status Change</label>
                <select v-model="assessmentForm.status_change" class="w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-1.5 py-1 bg-white dark:bg-slate-700 dark:text-slate-200">
                  <option value="">No change noted</option>
                  <option value="improved">Improved</option>
                  <option value="unchanged">Unchanged</option>
                  <option value="deteriorated">Deteriorated</option>
                  <option value="healed">Healed</option>
                </select>
              </div>
              <div>
                <label class="block text-xs text-gray-600 dark:text-slate-400 mb-0.5">Notes</label>
                <input v-model="assessmentForm.notes" type="text" class="w-full text-xs border border-gray-300 dark:border-slate-600 rounded px-1.5 py-1 bg-white dark:bg-slate-700 dark:text-slate-200" />
              </div>
            </div>
            <p v-if="assessmentError" class="text-red-600 dark:text-red-400 text-xs mb-1">{{ assessmentError }}</p>
            <div class="flex gap-2">
              <button :disabled="savingAssessment" class="text-xs px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="submitAssessment(wound)">
                {{ savingAssessment ? 'Saving...' : 'Save Assessment' }}
              </button>
              <button class="text-xs px-2 py-1 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 rounded transition-colors" @click="addAssessmentForId = null">Cancel</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Healed wounds -->
      <div v-if="healedWounds.length > 0">
        <h3 class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-2">Healed / Closed</h3>
        <div class="space-y-1">
          <div v-for="wound in healedWounds" :key="wound.id" class="flex items-center gap-3 text-xs text-gray-500 dark:text-slate-400 px-4 py-2 bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700 rounded-lg">
            <span class="text-gray-600 dark:text-slate-300">{{ wound.location }}</span>
            <span class="capitalize text-gray-400 dark:text-slate-500">{{ wound.wound_type.replace(/_/g, ' ') }}</span>
            <span class="ml-auto">Closed: {{ fmtDate(wound.healed_date) }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
