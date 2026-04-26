<script setup lang="ts">
// ─── CareGapsTab.vue ───────────────────────────────────────────────────────
// HEDIS / Stars-style preventive-care gap list for this participant.
// Lazy-loads from /participants/{id}/care-gaps. Each row is a measure
// (annual flu, mammogram, A1C, etc.) with status (open / satisfied /
// not-applicable) and last-completed date when known.
//
// Read-only here. Closing a gap happens via the underlying clinical
// action (placing the order, charting the result) — this tab just
// surfaces the worklist.
// ───────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'

interface Participant { id: number }
const props = defineProps<{ participant: Participant }>()

const loading = ref(true)
const gaps = ref<any[]>([])

onMounted(() => {
  axios.get(`/participants/${props.participant.id}/care-gaps`)
    .then(r => gaps.value = r.data.gaps ?? [])
    .finally(() => loading.value = false)
})
</script>

<template>
  <div class="space-y-4">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Care Gaps</h2>
    <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs uppercase text-gray-500 dark:text-slate-400">
          <tr>
            <th class="px-3 py-2">Measure</th>
            <th class="px-3 py-2">Status</th>
            <th class="px-3 py-2">Next due</th>
            <th class="px-3 py-2">Reason open</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
          <tr v-for="g in gaps" :key="g.id">
            <td class="px-3 py-2">{{ g.measure }}</td>
            <td class="px-3 py-2">
              <span class="inline-block rounded px-2 py-0.5 text-xs" :class="g.satisfied ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'">
                {{ g.satisfied ? 'Satisfied' : 'Open' }}
              </span>
            </td>
            <td class="px-3 py-2">{{ g.next_due_date ?? '—' }}</td>
            <td class="px-3 py-2 text-gray-500 dark:text-slate-400">{{ g.reason_open ?? '—' }}</td>
          </tr>
          <tr v-if="!loading && gaps.length === 0">
            <td colspan="4" class="px-3 py-4 text-center text-gray-500 dark:text-slate-400">No care gaps evaluated.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
