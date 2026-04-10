<script setup lang="ts">
// ─── ConsentsTab.vue ──────────────────────────────────────────────────────────
// Participant consent and acknowledgment records.
// HIPAA 45 CFR §164.520: NPP acknowledgment required at first service delivery.
//
// Actions:
//   POST /participants/{id}/consents           → store (create new consent record)
//   PUT  /participants/{id}/consents/{id}      → update (acknowledge / refuse / unable)
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon, DocumentCheckIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
  participant: { id: number }
}>()

interface ConsentRecord {
  id: number
  consent_type: string
  type_label: string
  document_title: string | null
  status: string
  status_label: string
  acknowledged_by: string | null
  acknowledged_at: string | null
  expiration_date: string | null
  representative_type: string | null
  notes: string | null
  created_at: string
}

const consents  = ref<ConsentRecord[]>([])
const loading   = ref(true)
const loadError = ref('')

// ── Add consent form ──────────────────────────────────────────────────────────
const showAddForm = ref(false)
const addSaving   = ref(false)
const addError    = ref('')
const addForm = ref({
  consent_type:     'npp_acknowledgment',
  document_title:   '',
  status:           'pending' as string,
  acknowledged_by:  '',
  acknowledged_at:  '',
  notes:            '',
})

// ── Acknowledge modal ─────────────────────────────────────────────────────────
const showAckModal    = ref(false)
const ackConsentId    = ref<number | null>(null)
const ackBy           = ref('')
const ackDate         = ref(new Date().toISOString().slice(0, 10))
const ackRepType      = ref('self')
const ackSaving       = ref(false)
const ackError        = ref('')

// ── Action saving ─────────────────────────────────────────────────────────────
const actionSaving = ref<Record<number, boolean>>({})

const TYPE_LABELS: Record<string, string> = {
  npp_acknowledgment:  'NPP Acknowledgment',
  hipaa_authorization: 'HIPAA Authorization',
  treatment_consent:   'Treatment Consent',
  research_consent:    'Research Consent',
  photo_release:       'Photo / Media Release',
  other:               'Other',
}

const STATUS_COLORS: Record<string, string> = {
  acknowledged:     'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
  pending:          'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
  refused:          'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
  unable_to_consent:'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
}

const STATUS_LABELS: Record<string, string> = {
  acknowledged:     'Acknowledged',
  pending:          'Pending',
  refused:          'Refused',
  unable_to_consent:'Unable to Consent',
}

onMounted(async () => {
  await loadConsents()
})

async function loadConsents() {
  loading.value = true; loadError.value = ''
  try {
    const res = await axios.get(`/participants/${props.participant.id}/consents`)
    consents.value = res.data.consents ?? []
  } catch {
    loadError.value = 'Unable to load consent records.'
  } finally {
    loading.value = false
  }
}

function fmtDate(val: string | null): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

function updateConsent(updated: ConsentRecord) {
  const idx = consents.value.findIndex(c => c.id === updated.id)
  if (idx >= 0) consents.value[idx] = updated
}

// ── Add consent ───────────────────────────────────────────────────────────────
async function submitAdd() {
  if (!addForm.value.document_title.trim()) { addError.value = 'Document title is required.'; return }
  addSaving.value = true; addError.value = ''
  try {
    const payload: Record<string, unknown> = {
      consent_type:   addForm.value.consent_type,
      document_title: addForm.value.document_title,
      status:         addForm.value.status,
      notes:          addForm.value.notes || null,
    }
    if (addForm.value.status === 'acknowledged') {
      payload.acknowledged_by  = addForm.value.acknowledged_by || null
      payload.acknowledged_at  = addForm.value.acknowledged_at || null
    }
    const res = await axios.post(`/participants/${props.participant.id}/consents`, payload)
    consents.value.unshift(res.data.consent)
    showAddForm.value = false
    addForm.value = { consent_type: 'npp_acknowledgment', document_title: '', status: 'pending', acknowledged_by: '', acknowledged_at: '', notes: '' }
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    addError.value = err.response?.data?.message ?? 'Failed to add consent record.'
  } finally {
    addSaving.value = false
  }
}

