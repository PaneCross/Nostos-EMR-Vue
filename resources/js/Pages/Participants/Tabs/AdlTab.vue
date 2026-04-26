<script setup lang="ts">
// ─── AdlTab.vue ────────────────────────────────────────────────────────────
// ADL — Activities of Daily Living (bathing, dressing, eating,
// toileting, transferring, continence — 6 core items, plus 4 PACE-
// specific extensions for 10 total). Each record is a point-in-time
// independence-level rating per category. Color-coded badges show
// current status across all 10 categories.
//
// Append-only audit trail. Write access: primary_care, day_center,
// home_care, nursing.
// ───────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface AdlRecord {
  id: number
  adl_category: string
  independence_level: string
  recorded_at: string
  notes: string | null
  recorded_by: { first_name: string; last_name: string } | null
}

interface AdlThreshold {
  alert_level: string
}

interface AdlSummary {
  latest: Record<string, AdlRecord>
  thresholds: Record<string, AdlThreshold>
}

interface Participant { id: number }

const props = defineProps<{ participant: Participant }>()

const ADL_CATEGORIES = [
  'bathing','dressing','grooming','toileting','transferring',
  'ambulation','eating','continence','medication_management','communication',
]

const ADL_CATEGORY_LABELS: Record<string, string> = {
  bathing: 'Bathing', dressing: 'Dressing', grooming: 'Grooming',
  toileting: 'Toileting', transferring: 'Transferring', ambulation: 'Ambulation',
  eating: 'Eating', continence: 'Continence',
  medication_management: 'Medication Mgmt', communication: 'Communication',
}

const ADL_LEVEL_LABELS: Record<string, string> = {
  independent:      'Independent',
  supervision:      'Supervision',
  limited_assist:   'Limited Assist',
  extensive_assist: 'Extensive Assist',
  total_dependent:  'Total Dependent',
}

const ADL_LEVEL_COLORS: Record<string, string> = {
  independent:      'text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800',
  supervision:      'text-lime-700 dark:text-lime-300 bg-lime-50 dark:bg-lime-950/60 border-lime-200 dark:border-lime-800',
  limited_assist:   'text-yellow-700 dark:text-yellow-300 bg-yellow-50 dark:bg-yellow-950/60 border-yellow-200 dark:border-yellow-800',
  extensive_assist: 'text-orange-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-950/60 border-orange-200 dark:border-orange-800',
  total_dependent:  'text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800',
}

// ── State ─────────────────────────────────────────────────────────────────────
const adlData   = ref<AdlSummary | null>(null)
const loading   = ref(true)
const loadError = ref<string | null>(null)
const showForm  = ref(false)
const saving    = ref(false)

const blankForm = () => ({ adl_category: 'bathing', independence_level: 'supervision', notes: '' })
const form = ref(blankForm())

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const r = await axios.get(`/participants/${props.participant.id}/adl`)
    adlData.value = r.data
  } catch {
    loadError.value = 'Failed to load ADL data.'
  } finally {
    loading.value = false
  }
})

// ── Record ADL ────────────────────────────────────────────────────────────────
async function handleRecord(e: Event) {
  e.preventDefault()
  saving.value = true
  try {
    await axios.post(`/participants/${props.participant.id}/adl`, {
      adl_category:       form.value.adl_category,
      independence_level: form.value.independence_level,
      notes:              form.value.notes || null,
    })
    const r = await axios.get(`/participants/${props.participant.id}/adl`)
    adlData.value  = r.data
    showForm.value = false
    form.value     = blankForm()
  } catch {
    // form stays open
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-6 p-6">

    <!-- Loading -->
    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">
      Loading ADL data...
    </div>
    <div v-else-if="loadError" class="py-8 text-center text-red-500 dark:text-red-400 text-sm">
      {{ loadError }}
    </div>

    <template v-else-if="adlData">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">ADL Tracking</h3>
        <button
          class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          @click="showForm ? (showForm = false, form = blankForm()) : (showForm = true)"
        >
          <PlusIcon v-if="!showForm" class="w-3 h-3" />
          {{ showForm ? 'Cancel' : 'Record ADL' }}
        </button>
      </div>

      <!-- Record ADL form -->
      <form
        v-if="showForm"
        class="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 grid grid-cols-3 gap-3"
        @submit.prevent="handleRecord"
      >
        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Category</label>
          <select name="adl_category"
            v-model="form.adl_category"
            class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
          >
            <option v-for="c in ADL_CATEGORIES" :key="c" :value="c">{{ ADL_CATEGORY_LABELS[c] }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Independence Level</label>
          <select name="independence_level"
            v-model="form.independence_level"
            class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
          >
            <option v-for="(label, val) in ADL_LEVEL_LABELS" :key="val" :value="val">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-medium text-gray-600 dark:text-slate-400">Notes (optional)</label>
          <input
            v-model="form.notes"
            type="text"
            class="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
          />
        </div>
        <div class="col-span-3 flex justify-end gap-2">
          <button
            type="button"
            class="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700"
            @click="showForm = false; form = blankForm()"
          >
            Cancel
          </button>
          <button
            type="submit"
            :disabled="saving"
            class="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            {{ saving ? 'Saving...' : 'Record ADL' }}
          </button>
        </div>
      </form>

      <!-- Current functional status grid -->
      <div>
        <h4 class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-3">
          Current Functional Status
        </h4>
        <div class="grid grid-cols-2 gap-2">
          <div
            v-for="cat in ADL_CATEGORIES"
            :key="cat"
            class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3 flex items-center justify-between"
          >
            <div>
              <div class="text-xs font-medium text-gray-700 dark:text-slate-300">{{ ADL_CATEGORY_LABELS[cat] }}</div>
              <div v-if="adlData.thresholds?.[cat]" class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                Alert &ge; {{ ADL_LEVEL_LABELS[adlData.thresholds[cat].alert_level] ?? adlData.thresholds[cat].alert_level }}
              </div>
            </div>
            <div class="text-right">
              <span
                v-if="adlData.latest?.[cat]"
                :class="['text-xs px-2 py-0.5 rounded-full border font-medium', ADL_LEVEL_COLORS[adlData.latest[cat].independence_level] ?? '']"
              >
                {{ ADL_LEVEL_LABELS[adlData.latest[cat].independence_level] ?? adlData.latest[cat].independence_level }}
              </span>
              <span v-else class="text-xs text-gray-300 dark:text-slate-600">Not recorded</span>
              <div v-if="adlData.latest?.[cat]?.recorded_at" class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                {{ new Date(adlData.latest[cat].recorded_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
