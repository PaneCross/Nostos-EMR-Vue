<script setup lang="ts">
// ─── Dashboards/PdeReconciliation.vue ────────────────────────────────────────
// Pharmacy/Finance analytics page. PDE (Prescription Drug Event) records are
// the Part D claim records the org submits to CMS. This page reconciles
// submitted vs accepted vs rejected PDEs by month so pharmacy + finance can
// track Part D claim health.
// Data: GET /billing/pde-reconciliation.json: bar chart + table.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import BarChart from '@/Components/Charts/BarChart.vue'

const rows = ref<any[]>([])
const loading = ref(true)
onMounted(() => axios.get('/billing/pde-reconciliation.json').then(r => rows.value = r.data.rows ?? []).finally(() => loading.value = false))

const chart = computed(() => {
  const labels = rows.value.map(r => r.month)
  return {
    labels,
    data: {
      labels,
      datasets: [
        { label: 'Submitted', data: rows.value.map(r => Number(r.submitted) || 0), backgroundColor: 'rgba(59, 130, 246, 0.7)' },
        { label: 'Paid',      data: rows.value.map(r => Number(r.paid) || 0),      backgroundColor: 'rgba(34, 197, 94, 0.7)' },
      ],
    },
  }
})
</script>

<template>
  <Head title="PDE Reconciliation" />
  <AppShell>
    <div class="p-6 space-y-4">
      <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">PDE Reconciliation</h1>
      <div v-if="loading" class="text-sm text-gray-500 dark:text-slate-400">Loading…</div>
      <div v-else class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
        <BarChart :labels="chart.labels" :data="chart.data" :height="300" />
        <table class="mt-4 min-w-full text-sm">
          <thead class="text-xs uppercase text-gray-500 dark:text-slate-400">
            <tr><th class="px-2 py-1 text-left">Month</th><th class="px-2 py-1 text-right">Submitted</th><th class="px-2 py-1 text-right">Paid</th><th class="px-2 py-1 text-right">Variance</th></tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <tr v-for="r in rows" :key="r.month">
              <td class="px-2 py-1">{{ r.month }}</td>
              <td class="px-2 py-1 text-right">${{ Number(r.submitted).toFixed(2) }}</td>
              <td class="px-2 py-1 text-right">${{ Number(r.paid).toFixed(2) }}</td>
              <td class="px-2 py-1 text-right" :class="Number(r.variance) > 0 ? 'text-red-600' : 'text-green-600'">
                ${{ Number(r.variance).toFixed(2) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
