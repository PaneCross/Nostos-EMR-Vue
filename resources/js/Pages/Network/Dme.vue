<script setup lang="ts">
// ─── Network / DME — Phase S3 + U4 management UI ───────────────────────────
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
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

// ── New-item form ──────────────────────────────────────────────────────────
const showAddItem = ref(false)
const addItemSaving = ref(false)
const addItemError = ref<string | null>(null)
const newItem = ref({
  item_type: '', manufacturer: '', model: '', serial_number: '', hcpcs_code: '',
  purchase_date: '', purchase_cost: '', notes: '',
})
async function submitAddItem() {
  addItemSaving.value = true
  addItemError.value = null
  try {
    await axios.post('/network/dme', { ...newItem.value, purchase_cost: newItem.value.purchase_cost || null })
    showAddItem.value = false
    newItem.value = {
      item_type: '', manufacturer: '', model: '', serial_number: '', hcpcs_code: '',
      purchase_date: '', purchase_cost: '', notes: '',
    }
    router.reload()
  } catch (e: any) {
    // Phase V4 — extract per-field 422 errors when present, else fall back to message.
    const errs = e?.response?.data?.errors ?? null
    addItemError.value = (errs && Object.keys(errs).length)
      ? Object.values(errs).flat().join('; ')
      : (e?.response?.data?.message ?? 'Failed to register DME item.')
  } finally {
    addItemSaving.value = false
  }
}

// ── Issue form (for available items) ───────────────────────────────────────
const issueItemId = ref<number | null>(null)
const issueForm = ref({ participant_mrn: '', issued_at: new Date().toISOString().slice(0, 10), expected_return_at: '' })
const issueSaving = ref(false)
const issueError = ref<string | null>(null)
function startIssue(itemId: number) {
  issueItemId.value = itemId
  issueForm.value = { participant_mrn: '', issued_at: new Date().toISOString().slice(0, 10), expected_return_at: '' }
  issueError.value = null
}
async function submitIssue() {
  if (! issueItemId.value) return
  issueSaving.value = true
  issueError.value = null
  try {
    // Resolve MRN → participant_id via existing search endpoint.
    const lookup = await axios.get('/participants/search', { params: { q: issueForm.value.participant_mrn } })
    const results = Array.isArray(lookup.data) ? lookup.data : (lookup.data?.results ?? lookup.data?.participants ?? [])
    const participant = results[0]
    if (! participant) {
      issueError.value = 'No participant found with that MRN.'
      return
    }
    await axios.post(`/network/dme/${issueItemId.value}/issue`, {
      participant_id: participant.id,
      issued_at: issueForm.value.issued_at,
      expected_return_at: issueForm.value.expected_return_at || null,
    })
    issueItemId.value = null
    router.reload()
  } catch (e: any) {
    const errs = e?.response?.data?.errors ?? null
    issueError.value = (errs && Object.keys(errs).length)
      ? Object.values(errs).flat().join('; ')
      : (e?.response?.data?.message ?? 'Failed to issue DME.')
  } finally {
    issueSaving.value = false
  }
}

// ── Return action — Phase V7 inline panel instead of window.prompt ─────────
const returnIssuanceId = ref<number | null>(null)
const returnForm = ref<{ returned_at: string; return_condition: 'good' | 'damaged' | 'lost' }>({
  returned_at: new Date().toISOString().slice(0, 10),
  return_condition: 'good',
})
const returnSaving = ref(false)
const returnError = ref<string | null>(null)
function startReturn(issuanceId: number) {
  returnIssuanceId.value = issuanceId
  returnForm.value = { returned_at: new Date().toISOString().slice(0, 10), return_condition: 'good' }
  returnError.value = null
}
async function submitReturn() {
  if (! returnIssuanceId.value) return
  returnSaving.value = true
  returnError.value = null
  try {
    await axios.post(`/network/dme/issuances/${returnIssuanceId.value}/return`, returnForm.value)
    returnIssuanceId.value = null
    router.reload()
  } catch (e: any) {
    const errs = e?.response?.data?.errors ?? null
    returnError.value = (errs && Object.keys(errs).length)
      ? Object.values(errs).flat().join('; ')
      : (e?.response?.data?.message ?? 'Return failed.')
  } finally {
    returnSaving.value = false
  }
}
</script>