// ── Acknowledge modal ─────────────────────────────────────────────────────────
function openAckModal(consent: ConsentRecord) {
  ackConsentId.value = consent.id
  ackBy.value        = ''
  ackDate.value      = new Date().toISOString().slice(0, 10)
  ackRepType.value   = 'self'
  ackError.value     = ''
  showAckModal.value = true
}

async function submitAck() {
  ackSaving.value = true; ackError.value = ''
  try {
    const res = await axios.put(
      `/participants/${props.participant.id}/consents/${ackConsentId.value}`,
      {
        status:              'acknowledged',
        acknowledged_by:     ackBy.value || null,
        acknowledged_at:     ackDate.value || null,
        representative_type: ackRepType.value,
      },
    )
    updateConsent(res.data.consent)
    showAckModal.value = false
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    ackError.value = err.response?.data?.message ?? 'Failed to record acknowledgment.'
  } finally {
    ackSaving.value = false
  }
}

// ── Refuse ────────────────────────────────────────────────────────────────────
async function refuse(consent: ConsentRecord) {
  if (!confirm(`Mark "${TYPE_LABELS[consent.consent_type] ?? consent.consent_type}" as refused?`)) return
  actionSaving.value[consent.id] = true
  try {
    const res = await axios.put(
      `/participants/${props.participant.id}/consents/${consent.id}`,
      { status: 'refused' },
    )
    updateConsent(res.data.consent)
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    alert(err.response?.data?.message ?? 'Failed to update consent.')
  } finally {
    delete actionSaving.value[consent.id]
  }
}

// ── Unable to consent ─────────────────────────────────────────────────────────
async function markUnable(consent: ConsentRecord) {
  if (!confirm(`Mark "${TYPE_LABELS[consent.consent_type] ?? consent.consent_type}" as unable to consent?`)) return
  actionSaving.value[consent.id] = true
  try {
    const res = await axios.put(
      `/participants/${props.participant.id}/consents/${consent.id}`,
      { status: 'unable_to_consent' },
    )
    updateConsent(res.data.consent)
  } catch {
    alert('Failed to update consent.')
  } finally {
    delete actionSaving.value[consent.id]
  }
}
</script>

