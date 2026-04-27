<script setup lang="ts">
// ─── LabResultsTab.vue ────────────────────────────────────────────────────────
// Lab results list. Paginated from /lab-results index (summary only).
// Expand a row to lazy-load components via show endpoint.
// Critical/abnormal results highlighted. Mark reviewed action.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ChevronDownIcon, ExclamationTriangleIcon, BeakerIcon } from '@heroicons/vue/24/outline'

interface LabResultComponent {
  id: number; component_name: string; value_numeric: number | null
  value_text: string | null; unit: string | null; reference_range: string | null
  abnormal_flag: string | null
}

interface LabResult {
  id: number; test_name: string; test_code: string | null
  collected_at: string | null; resulted_at: string | null
  ordering_provider_name: string | null; performing_facility: string | null
  overall_status: string; status_label: string
  abnormal_flag: string | null; is_reviewed: boolean
  reviewed_at: string | null; reviewed_by: string | null
  notes: string | null; component_count: number
}

interface Participant { id: number }

const props = defineProps<{ participant: Participant }>()

const results = ref<LabResult[]>([])
const loading = ref(true)
const loadError = ref('')
const filterMode = ref<'all' | 'unreviewed' | 'abnormal'>('all')
const expandedId = ref<number | null>(null)
const componentCache = ref<Record<number, LabResultComponent[]>>({})
const loadingComponents = ref<number | null>(null)
const reviewingId = ref<number | null>(null)

onMounted(async () => {
  try {
    const r = await axios.get(`/participants/${props.participant.id}/lab-results`)
    results.value = r.data.data ?? []
  } catch {
    loadError.value = 'Failed to load lab results.'
  } finally {
    loading.value = false
  }
})

const filtered = () => {
  if (filterMode.value === 'unreviewed') return results.value.filter(r => !r.is_reviewed)
  if (filterMode.value === 'abnormal')   return results.value.filter(r => !!r.abnormal_flag)
  return results.value
}

async function toggleExpand(result: LabResult) {
  if (expandedId.value === result.id) {
    expandedId.value = null
    return
  }
  expandedId.value = result.id
  if (!componentCache.value[result.id] && result.component_count > 0) {
    loadingComponents.value = result.id
    try {
      const r = await axios.get(`/participants/${props.participant.id}/lab-results/${result.id}`)
      componentCache.value[result.id] = r.data.components ?? []
    } catch {
      componentCache.value[result.id] = []
    } finally {
      loadingComponents.value = null
    }
  }
}

async function markReviewed(result: LabResult) {
  reviewingId.value = result.id
  try {
    await axios.post(`/participants/${props.participant.id}/lab-results/${result.id}/review`)
    const idx = results.value.findIndex(r => r.id === result.id)
    if (idx !== -1) {
      results.value[idx].is_reviewed = true
      results.value[idx].reviewed_at = new Date().toISOString()
    }
  } catch {
    // ignore: already reviewed returns 409 which is fine
  } finally {
    reviewingId.value = null
  }
}

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function flagColor(flag: string | null): string {
  if (!flag) return ''
  if (flag === 'H' || flag === 'HH' || flag === 'C') return 'text-red-600 dark:text-red-400 font-semibold'
  if (flag === 'L' || flag === 'LL')                  return 'text-blue-600 dark:text-blue-400 font-semibold'
  return 'text-amber-600 dark:text-amber-400'
}

function isCritical(r: LabResult): boolean {
  return r.abnormal_flag === 'C' || r.abnormal_flag === 'HH' || r.abnormal_flag === 'LL'
}
</script>

