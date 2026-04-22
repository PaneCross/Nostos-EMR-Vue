<script setup lang="ts">
// ─── GrievancesTab.vue ────────────────────────────────────────────────────────
// Participant grievance records per 42 CFR §460.120.
// Fields match Grievance::toApiArray() output.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ExclamationCircleIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
  participant: { id: number }
}>()

interface Grievance {
  id: number
  reference_number: string
  filed_at: string | null          // toApiArray: filed_at (ISO)
  category: string | null
  category_label: string | null
  description: string
  status: string
  status_label: string
  priority: string | null
  filed_by_name: string | null     // person who filed (string, not object)
  assigned_to: string | null       // assigned staff name (string)
  resolution_date: string | null
  is_urgent_overdue: boolean
  is_standard_overdue: boolean
  age_in_days: number | null
  aging_band: 'green' | 'yellow' | 'red' | 'overdue' | null
  cms_reportable: boolean
}

// Phase 13.5 — aging band color classes for the standard-30-day clock.
const AGING_BAND_CLASS: Record<string, string> = {
  green:   'border-l-4 border-l-emerald-400',
  yellow:  'border-l-4 border-l-amber-400',
  red:     'border-l-4 border-l-red-500',
  overdue: 'border-l-4 border-l-red-700 animate-pulse',
}

const grievances = ref<Grievance[]>([])
const loading    = ref(true)
const error      = ref('')

const STATUS_COLORS: Record<string, string> = {
  open:        'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
  under_review:'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
  in_review:   'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
  resolved:    'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
  closed:      'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
  withdrawn:   'bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500',
}

const PRIORITY_COLORS: Record<string, string> = {
  urgent:   'text-red-600 dark:text-red-400 font-semibold',
  standard: 'text-gray-500 dark:text-slate-400',
}

onMounted(async () => {
  try {
    const res = await axios.get(`/participants/${props.participant.id}/grievances`)
    grievances.value = Array.isArray(res.data) ? res.data : []
  } catch {
    error.value = 'Unable to load grievances.'
  } finally {
    loading.value = false
  }
})

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}
</script>

<template>
  <div class="p-6 max-w-4xl space-y-4">
    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Grievances</h2>

    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <div v-else-if="error" class="rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-300">
      {{ error }}
    </div>

    <div v-else-if="grievances.length === 0"
      class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-12 text-center"
    >
      <ExclamationCircleIcon class="w-8 h-8 mx-auto text-gray-300 dark:text-slate-600 mb-2" />
      <p class="text-sm text-gray-500 dark:text-slate-400">No grievances on record.</p>
    </div>

    <div v-else class="space-y-3">
      <div
        v-for="g in grievances"
        :key="g.id"
        :class="['bg-white dark:bg-slate-800 rounded-xl border p-4',
          (g.is_urgent_overdue || g.is_standard_overdue)
            ? 'border-red-300 dark:border-red-700'
            : 'border-gray-200 dark:border-slate-700',
          (g.aging_band && AGING_BAND_CLASS[g.aging_band]) || '']"
      >
        <div class="flex items-start justify-between gap-3 mb-2">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-semibold text-gray-800 dark:text-slate-200">
              {{ g.category_label ?? (g.category ? g.category.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()) : 'General') }}
            </span>
            <span :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium', STATUS_COLORS[g.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-500']">
              {{ g.status_label ?? g.status.replace(/_/g, ' ') }}
            </span>
            <span v-if="g.priority" :class="['text-xs', PRIORITY_COLORS[g.priority] ?? '']">
              {{ g.priority }}
            </span>
            <span v-if="g.is_urgent_overdue || g.is_standard_overdue"
              class="text-xs text-red-600 dark:text-red-400 font-medium">Overdue</span>
            <span v-else-if="g.age_in_days !== null"
              :class="['text-xs',
                g.aging_band === 'green'  ? 'text-emerald-600 dark:text-emerald-400' :
                g.aging_band === 'yellow' ? 'text-amber-600 dark:text-amber-400' :
                g.aging_band === 'red'    ? 'text-red-600 dark:text-red-400 font-medium' :
                'text-slate-500']">
              Day {{ g.age_in_days }}/30
            </span>
            <span v-if="g.cms_reportable"
              class="text-xs bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 px-1.5 py-0.5 rounded">CMS</span>
          </div>
          <div class="text-right shrink-0">
            <div class="text-xs font-mono text-gray-400 dark:text-slate-500">{{ g.reference_number }}</div>
            <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ fmtDate(g.filed_at) }}</div>
          </div>
        </div>

        <p class="text-sm text-gray-700 dark:text-slate-300 mb-2">{{ g.description }}</p>

        <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-slate-400 flex-wrap">
          <span v-if="g.filed_by_name">Filed by: {{ g.filed_by_name }}</span>
          <span v-if="g.assigned_to">Assigned to: {{ g.assigned_to }}</span>
          <span v-if="g.resolution_date">Resolved: {{ fmtDate(g.resolution_date) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
