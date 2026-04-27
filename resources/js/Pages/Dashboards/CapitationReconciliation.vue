<script setup lang="ts">
// ─── Dashboards/CapitationReconciliation.vue ─────────────────────────────────
// Finance/Executive analytics page. Compares CMS-paid capitation per month
// against the org's expected capitation (member months x rate), so finance
// can spot under/over-payment from CMS by month and follow up.
// CMS = Centers for Medicare & Medicaid Services.
// Data: GET /billing/capitation-reconciliation.json: bar chart + table.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import BarChart from '@/Components/Charts/BarChart.vue'

const rows = ref<any[]>([])
const loading = ref(true)
onMounted(() => axios.get('/billing/capitation-reconciliation.json').then(r => rows.value = r.data.rows ?? []).finally(() => loading.value = false))

const chart = computed(() => {
  const labels = rows.value.map(r => r.month_year)
  return {
    labels,
    data: {
      labels,
      datasets: [
        { label: 'Local expected', data: rows.value.map(r => Number(r.local_expected) || 0), backgroundColor: 'rgba(59, 130, 246, 0.7)' },
      ],
    },
  }
})
</script>

<template>
  <Head title="Capitation Reconciliation" />
  <AppShell>
    <div class="p-6 space-y-4">
      <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Capitation Reconciliation</h1>
      <p class="text-xs text-gray-500 dark:text-slate-400">
        Local expected totals per month. MMR comparison is wired via existing CmsReconciliation infrastructure.
      </p>
      <div v-if="loading" class="text-sm text-gray-500 dark:text-slate-400">Loading…</div>
      <div v-else class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
        <BarChart :labels="chart.labels" :data="chart.data" :height="300" />
        <table class="mt-4 min-w-full text-sm">
          <thead class="text-xs uppercase text-gray-500 dark:text-slate-400">
            <tr><th class="px-2 py-1 text-left">Month</th><th class="px-2 py-1 text-right">Participants</th><th class="px-2 py-1 text-right">Local expected</th></tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <tr v-for="r in rows" :key="r.month_year">
              <td class="px-2 py-1">{{ r.month_year }}</td>
              <td class="px-2 py-1 text-right">{{ r.participant_count }}</td>
              <td class="px-2 py-1 text-right">${{ Number(r.local_expected).toFixed(2) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
