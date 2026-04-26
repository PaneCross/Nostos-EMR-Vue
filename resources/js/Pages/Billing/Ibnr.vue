<script setup lang="ts">
// ─── Billing/Ibnr ───────────────────────────────────────────────────────────
// IBNR (Incurred But Not Reported) estimator — claims for services already
// delivered but not yet billed/processed. Critical for accurate monthly
// financial close because PACE programs bear full medical risk under cap.
//
// Audience: Finance / Actuarial.
//
// Notable rules:
//   - Lag-based estimator: completion factors derived from historical claim
//     lag pattern. Estimates are advisory; actuarial sign-off required for
//     external reporting.
//   - Read-only here; estimate is recomputed nightly.
// ────────────────────────────────────────────────────────────────────────────
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface MonthRow {
  month: string
  total_encounters: number
  submitted: number
  completion_pct: number
  completion_factor_used: number
  estimated_ibnr_count: number
  estimated_ibnr_dollars: number
}
interface Estimate {
  service_months: MonthRow[]
  total_estimated_ibnr_count: number
  total_estimated_ibnr_dollars: number
  completion_factors: Record<number, number>
}
defineProps<{
  months_back: number
  estimate: Estimate
  honest_label: string
}>()
function money(n: number): string {
  return '$' + (n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <AppShell>
    <Head title="IBNR Estimator" />
    <div class="max-w-5xl mx-auto px-6 py-8">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">IBNR Estimator</h1>
      <p class="text-sm text-gray-600 dark:text-slate-400 mt-1">{{ honest_label }}</p>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-6">
        <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
          <p class="text-xs text-amber-700/80 dark:text-amber-400/80">Total Estimated IBNR (count)</p>
          <p class="text-3xl font-bold text-amber-700 dark:text-amber-300">
            {{ estimate.total_estimated_ibnr_count.toFixed(1) }}
          </p>
        </div>
        <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
          <p class="text-xs text-amber-700/80 dark:text-amber-400/80">Total Estimated IBNR ($)</p>
          <p class="text-3xl font-bold text-amber-700 dark:text-amber-300">
            {{ money(estimate.total_estimated_ibnr_dollars) }}
          </p>
        </div>
      </div>

      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100 mt-8">Service Months</h2>
      <div class="overflow-x-auto mt-2">
        <table class="min-w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg">
          <thead class="bg-gray-50 dark:bg-slate-900">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Month</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Total</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Submitted</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Completion %</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Factor</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">IBNR Count</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">IBNR $</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in estimate.service_months" :key="m.month" class="border-t border-gray-100 dark:border-slate-700">
              <td class="px-3 py-2 text-sm text-gray-900 dark:text-slate-100">{{ m.month }}</td>
              <td class="px-3 py-2 text-sm text-right text-gray-700 dark:text-slate-300">{{ m.total_encounters }}</td>
              <td class="px-3 py-2 text-sm text-right text-gray-700 dark:text-slate-300">{{ m.submitted }}</td>
              <td class="px-3 py-2 text-sm text-right text-gray-700 dark:text-slate-300">{{ m.completion_pct }}%</td>
              <td class="px-3 py-2 text-sm text-right text-gray-700 dark:text-slate-300">{{ m.completion_factor_used }}</td>
              <td class="px-3 py-2 text-sm text-right text-amber-700 dark:text-amber-300">{{ m.estimated_ibnr_count }}</td>
              <td class="px-3 py-2 text-sm text-right text-amber-700 dark:text-amber-300">{{ money(m.estimated_ibnr_dollars) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100 mt-6">Completion Factors (trailing 12mo)</h2>
      <ul class="mt-2 grid grid-cols-2 sm:grid-cols-5 gap-2">
        <li v-for="(f, lag) in estimate.completion_factors" :key="lag"
            class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded px-3 py-2 text-sm">
          <div class="text-xs text-gray-500 dark:text-slate-400">Lag {{ lag }}mo</div>
          <div class="font-semibold text-gray-900 dark:text-slate-100">{{ (f * 100).toFixed(1) }}%</div>
        </li>
      </ul>
    </div>
  </AppShell>
</template>
