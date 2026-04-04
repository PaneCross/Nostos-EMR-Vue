<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
  participant: { id: number }
}>()

interface MedRecon {
  id: number
  started_at: string
  status: string
  step: number | null
  completed_at: string | null
  initiated_by: { first_name: string; last_name: string } | null
  provider_approved_at: string | null
  notes: string | null
}

const records = ref<MedRecon[]>([])
const loading = ref(true)
const error = ref('')

const STATUS_COLORS: Record<string, string> = {
  draft:             'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
  in_progress:       'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
  pending_approval:  'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
  approved:          'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
  rejected:          'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
}

onMounted(async () => {
  try {
    const res = await axios.get(`/participants/${props.participant.id}/med-reconciliations`)
    records.value = res.data
  } catch {
    error.value = 'Unable to load medication reconciliation records.'
  } finally {
    loading.value = false
  }
})

function fmtDate(val: string | null): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

function stepLabel(step: number | null): string {
  if (!step) return '-'
  const labels: Record<number, string> = {
    1: 'Start', 2: 'Prior Meds', 3: 'Comparison', 4: 'Decisions', 5: 'Provider Approval',
  }
  return labels[step] ?? `Step ${step}`
}
</script>

<template>
  <div class="p-6 max-w-4xl space-y-4">
    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Medication Reconciliation</h2>

    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <div v-else-if="error" class="rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-300">
      {{ error }}
    </div>

    <div v-else-if="records.length === 0"
      class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-12 text-center"
    >
      <ClipboardDocumentCheckIcon class="w-8 h-8 mx-auto text-gray-300 dark:text-slate-600 mb-2" />
      <p class="text-sm text-gray-500 dark:text-slate-400">No medication reconciliation records found.</p>
    </div>

    <div v-else class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-slate-700/50">
          <tr>
            <th
              v-for="h in ['Date Started', 'Status', 'Step', 'Initiated By', 'Completed', 'Provider Approved']"
              :key="h"
              class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
            >{{ h }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <tr v-for="rec in records" :key="rec.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
            <td class="px-4 py-3 text-gray-800 dark:text-slate-200">{{ fmtDate(rec.started_at) }}</td>
            <td class="px-4 py-3">
              <span :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize', STATUS_COLORS[rec.status] ?? '']">
                {{ rec.status.replace(/_/g, ' ') }}
              </span>
            </td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ stepLabel(rec.step) }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
              <span v-if="rec.initiated_by">{{ rec.initiated_by.first_name }} {{ rec.initiated_by.last_name }}</span>
              <span v-else class="text-gray-400 dark:text-slate-500">-</span>
            </td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ fmtDate(rec.completed_at) }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ fmtDate(rec.provider_approved_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
