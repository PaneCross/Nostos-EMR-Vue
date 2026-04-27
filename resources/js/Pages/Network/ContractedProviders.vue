<script setup lang="ts">
// ─── Network/ContractedProviders ────────────────────────────────────────────
// Manage the PACE program's external contracted-provider network: specialists,
// hospitals, ancillary services with negotiated per-CPT rates that the program
// uses to estimate downstream cost when authorizing referrals.
//
// Audience: Network Management, Finance, Contracting.
//
// Notable rules:
//   - Per-CPT (Current Procedural Terminology) rate overrides are stored
//     against each contract; absence of a CPT-specific rate falls back to
//     the contracted percent-of-Medicare default.
//   - Effective-date windows are honored; expired contracts surface red.
// ────────────────────────────────────────────────────────────────────────────
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
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
const props = defineProps<{
  providers: Provider[]
  provider_types: string[]
  reimbursement_bases: string[]
}>()

// ── Add provider ───────────────────────────────────────────────────────────
const showAddProvider = ref(false)
const addProviderSaving = ref(false)
const addProviderError = ref<string | null>(null)
const newProvider = ref({
  name: '', npi: '', tax_id: '', provider_type: 'specialist', specialty: '',
  phone: '', fax: '', address_line1: '', city: '', state: '', zip: '',
  accepting_new_referrals: true, is_active: true,
})
async function submitAddProvider() {
  addProviderSaving.value = true
  addProviderError.value = null
  try {
    await axios.post('/network/contracted-providers', { ...newProvider.value })
    showAddProvider.value = false
    newProvider.value = {
      name: '', npi: '', tax_id: '', provider_type: 'specialist', specialty: '',
      phone: '', fax: '', address_line1: '', city: '', state: '', zip: '',
      accepting_new_referrals: true, is_active: true,
    }
    router.reload()
  } catch (e: any) {
    addProviderError.value = e?.response?.data?.message
      ?? Object.values(e?.response?.data?.errors ?? {}).flat().join('; ')
      ?? 'Failed to save provider.'
  } finally {
    addProviderSaving.value = false
  }
}

// ── Add-contract sub-flow ──────────────────────────────────────────────────
const contractProviderId = ref<number | null>(null)
const contractForm = ref({
  contract_number: '', effective_date: new Date().toISOString().slice(0, 10),
  termination_date: '', reimbursement_basis: 'percent_of_medicare',
  reimbursement_value: '80', requires_prior_auth_default: false,
})
const contractSaving = ref(false)
const contractError = ref<string | null>(null)
function startAddContract(providerId: number) {
  contractProviderId.value = providerId
  contractError.value = null
}
async function submitAddContract() {
  if (! contractProviderId.value) return
  contractSaving.value = true
  contractError.value = null
  try {
    await axios.post(`/network/contracted-providers/${contractProviderId.value}/contracts`, {
      ...contractForm.value,
      reimbursement_value: contractForm.value.reimbursement_value === '' ? null : Number(contractForm.value.reimbursement_value),
      termination_date: contractForm.value.termination_date || null,
    })
    contractProviderId.value = null
    router.reload()
  } catch (e: any) {
    contractError.value = e?.response?.data?.message
      ?? Object.values(e?.response?.data?.errors ?? {}).flat().join('; ')
      ?? 'Failed to save contract.'
  } finally {
    contractSaving.value = false
  }
}
</script>

