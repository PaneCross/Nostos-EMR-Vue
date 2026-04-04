<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ExclamationCircleIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
  participant: { id: number }
}>()

interface Grievance {
  id: number
  submitted_at: string
  category: string | null
  description: string
  status: string
  resolution: string | null
  resolved_at: string | null
  submitted_by: { first_name: string; last_name: string } | null
  assigned_to: { first_name: string; last_name: string } | null
}

const grievances = ref<Grievance[]>([])
const loading = ref(true)
const error = ref('')

const STATUS_COLORS: Record<string, string> = {
  open:       'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
  in_review:  'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
  resolved:   'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
  closed:     'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
  withdrawn:  'bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500',
}

onMounted(async () => {
  try {
    const res = await axios.get(`/participants/${props.participant.id}/grievances`)
    grievances.value = res.data
  } catch {
    error.value = 'Unable to load grievances.'
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
        class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4"
      >
        <div class="flex items-start justify-between gap-3 mb-2">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-medium text-gray-800 dark:text-slate-200">
              {{ g.category ? g.category.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'General' }}
            </span>
            <span :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize', STATUS_COLORS[g.status] ?? '']">
              {{ g.status.replace(/_/g, ' ') }}
            </span>
          </div>
          <span class="text-xs text-gray-500 dark:text-slate-400 shrink-0">{{ fmtDate(g.submitted_at) }}</span>
        </div>

        <p class="text-sm text-gray-700 dark:text-slate-300 mb-2">{{ g.description }}</p>

        <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-slate-400 flex-wrap">
          <span v-if="g.submitted_by">
            Submitted by: {{ g.submitted_by.first_name }} {{ g.submitted_by.last_name }}
          </span>
          <span v-if="g.assigned_to">
            Assigned to: {{ g.assigned_to.first_name }} {{ g.assigned_to.last_name }}
          </span>
          <span v-if="g.resolved_at">Resolved: {{ fmtDate(g.resolved_at) }}</span>
        </div>

        <div v-if="g.resolution" class="mt-2 text-xs text-gray-600 dark:text-slate-400 bg-gray-50 dark:bg-slate-700/50 rounded px-3 py-2">
          <span class="font-medium">Resolution:</span> {{ g.resolution }}
        </div>
      </div>
    </div>
  </div>
</template>
