<template>
  <AppShell>
    <Head title="Revenue Integrity" />

    <div class="p-6 space-y-6">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Revenue Integrity</h1>

      <!-- KPI Cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div
          v-for="kpi in kpis"
          :key="kpi.label"
          :class="kpiCardClass(kpi.status)"
          class="rounded-lg border p-4 space-y-1"
        >
          <p class="text-xs font-medium uppercase tracking-wider" :class="kpiLabelClass(kpi.status)">{{ kpi.label }}</p>
          <p class="text-2xl font-bold" :class="kpiValueClass(kpi.status)">{{ kpi.value }}</p>
        </div>
      </div>

      <!-- Denial KPIs -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-1">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Denials</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ denial_kpis.total_denials }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-1">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Denial Rate</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ denial_kpis.denial_rate.toFixed(1) }}%</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-1">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Amount at Risk</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">${{ denial_kpis.amount_at_risk.toLocaleString() }}</p>
        </div>
      </div>

      <!-- Gaps Table -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Gaps</h2>
        </div>
        <table v-if="gaps.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Description</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Participant</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Estimated Impact</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="(gap, idx) in gaps" :key="idx" class="hover:bg-gray-50 dark:hover:bg-gray-700">
              <td class="px-6 py-4 text-sm text-gray-900 dark:text-white">{{ gap.description ?? '-' }}</td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ gap.participant ?? '-' }}</td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                {{ gap.estimated_impact != null ? '$' + Number(gap.estimated_impact).toLocaleString() : '-' }}
              </td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300 capitalize">{{ gap.status ?? '-' }}</td>
            </tr>
          </tbody>
        </table>
        <div v-else class="flex flex-col items-center justify-center py-12 text-gray-500 dark:text-gray-400">
          <CheckCircleIcon class="w-8 h-8 mb-2 text-green-400" />
          <p class="text-sm">No gaps found.</p>
        </div>
      </div>

      <!-- Pending Items Table -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Pending Items</h2>
        </div>
        <table v-if="pending.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Description</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Amount</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Due Date</th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="(item, idx) in pending" :key="idx" class="hover:bg-gray-50 dark:hover:bg-gray-700">
              <td class="px-6 py-4 text-sm text-gray-900 dark:text-white">{{ item.description ?? '-' }}</td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                {{ item.amount != null ? '$' + Number(item.amount).toLocaleString() : '-' }}
              </td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ item.due_date ?? '-' }}</td>
            </tr>
          </tbody>
        </table>
        <div v-else class="flex flex-col items-center justify-center py-12 text-gray-500 dark:text-gray-400">
          <InboxIcon class="w-8 h-8 mb-2 text-gray-300 dark:text-gray-600" />
          <p class="text-sm">No pending items.</p>
        </div>
      </div>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import { CheckCircleIcon, InboxIcon } from '@heroicons/vue/24/outline'

interface RevenueKPI {
  label: string
  value: number | string
  status: 'ok' | 'warning' | 'critical'
}

const props = defineProps<{
  kpis: RevenueKPI[]
  denial_kpis: { total_denials: number; denial_rate: number; amount_at_risk: number }
  gaps: any[]
  pending: any[]
}>()

function kpiCardClass(status: string): string {
  if (status === 'critical') return 'bg-red-50 border-red-200 dark:bg-red-950 dark:border-red-800'
  if (status === 'warning') return 'bg-amber-50 border-amber-200 dark:bg-amber-950 dark:border-amber-800'
  return 'bg-green-50 border-green-200 dark:bg-green-950 dark:border-green-800'
}

function kpiLabelClass(status: string): string {
  if (status === 'critical') return 'text-red-600 dark:text-red-400'
  if (status === 'warning') return 'text-amber-600 dark:text-amber-400'
  return 'text-green-600 dark:text-green-400'
}

function kpiValueClass(status: string): string {
  if (status === 'critical') return 'text-red-700 dark:text-red-300'
  if (status === 'warning') return 'text-amber-700 dark:text-amber-300'
  return 'text-green-700 dark:text-green-300'
}
</script>