<template>
  <AppShell>
    <Head title="Contracted Providers" />
    <div class="max-w-6xl mx-auto px-6 py-8">
      <div class="flex items-start justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Contracted Provider Network</h1>
          <p class="text-sm text-gray-600 dark:text-slate-400 mt-1">External specialists, hospitals, SNFs, imaging, labs.</p>
        </div>
        <button @click="showAddProvider = !showAddProvider"
                class="text-sm px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                data-testid="cp-add-toggle">
          {{ showAddProvider ? 'Cancel' : '+ Add Provider' }}
        </button>
      </div>

      <!-- Add provider form -->
      <form v-if="showAddProvider" @submit.prevent="submitAddProvider"
            class="mt-4 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-4 grid grid-cols-1 sm:grid-cols-3 gap-3"
            data-testid="cp-add-form">
        <input v-model="newProvider.name" required placeholder="Name *"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <select v-model="newProvider.provider_type"
                class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100">
          <option v-for="t in provider_types" :key="t" :value="t">{{ t }}</option>
        </select>
        <input v-model="newProvider.specialty" placeholder="Specialty"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newProvider.npi" placeholder="NPI (10 digits)" maxlength="10"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newProvider.tax_id" placeholder="Tax ID / EIN"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newProvider.phone" placeholder="Phone"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newProvider.address_line1" placeholder="Address" class="sm:col-span-2 border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newProvider.city" placeholder="City" class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newProvider.state" placeholder="State (2-letter)" maxlength="2" class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newProvider.zip" placeholder="ZIP" class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <div class="sm:col-span-3 flex items-center gap-2">
          <button :disabled="addProviderSaving" type="submit"
                  class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg disabled:opacity-50">
            {{ addProviderSaving ? 'Saving…' : 'Save' }}
          </button>
          <span v-if="addProviderError" class="text-sm text-red-600 dark:text-red-400">{{ addProviderError }}</span>
        </div>
      </form>

      <div class="overflow-x-auto mt-6">
        <table class="min-w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg">
          <thead class="bg-gray-50 dark:bg-slate-900">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Name</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Type</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Specialty</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Reimbursement</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Status</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in providers" :key="p.id" class="border-t border-gray-100 dark:border-slate-700">
              <td class="px-3 py-2 text-sm text-gray-900 dark:text-slate-100">
                {{ p.name }}
                <div v-if="p.npi" class="text-xs text-gray-500">NPI {{ p.npi }}</div>
              </td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">{{ p.provider_type }}</td>
              <td class="px-3 py-2 text-sm text-gray-700 dark:text-slate-300">{{ p.specialty || '-' }}</td>
              <td class="px-3 py-2 text-sm">
                <template v-if="p.active_contract">
                  <span class="text-gray-900 dark:text-slate-100">
                    {{ p.active_contract.reimbursement_basis }}<span v-if="p.active_contract.reimbursement_value"> @ {{ p.active_contract.reimbursement_value }}</span>
                  </span>
                </template>
                <span v-else class="text-xs text-amber-700 dark:text-amber-300">No active contract</span>
              </td>
              <td class="px-3 py-2 text-sm">
                <span v-if="!p.is_active" class="px-2 py-0.5 rounded-full text-xs bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300">Inactive</span>
                <span v-else-if="!p.accepting_new_referrals" class="px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">Closed to new</span>
                <span v-else class="px-2 py-0.5 rounded-full text-xs bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">Active</span>
              </td>
              <td class="px-3 py-2 text-sm text-right">
                <button @click="startAddContract(p.id)"
                        class="text-xs px-2 py-1 border border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400 rounded hover:bg-blue-50 dark:hover:bg-blue-900/20"
                        data-testid="cp-add-contract-btn">
                  + Contract
                </button>
              </td>
            </tr>
            <tr v-if="providers.length === 0">
              <td colspan="6" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-slate-400">No contracted providers yet.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Add contract overlay -->
      <div v-if="contractProviderId !== null"
           class="mt-3 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg p-3"
           data-testid="cp-add-contract-form">
        <div class="text-sm font-medium text-blue-900 dark:text-blue-200 mb-2">
          Add contract for provider #{{ contractProviderId }}
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
          <input v-model="contractForm.contract_number" placeholder="Contract # (optional)" class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
          <input v-model="contractForm.effective_date" type="date" class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
          <input v-model="contractForm.termination_date" type="date" placeholder="Termination" class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
          <select v-model="contractForm.reimbursement_basis" class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100">
            <option v-for="b in reimbursement_bases" :key="b" :value="b">{{ b }}</option>
          </select>
          <input v-model="contractForm.reimbursement_value" type="number" step="0.01" placeholder="Value" class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
          <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
            <input v-model="contractForm.requires_prior_auth_default" type="checkbox" />
            PA required by default
          </label>
        </div>
        <div class="mt-2 flex items-center gap-2">
          <button @click="submitAddContract" :disabled="contractSaving"
                  class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg disabled:opacity-50">
            {{ contractSaving ? 'Saving…' : 'Save Contract' }}
          </button>
          <button @click="contractProviderId = null" class="px-3 py-1.5 border rounded-lg text-gray-700 dark:text-slate-300">
            Cancel
          </button>
          <span v-if="contractError" class="text-sm text-red-600 dark:text-red-400">{{ contractError }}</span>
        </div>
      </div>
    </div>
  </AppShell>
</template>
