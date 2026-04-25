<script setup lang="ts">
// ─── Network / Contracted Providers — Phase S2 ─────────────────────────────
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Provider {
  id: number
  name: string
  npi: string | null
  provider_type: string
  specialty: string | null
  phone: string | null
  city: string | null
  state: string | null
  accepting_new_referrals: boolean
  is_active: boolean
  active_contract: {
    id: number
    contract_number: string | null
    effective_date: string
    termination_date: string | null
    reimbursement_basis: string
    reimbursement_value: number | null
    requires_prior_auth_default: boolean
  } | null
}
defineProps<{
  providers: Provider[]
  provider_types: string[]
  reimbursement_bases: string[]
}>()
</script>

<template>
  <AppShell>
    <Head title="Contracted Providers" />
    <div class="max-w-6xl mx-auto px-6 py-8">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Contracted Provider Network</h1>
      <p class="text-sm text-gray-600 dark:text-slate-400 mt-1">
        External specialists, hospitals, SNFs, imaging, and labs that the PACE program contracts with.
      </p>

      <div class="overflow-x-auto mt-6">
        <table class="min-w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg">
          <thead class="bg-gray-50 dark:bg-slate-900">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Name</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Type</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Specialty</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Location</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Reimbursement</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in providers" :key="p.id" class="border-t border-gray-100 dark:border-slate-700">
              <td class="px-3 py-2 text-sm text-gray-900 dark:text-slate-100">
                {{ p.name }}
                <div v-if="p.npi" class="text-xs text-gray-500">NPI {{ p.npi }}</div>
              </td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">{{ p.provider_type }}</td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">{{ p.specialty || '—' }}</td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">
                <template v-if="p.city || p.state">{{ p.city }}{{ p.city && p.state ? ', ' : '' }}{{ p.state }}</template>
                <template v-else>—</template>
              </td>
              <td class="px-3 py-2 text-sm">
                <template v-if="p.active_contract">
                  <span class="text-gray-900 dark:text-slate-100">
                    {{ p.active_contract.reimbursement_basis }}
                    <span v-if="p.active_contract.reimbursement_value">@ {{ p.active_contract.reimbursement_value }}</span>
                  </span>
                  <div v-if="p.active_contract.requires_prior_auth_default"
                       class="text-xs text-amber-700 dark:text-amber-300">PA required by default</div>
                </template>
                <span v-else class="text-xs text-amber-700 dark:text-amber-300">No active contract</span>
              </td>
              <td class="px-3 py-2 text-sm">
                <span v-if="!p.is_active" class="px-2 py-0.5 rounded-full text-xs bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300">Inactive</span>
                <span v-else-if="!p.accepting_new_referrals" class="px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">Closed to new</span>
                <span v-else class="px-2 py-0.5 rounded-full text-xs bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">Active</span>
              </td>
            </tr>
            <tr v-if="providers.length === 0">
              <td colspan="6" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-slate-400">No contracted providers yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