<template>
  <div class="p-6 max-w-4xl space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Consents</h2>
        <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">HIPAA 45 CFR §164.520 acknowledgment tracking</p>
      </div>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon class="w-3 h-3" />
        Add Consent
      </button>
    </div>

    <!-- Loading / error -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>
    <div v-else-if="loadError" class="rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-300">
      {{ loadError }}
    </div>

    <!-- Add consent form -->
    <div v-if="showAddForm && !loading" class="bg-gray-50 dark:bg-slate-700/50 rounded-xl border border-gray-200 dark:border-slate-600 p-5">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-4">New Consent Record</h3>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Consent Type</label>
          <select name="consent_type" v-model="addForm.consent_type"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100">
            <option v-for="(label, key) in TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Status</label>
          <select name="status" v-model="addForm.status"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100">
            <option value="pending">Pending</option>
            <option value="acknowledged">Acknowledged</option>
            <option value="refused">Refused</option>
            <option value="unable_to_consent">Unable to Consent</option>
          </select>
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Document Title <span class="text-red-500">*</span></label>
          <input v-model="addForm.document_title" type="text" placeholder="e.g. HIPAA Notice of Privacy Practices v2024"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" />
        </div>
        <template v-if="addForm.status === 'acknowledged'">
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Acknowledged By</label>
            <input v-model="addForm.acknowledged_by" type="text" placeholder="Full name of person who acknowledged"
              class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Acknowledged Date</label>
            <input v-model="addForm.acknowledged_at" type="date"
              class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" />
          </div>
        </template>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Notes</label>
          <textarea v-model="addForm.notes" rows="2" placeholder="Optional notes"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100 resize-none" />
        </div>
      </div>
      <p v-if="addError" class="text-xs text-red-600 dark:text-red-400 mb-2">{{ addError }}</p>
      <div class="flex gap-2">
        <button :disabled="addSaving" @click="submitAdd"
          class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">
          {{ addSaving ? 'Saving...' : 'Save Record' }}
        </button>
        <button @click="showAddForm = false"
          class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
          Cancel
        </button>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else-if="!loading && consents.length === 0"
      class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-12 text-center">
      <DocumentCheckIcon class="w-8 h-8 mx-auto text-gray-300 dark:text-slate-600 mb-2" />
      <p class="text-sm text-gray-500 dark:text-slate-400">No consent records on file.</p>
    </div>

    <!-- Consent records table -->
    <div v-if="!loading && consents.length > 0" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-slate-700/50">
          <tr>
            <th
              v-for="h in ['Consent Type', 'Status', 'Acknowledged', 'Expires', 'Acknowledged By', 'Actions']"
              :key="h"
              class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
            >{{ h }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <tr v-for="c in consents" :key="c.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
            <td class="px-4 py-3 font-medium text-gray-800 dark:text-slate-200">
              {{ c.type_label || TYPE_LABELS[c.consent_type] || c.consent_type.replace(/_/g, ' ') }}
              <div v-if="c.document_title" class="text-xs text-gray-500 dark:text-slate-400 font-normal mt-0.5">{{ c.document_title }}</div>
              <div v-if="c.notes" class="text-xs text-gray-400 dark:text-slate-500 font-normal mt-0.5 italic">{{ c.notes }}</div>
            </td>
            <td class="px-4 py-3">
              <span :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium', STATUS_COLORS[c.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-500']">
                {{ c.status_label || STATUS_LABELS[c.status] || c.status }}
              </span>
            </td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 whitespace-nowrap text-xs">{{ fmtDate(c.acknowledged_at) }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 whitespace-nowrap text-xs">{{ fmtDate(c.expiration_date) }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 text-xs">{{ c.acknowledged_by ?? '-' }}</td>
            <td class="px-4 py-3">
              <!-- Actions only for pending consents -->
              <div v-if="c.status === 'pending'" class="flex flex-col gap-1.5 min-w-max">
                <button
                  :disabled="actionSaving[c.id]"
                  class="inline-flex items-center gap-1 text-xs px-2.5 py-1 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 transition-colors whitespace-nowrap"
                  @click="openAckModal(c)"
                >
                  <DocumentCheckIcon class="w-3 h-3" />
                  Acknowledge
                </button>
                <button
                  :disabled="actionSaving[c.id]"
                  class="inline-flex items-center gap-1 text-xs px-2.5 py-1 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 rounded-md hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-50 transition-colors whitespace-nowrap"
                  @click="markUnable(c)"
                >
                  Unable to Consent
                </button>
                <button
                  :disabled="actionSaving[c.id]"
                  class="inline-flex items-center gap-1 text-xs px-2.5 py-1 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 rounded-md hover:bg-red-50 dark:hover:bg-red-950/40 disabled:opacity-50 transition-colors"
                  @click="refuse(c)"
                >
                  Refuse
                </button>
              </div>
              <span v-else class="text-xs text-gray-400 dark:text-slate-500 italic">-</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Acknowledge modal -->
    <Teleport to="body">
      <div v-if="showAckModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
          <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Record Acknowledgment</h3>
          <p class="text-sm text-gray-600 dark:text-slate-400">
            Document that the participant (or authorized representative) has acknowledged this consent.
          </p>
          <div class="space-y-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Acknowledged By</label>
              <input v-model="ackBy" type="text" placeholder="Full name of participant or representative"
                class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Date Acknowledged</label>
              <input v-model="ackDate" type="date"
                class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Representative Type</label>
              <select name="ackRepType" v-model="ackRepType"
                class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100">
                <option value="self">Self (Participant)</option>
                <option value="guardian">Legal Guardian</option>
                <option value="poa">Power of Attorney</option>
                <option value="healthcare_proxy">Healthcare Proxy</option>
              </select>
            </div>
          </div>
          <p v-if="ackError" class="text-xs text-red-600 dark:text-red-400">{{ ackError }}</p>
          <div class="flex gap-2 justify-end">
            <button
              class="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
              @click="showAckModal = false"
            >Cancel</button>
            <button
              :disabled="ackSaving"
              class="text-sm px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 transition-colors"
              @click="submitAck"
            >{{ ackSaving ? 'Saving...' : 'Record Acknowledgment' }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
