<script setup lang="ts">
// ─── Dashboards/CareGaps.vue — Phase K1 ──────────────────────────────────────
// Population-health "care gaps" view: participants who are missing recommended
// screenings or interventions (annual flu vaccine, A1c, mammogram, etc.) per
// HEDIS / CMS Stars measures. Two views: org-wide summary + the logged-in
// clinician's own panel.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import BarChart from '@/Components/Charts/BarChart.vue'

const summary = ref<any[]>([])
const myPanel = ref<any[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    const [s, p] = await Promise.all([
      axios.get('/care-gaps/summary'),
      axios.get('/care-gaps/my-panel').catch(() => ({ data: { rows: [] } })),
    ])
    summary.value = s.data.rows ?? []
    myPanel.value = p.data.rows ?? p.data ?? []
  } finally { loading.value = false }
})

const chart = computed(() => {
  const labels = summary.value.map((r: any) => r.measure)
  return {
    labels,
    data: {
      labels,
      datasets: [
        { label: 'Open', data: summary.value.map((r: any) => Number(r.open) || 0), backgroundColor: 'rgba(239, 68, 68, 0.7)' },
        { label: 'Satisfied', data: summary.value.map((r: any) => Number(r.satisfied) || 0), backgroundColor: 'rgba(34, 197, 94, 0.7)' },
      ],
    },
  }
})
</script>

<template>
  <Head title="Care Gaps" />
  <AppShell>
    <div class="p-6 space-y-6">
      <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Care Gaps</h1>

      <div v-if="loading" class="text-sm text-gray-500 dark:text-slate-400">Loading…</div>

      <div v-else class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
        <h2 class="text-sm font-semibold mb-3 text-gray-900 dark:text-slate-100">Tenant summary by measure</h2>
        <BarChart :labels="chart.labels" :data="chart.data" :height="300" />
      </div>

      <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
        <h2 class="text-sm font-semibold mb-3 text-gray-900 dark:text-slate-100">My panel</h2>
        <table class="min-w-full text-sm">
          <thead class="text-xs uppercase text-gray-500 dark:text-slate-400">
            <tr>
              <th class="px-2 py-1 text-left">Participant</th>
              <th class="px-2 py-1 text-left">Measure</th>
              <th class="px-2 py-1 text-left">Status</th>
              <th class="px-2 py-1 text-left">Next due</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <tr v-for="g in myPanel" :key="g.id">
              <td class="px-2 py-1">{{ g.participant?.name ?? g.participant_id }}</td>
              <td class="px-2 py-1">{{ g.measure }}</td>
              <td class="px-2 py-1">{{ g.satisfied ? 'Satisfied' : 'Open' }}</td>
              <td class="px-2 py-1">{{ g.next_due_date ?? '—' }}</td>
            </tr>
            <tr v-if="myPanel.length === 0">
              <td colspan="4" class="px-2 py-4 text-center text-gray-500 dark:text-slate-400">
                No panel gaps or endpoint unavailable.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