<template>
  <div class="p-6">
    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">Loading lab results...</div>
    <div v-else-if="loadError" class="py-8 text-center text-red-500 dark:text-red-400 text-sm">{{ loadError }}</div>

    <template v-else>
      <!-- Header -->
      <div class="flex items-center justify-between mb-4 gap-2">
        <div class="flex items-center gap-2">
          <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Lab Results</h2>
          <span class="text-xs text-gray-400 dark:text-slate-500">({{ results.length }})</span>
        </div>
        <div class="flex items-center gap-1.5">
          <button
            v-for="opt in [{ key: 'all', label: 'All' }, { key: 'unreviewed', label: 'Unreviewed' }, { key: 'abnormal', label: 'Abnormal' }]"
            :key="opt.key"
            :class="['text-xs px-2.5 py-1 rounded-md border transition-colors', filterMode === opt.key
              ? 'bg-blue-600 border-blue-600 text-white'
              : 'border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700']"
            @click="filterMode = opt.key as 'all' | 'unreviewed' | 'abnormal'"
          >{{ opt.label }}</button>
        </div>
      </div>

      <!-- Empty -->
      <div v-if="filtered().length === 0" class="py-12 text-center">
        <BeakerIcon class="w-8 h-8 mx-auto text-gray-300 dark:text-slate-600 mb-2" />
        <p class="text-sm text-gray-400 dark:text-slate-500">No lab results found.</p>
      </div>

      <!-- Result list -->
      <div v-else class="space-y-2">
        <div
          v-for="result in filtered()"
          :key="result.id"
          :class="['rounded-lg overflow-hidden border', isCritical(result)
            ? 'border-red-400 dark:border-red-600 bg-red-50/50 dark:bg-red-950/10'
            : result.abnormal_flag
              ? 'border-amber-300 dark:border-amber-700 bg-amber-50/40 dark:bg-amber-950/10'
              : 'border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800']"
        >
          <!-- Row header (click to expand) -->
          <button
            class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
            @click="toggleExpand(result)"
          >
            <ExclamationTriangleIcon v-if="isCritical(result)" class="w-4 h-4 text-red-500 shrink-0" />
            <div class="flex-1 min-w-0">
              <span class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ result.test_name }}</span>
              <span v-if="result.test_code" class="ml-2 font-mono text-xs text-gray-400 dark:text-slate-500">{{ result.test_code }}</span>
            </div>
            <span v-if="result.abnormal_flag" :class="['text-xs font-bold px-1.5 py-0.5 rounded', flagColor(result.abnormal_flag)]">
              {{ result.abnormal_flag }}
            </span>
            <span v-if="!result.is_reviewed" class="text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded">
              Unreviewed
            </span>
            <span class="text-xs text-gray-400 dark:text-slate-500 whitespace-nowrap shrink-0">{{ fmtDate(result.collected_at) }}</span>
            <span v-if="result.performing_facility" class="text-xs text-gray-400 dark:text-slate-500 shrink-0 hidden sm:inline">{{ result.performing_facility }}</span>
            <ChevronDownIcon :class="['w-4 h-4 text-gray-400 dark:text-slate-500 transition-transform shrink-0', expandedId === result.id ? 'rotate-180' : '']" />
          </button>

          <!-- Expanded detail -->
          <div v-if="expandedId === result.id" class="border-t border-gray-100 dark:border-slate-700 px-4 py-3 bg-gray-50/50 dark:bg-slate-900/30">
            <!-- Meta row -->
            <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-500 dark:text-slate-400 mb-3">
              <span v-if="result.ordering_provider_name">Provider: {{ result.ordering_provider_name }}</span>
              <span v-if="result.resulted_at">Resulted: {{ fmtDate(result.resulted_at) }}</span>
              <span>Status: {{ result.status_label || result.overall_status }}</span>
              <span v-if="result.notes">Notes: {{ result.notes }}</span>
            </div>

            <!-- Components loading -->
            <div v-if="loadingComponents === result.id" class="text-xs text-gray-400 dark:text-slate-500 py-2">
              Loading components...
            </div>

            <!-- Components table -->
            <div v-else-if="(componentCache[result.id] ?? []).length > 0" class="mb-3 overflow-x-auto">
              <table class="w-full text-xs border-collapse">
                <thead>
                  <tr class="border-b border-gray-200 dark:border-slate-600">
                    <th class="text-left py-1.5 pr-4 font-semibold text-gray-500 dark:text-slate-400">Component</th>
                    <th class="text-right py-1.5 pr-4 font-semibold text-gray-500 dark:text-slate-400">Value</th>
                    <th class="text-left py-1.5 pr-4 font-semibold text-gray-500 dark:text-slate-400">Reference</th>
                    <th class="text-center py-1.5 font-semibold text-gray-500 dark:text-slate-400">Flag</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="comp in componentCache[result.id]" :key="comp.id" class="border-b border-gray-100 dark:border-slate-700/50 last:border-0">
                    <td class="py-1.5 pr-4 text-gray-700 dark:text-slate-300">{{ comp.component_name }}</td>
                    <td :class="['py-1.5 pr-4 text-right', flagColor(comp.abnormal_flag)]">
                      {{ comp.value_numeric ?? comp.value_text ?? '-' }}
                      <span v-if="comp.unit" class="text-gray-400 ml-0.5">{{ comp.unit }}</span>
                    </td>
                    <td class="py-1.5 pr-4 text-gray-400 dark:text-slate-500">{{ comp.reference_range ?? '-' }}</td>
                    <td :class="['py-1.5 text-center', flagColor(comp.abnormal_flag)]">{{ comp.abnormal_flag ?? '' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else-if="result.component_count === 0" class="text-xs text-gray-400 dark:text-slate-500 mb-3">No individual components recorded.</div>

            <!-- Review action -->
            <div class="flex items-center gap-3">
              <button
                v-if="!result.is_reviewed"
                :disabled="reviewingId === result.id"
                class="text-xs px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 transition-colors"
                @click="markReviewed(result)"
              >
                {{ reviewingId === result.id ? 'Saving...' : 'Mark Reviewed' }}
              </button>
              <span v-if="result.is_reviewed" class="text-xs text-green-600 dark:text-green-400">
                Reviewed {{ fmtDate(result.reviewed_at) }}<span v-if="result.reviewed_by"> by {{ result.reviewed_by }}</span>
              </span>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
