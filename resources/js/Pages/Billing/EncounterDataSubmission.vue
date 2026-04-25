<script setup lang="ts">
// ─── Billing / Encounter Data Submission — Phase S4 ────────────────────────
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Batch {
  id: number
  file_name: string
  record_count: number
  total_charge_amount: number | null
  status: string
  submitted_at: string | null
  clearinghouse_reference: string | null
}
defineProps<{
  driver: string
  driver_label: string
  is_real_vendor: boolean
  recent_batches: Batch[]
  honest_label: string
}>()
</script>

<template>
  <AppShell>
    <Head title="Encounter Data Submission" />
    <div class="max-w-5xl mx-auto px-6 py-8">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">CMS Encounter Data Submission</h1>
      <p class="text-sm text-gray-600 dark:text-slate-400 mt-1">{{ honest_label }}</p>

      <div class="mt-6 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-4">
        <div class="flex justify-between items-center">
          <span class="font-semibold text-gray-900 dark:text-slate-100">Active Driver</span>
          <span :class="[
            'px-2 py-0.5 rounded-full text-xs',
            is_real_vendor ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300'
                           : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'
          ]">
            {{ is_real_vendor ? 'Vendor active' : 'Null gateway' }}
          </span>
        </div>
        <p class="text-sm text-gray-700 dark:text-slate-300 mt-2">{{ driver_label }}</p>
        <p class="text-xs text-gray-500 dark:text-slate-400 italic mt-2">
          Set ENCOUNTER_DATA_DRIVER=direct_cms|availity in .env once contract signed.
        </p>
      </div>

      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100 mt-8">Recent EDR Batches</h2>
      <div class="overflow-x-auto mt-2">
        <table class="min-w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg">
          <thead class="bg-gray-50 dark:bg-slate-900">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Batch</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Records</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Total $</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Status</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Submitted</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in recent_batches" :key="b.id" class="border-t border-gray-100 dark:border-slate-700">
              <td class="px-3 py-2 text-sm text-gray-900 dark:text-slate-100">{{ b.file_name }}</td>
              <td class="px-3 py-2 text-sm text-right text-gray-700 dark:text-slate-300">{{ b.record_count }}</td>
              <td class="px-3 py-2 text-sm text-right text-gray-700 dark:text-slate-300">{{ b.total_charge_amount ?? '—' }}</td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">{{ b.status }}</td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">{{ b.submitted_at ?? '—' }}</td>
            </tr>
            <tr v-if="recent_batches.length === 0">
              <td colspan="5" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-slate-400">No EDR batches yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