<template>
  <AppShell>
    <Head title="DME Tracking" />
    <div class="max-w-6xl mx-auto px-6 py-8">
      <div class="flex items-start justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">DME Inventory</h1>
          <p class="text-sm text-gray-600 dark:text-slate-400 mt-1">Durable medical equipment lifecycle: register → issue → service → return.</p>
        </div>
        <button @click="showAddItem = !showAddItem"
                class="text-sm px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                data-testid="dme-add-toggle">
          {{ showAddItem ? 'Cancel' : '+ Register Item' }}
        </button>
      </div>

      <!-- Add-item form -->
      <form v-if="showAddItem" @submit.prevent="submitAddItem"
            class="mt-4 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-4 grid grid-cols-1 sm:grid-cols-3 gap-3"
            data-testid="dme-add-form">
        <div class="sm:col-span-3 text-xs text-gray-500 dark:text-slate-400">All fields except item_type are optional.</div>
        <input v-model="newItem.item_type" required placeholder="Item type (e.g. walker, cpap)"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newItem.manufacturer" placeholder="Manufacturer"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newItem.model" placeholder="Model"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newItem.serial_number" placeholder="Serial #"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newItem.hcpcs_code" placeholder="HCPCS code"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <input v-model="newItem.purchase_cost" placeholder="Purchase cost ($)" type="number" step="0.01"
               class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        <div class="sm:col-span-3 flex items-center gap-2">
          <button :disabled="addItemSaving" type="submit"
                  class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg disabled:opacity-50">
            {{ addItemSaving ? 'Saving…' : 'Save' }}
          </button>
          <span v-if="addItemError" class="text-sm text-red-600 dark:text-red-400">{{ addItemError }}</span>
        </div>
      </form>

      <!-- Open issuances -->
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100 mt-6">Open Issuances</h2>
      <ul class="mt-2 space-y-2">
        <li v-for="iss in open_issuances" :key="iss.id"
            class="bg-white dark:bg-slate-800 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2 flex justify-between items-center text-sm">
          <span class="text-gray-900 dark:text-slate-100">
            {{ iss.item.item_type }} · {{ iss.item.manufacturer }} {{ iss.item.model }}
            → {{ iss.participant.last_name }}, {{ iss.participant.first_name }} ({{ iss.participant.mrn }})
            <span class="text-gray-500 dark:text-slate-400 ml-2">issued {{ iss.issued_at }}</span>
          </span>
          <button @click="startReturn(iss.id)"
                  class="text-xs px-2 py-1 border border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400 rounded hover:bg-blue-50 dark:hover:bg-blue-900/20"
                  data-testid="dme-return-btn">
            Mark Returned
          </button>
        </li>
        <li v-if="open_issuances.length === 0" class="text-sm text-gray-500 dark:text-slate-400">No items currently issued.</li>
      </ul>

      <!-- Phase V7 — return form (replaces window.prompt) -->
      <div v-if="returnIssuanceId !== null"
           class="mt-3 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 rounded-lg p-3"
           data-testid="dme-return-form">
        <div class="text-sm font-medium text-emerald-900 dark:text-emerald-200 mb-2">
          Return DME issuance #{{ returnIssuanceId }}
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
          <input v-model="returnForm.returned_at" type="date"
                 class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
          <select v-model="returnForm.return_condition"
                  class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100">
            <option value="good">Good</option>
            <option value="damaged">Damaged</option>
            <option value="lost">Lost</option>
          </select>
        </div>
        <div class="mt-2 flex items-center gap-2">
          <button @click="submitReturn" :disabled="returnSaving"
                  class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg disabled:opacity-50">
            {{ returnSaving ? 'Saving…' : 'Confirm Return' }}
          </button>
          <button @click="returnIssuanceId = null" class="px-3 py-1.5 border rounded-lg text-gray-700 dark:text-slate-300">
            Cancel
          </button>
          <span v-if="returnError" class="text-sm text-red-600 dark:text-red-400">{{ returnError }}</span>
        </div>
      </div>

      <!-- Issue form (modal-style overlay row) -->
      <div v-if="issueItemId !== null"
           class="mt-3 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg p-3"
           data-testid="dme-issue-form">
        <div class="text-sm font-medium text-blue-900 dark:text-blue-200 mb-2">
          Issue item #{{ issueItemId }} to participant
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
          <input v-model="issueForm.participant_mrn" placeholder="Participant MRN"
                 class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
          <input v-model="issueForm.issued_at" type="date"
                 class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
          <input v-model="issueForm.expected_return_at" type="date" placeholder="Expected return"
                 class="border rounded px-2 py-1.5 dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" />
        </div>
        <div class="mt-2 flex items-center gap-2">
          <button @click="submitIssue" :disabled="issueSaving"
                  class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg disabled:opacity-50">
            {{ issueSaving ? 'Issuing…' : 'Issue' }}
          </button>
          <button @click="issueItemId = null" class="px-3 py-1.5 border rounded-lg text-gray-700 dark:text-slate-300">
            Cancel
          </button>
          <span v-if="issueError" class="text-sm text-red-600 dark:text-red-400">{{ issueError }}</span>
        </div>
      </div>

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
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-slate-400">Action</th>
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
              <td class="px-3 py-2 text-sm text-right">
                <button v-if="i.status === 'available'" @click="startIssue(i.id)"
                        class="text-xs px-2 py-1 border border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400 rounded hover:bg-blue-50 dark:hover:bg-blue-900/20"
                        data-testid="dme-issue-btn">
                  Issue
                </button>
              </td>
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
