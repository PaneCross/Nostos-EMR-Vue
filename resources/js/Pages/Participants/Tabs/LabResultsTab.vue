<script setup lang="ts">
// ─── LabResultsTab.vue ────────────────────────────────────────────────────────
// Lab results list with critical flag highlighting (red border). Expandable
// result cards show individual components with reference ranges. Review action
// for unreviewed results. Filter by reviewed/unreviewed status.
// ─────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import axios from 'axios'
import { ChevronDownIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface LabResultComponent {
  id: number; component_name: string; value: string | null
  unit: string | null; reference_range: string | null; flag: string | null
}

interface LabResult {
  id: number; panel_name: string; ordered_date: string | null
  resulted_date: string | null; ordering_provider: string | null
  lab_name: string | null; status: string; is_critical: boolean
  reviewed_at: string | null
  reviewed_by: { id: number; first_name: string; last_name: string } | null
  components: LabResultComponent[]
}

interface Participant { id: number }

const props = defineProps<{
  participant: Participant
  labResults: LabResult[]
}>()

const results = ref<LabResult[]>(props.labResults)
const expandedIds = ref<Set<number>>(new Set())
const reviewingId = ref<number | null>(null)
const filterReviewed = ref('')

function toggleExpand(id: number) {
  if (expandedIds.value.has(id)) expandedIds.value.delete(id)
  else expandedIds.value.add(id)
}

function filteredResults() {
  if (filterReviewed.value === 'unreviewed') return results.value.filter(r => !r.reviewed_at)
  if (filterReviewed.value === 'reviewed') return results.value.filter(r => !!r.reviewed_at)
  return results.value
}

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function flagColor(flag: string | null): string {
  if (!flag) return ''
  if (flag === 'H' || flag === 'HH') return 'text-red-600 dark:text-red-400 font-semibold'
  if (flag === 'L' || flag === 'LL') return 'text-blue-600 dark:text-blue-400 font-semibold'
  return 'text-amber-600 dark:text-amber-400'
}

async function reviewResult(result: LabResult) {
  reviewingId.value = result.id
  try {
    await axios.post(`/participants/${props.participant.id}/lab-results/${result.id}/review`)
    const idx = results.value.findIndex(r => r.id === result.id)
    if (idx !== -1) results.value[idx].reviewed_at = new Date().toISOString()
  } catch {
    alert('Failed to mark as reviewed.')
  } finally {
    reviewingId.value = null
  }
}
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-4 gap-2">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Lab Results</h2>
      <select v-model="filterReviewed" class="text-xs border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
        <option value="">All results</option>
        <option value="unreviewed">Unreviewed</option>
        <option value="reviewed">Reviewed</option>
      </select>
    </div>

    <div v-if="filteredResults().length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No lab results found.</div>
    <div v-else class="space-y-2">
      <div
        v-for="result in filteredResults()"
        :key="result.id"
        :class="['bg-white dark:bg-slate-800 rounded-lg overflow-hidden', result.is_critical ? 'border-2 border-red-400 dark:border-red-600' : 'border border-gray-200 dark:border-slate-700']"
      >
        <!-- Header row -->
        <button
          class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
          @click="toggleExpand(result.id)"
        >
          <ExclamationTriangleIcon v-if="result.is_critical" class="w-4 h-4 text-red-500 dark:text-red-400 shrink-0" />
          <span class="text-sm font-medium text-gray-900 dark:text-slate-100 flex-1">{{ result.panel_name }}</span>
          <span v-if="!result.reviewed_at" class="text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded">Unreviewed</span>
          <span class="text-xs text-gray-400 dark:text-slate-500">{{ fmtDate(result.resulted_date) }}</span>
          <span v-if="result.lab_name" class="text-xs text-gray-400 dark:text-slate-500">{{ result.lab_name }}</span>
          <ChevronDownIcon :class="['w-4 h-4 text-gray-400 transition-transform', expandedIds.has(result.id) ? 'rotate-180' : '']" />
        </button>

        <!-- Expanded detail -->
        <div v-if="expandedIds.has(result.id)" class="border-t border-gray-100 dark:border-slate-700 px-4 py-3">
          <div class="text-xs text-gray-400 dark:text-slate-500 mb-3 flex gap-4 flex-wrap">
            <span v-if="result.ordering_provider">Provider: {{ result.ordering_provider }}</span>
            <span v-if="result.ordered_date">Ordered: {{ fmtDate(result.ordered_date) }}</span>
          </div>

          <!-- Components table -->
          <div v-if="result.components.length > 0" class="mb-3">
            <table class="w-full text-xs border-collapse">
              <thead>
                <tr class="border-b border-gray-100 dark:border-slate-700">
                  <th class="text-left py-1 font-medium text-gray-500 dark:text-slate-400">Component</th>
                  <th class="text-center py-1 font-medium text-gray-500 dark:text-slate-400">Value</th>
                  <th class="text-center py-1 font-medium text-gray-500 dark:text-slate-400">Reference</th>
                  <th class="text-center py-1 font-medium text-gray-500 dark:text-slate-400">Flag</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="comp in result.components" :key="comp.id" class="border-b border-gray-50 dark:border-slate-700/50">
                  <td class="py-1 text-gray-700 dark:text-slate-300">{{ comp.component_name }}</td>
                  <td :class="['text-center py-1', flagColor(comp.flag)]">{{ comp.value ?? '-' }}<span v-if="comp.unit" class="text-gray-400 ml-0.5">{{ comp.unit }}</span></td>
                  <td class="text-center py-1 text-gray-400 dark:text-slate-500">{{ comp.reference_range ?? '-' }}</td>
                  <td :class="['text-center py-1', flagColor(comp.flag)]">{{ comp.flag ?? '' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="flex items-center gap-3">
            <button
              v-if="!result.reviewed_at"
              :disabled="reviewingId === result.id"
              class="text-xs px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 transition-colors"
              @click="reviewResult(result)"
            >
              {{ reviewingId === result.id ? 'Marking...' : 'Mark Reviewed' }}
            </button>
            <span v-if="result.reviewed_at" class="text-xs text-green-600 dark:text-green-400">
              Reviewed {{ fmtDate(result.reviewed_at) }}
              <span v-if="result.reviewed_by"> by {{ result.reviewed_by.first_name }} {{ result.reviewed_by.last_name }}</span>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
