<script setup lang="ts">
// ─── Enrollment/MarketingFunnel ─────────────────────────────────────────────
// Lead-source attribution + stage-by-stage conversion funnel for the
// enrollment pipeline. Helps marketing measure which referral channels
// (hospital DC planners, community events, web) are actually converting.
//
// Audience: Marketing + Enrollment leadership.
//
// Notable rules:
//   - PACE marketing must follow CMS PACE marketing guidelines — no
//     misleading benefit claims, must distinguish PACE from Medicare
//     Advantage. (Compliance text owned by Marketing.)
//   - Read-only view; marketing source is captured at referral creation.
// ────────────────────────────────────────────────────────────────────────────
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface SourceRow {
  source: string
  total: number
  in_pipeline: number
  enrolled: number
  declined: number
  withdrawn: number
  conversion_rate_pct: number
}
interface DeclineRow { reason: string; count: number }
interface Props {
  from: string
  to: string
  totals: {
    leads: number
    intake_complete: number
    enrolled: number
    declined: number
    withdrawn: number
    enrollment_rate_pct: number
  }
  by_source: SourceRow[]
  decline_reasons: DeclineRow[]
}
defineProps<Props>()
</script>

<template>
  <AppShell>
    <Head title="Marketing Funnel" />
    <div class="max-w-6xl mx-auto px-6 py-8">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Marketing & Enrollment Funnel</h1>
      <p class="text-sm text-gray-600 dark:text-slate-400 mt-1">{{ from }} → {{ to }}</p>

      <!-- Top-line funnel cards -->
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-6">
        <div v-for="(label, key) in {
          leads: 'Leads',
          intake_complete: 'Intake Complete',
          enrolled: 'Enrolled',
          declined: 'Declined',
          withdrawn: 'Withdrawn',
        }" :key="key"
             class="bg-white dark:bg-slate-800 rounded-lg p-3 border border-gray-200 dark:border-slate-700">
          <div class="text-xs text-gray-500 dark:text-slate-400">{{ label }}</div>
          <div class="text-2xl font-semibold text-gray-900 dark:text-slate-100">
            {{ totals[key as keyof typeof totals] }}
          </div>
        </div>
      </div>
      <div class="mt-3 text-sm text-gray-700 dark:text-slate-300">
        Enrollment rate: <strong>{{ totals.enrollment_rate_pct }}%</strong>
      </div>

      <!-- By-source table -->
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100 mt-8">By Referral Source</h2>
      <div class="overflow-x-auto mt-2">
        <table class="min-w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg">
          <thead class="bg-gray-50 dark:bg-slate-900">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Source</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Total</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">In Pipeline</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Enrolled</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Declined</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Withdrawn</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Conv. %</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in by_source" :key="row.source" class="border-t border-gray-100 dark:border-slate-700">
              <td class="px-3 py-2 text-sm text-gray-900 dark:text-slate-100">{{ row.source }}</td>
              <td class="px-3 py-2 text-sm text-right text-gray-900 dark:text-slate-100">{{ row.total }}</td>
              <td class="px-3 py-2 text-sm text-right text-gray-700 dark:text-slate-300">{{ row.in_pipeline }}</td>
              <td class="px-3 py-2 text-sm text-right text-emerald-700 dark:text-emerald-400">{{ row.enrolled }}</td>
              <td class="px-3 py-2 text-sm text-right text-amber-700 dark:text-amber-400">{{ row.declined }}</td>
              <td class="px-3 py-2 text-sm text-right text-gray-600 dark:text-slate-400">{{ row.withdrawn }}</td>
              <td class="px-3 py-2 text-sm text-right font-semibold text-gray-900 dark:text-slate-100">{{ row.conversion_rate_pct }}%</td>
            </tr>
            <tr v-if="by_source.length === 0">
              <td colspan="7" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-slate-400">No referrals in date range.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Decline reasons -->
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100 mt-8">Decline Reasons</h2>
      <ul class="mt-2 space-y-1">
        <li v-for="row in decline_reasons" :key="row.reason"
            class="flex justify-between bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded px-3 py-2 text-sm">
          <span class="text-gray-900 dark:text-slate-100">{{ row.reason }}</span>
          <span class="text-gray-600 dark:text-slate-400">{{ row.count }}</span>
        </li>
        <li v-if="decline_reasons.length === 0" class="text-sm text-gray-500 dark:text-slate-400">No declines in range.</li>
      </ul>
    </div>
  </AppShell>
</template>
