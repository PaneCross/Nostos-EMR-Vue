<script setup lang="ts">
// ─── Network / DME — Phase S3 ──────────────────────────────────────────────
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Item {
  id: number
  item_type: string
  manufacturer: string | null
  model: string | null
  serial_number: string | null
  hcpcs_code: string | null
  status: string
  next_service_due: string | null
}
interface OpenIssuance {
  id: number
  participant: { mrn: string; first_name: string; last_name: string }
  item: { item_type: string; manufacturer: string | null; model: string | null }
  issued_at: string
  expected_return_at: string | null
}
defineProps<{
  items: Item[]
  open_issuances: OpenIssuance[]
  item_statuses: string[]
  return_conditions: string[]
}>()
</script>

<template>
  <AppShell>
    <Head title="DME Tracking" />
    <div class="max-w-6xl mx-auto px-6 py-8">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">DME Inventory</h1>
      <p class="text-sm text-gray-600 dark:text-slate-400 mt-1">Durable medical equipment lifecycle: issue → service → return.</p>

      <!-- Open issuances -->
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100 mt-6">Open Issuances</h2>
      <ul class="mt-2 space-y-2">
        <li v-for="iss in open_issuances" :key="iss.id"
            class="bg-white dark:bg-slate-800 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2 flex justify-between text-sm">
          <span class="text-gray-900 dark:text-slate-100">
            {{ iss.item.item_type }} · {{ iss.item.manufacturer }} {{ iss.item.model }}
            → {{ iss.participant.last_name }}, {{ iss.participant.first_name }} ({{ iss.participant.mrn }})
          </span>
          <span class="text-gray-500 dark:text-slate-400">
            issued {{ iss.issued_at }}<span v-if="iss.expected_return_at"> · expected back {{ iss.expected_return_at }}</span>
          </span>
        </li>
        <li v-if="open_issuances.length === 0" class="text-sm text-gray-500 dark:text-slate-400">No items currently issued.</li>
      </ul>

      <!-- Inventory -->
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100 mt-8">Inventory</h2>
      <div class="overflow-x-auto mt-2">
        <table class="min-w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg">
          <thead class="bg-gray-50 dark:bg-slate-900">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Type</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Manufacturer / Model</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Serial</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">HCPCS</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Status</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Next Service</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in items" :key="i.id" class="border-t border-gray-100 dark:border-slate-700">
              <td class="px-3 py-2 text-sm text-gray-900 dark:text-slate-100">{{ i.item_type }}</td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">{{ i.manufacturer }} {{ i.model }}</td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">{{ i.serial_number || '—' }}</td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">{{ i.hcpcs_code || '—' }}</td>
              <td class="px-3 py-2 text-sm">
                <span :class="[
                  'px-2 py-0.5 rounded-full text-xs',
                  i.status === 'available' ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' :
                  i.status === 'issued'    ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' :
                  i.status === 'servicing' ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300' :
                  i.status === 'lost'      ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' :
                                              'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300'
                ]">{{ i.status }}</span>
              </td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">{{ i.next_service_due || '—' }}</td>
            </tr>
            <tr v-if="items.length === 0">
              <td colspan="6" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-slate-400">No DME items registered yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
