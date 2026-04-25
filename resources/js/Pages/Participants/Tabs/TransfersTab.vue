<script setup lang="ts">
// ─── TransfersTab.vue ─────────────────────────────────────────────────────────
// Site transfer history and pending transfer banner. Request transfer modal
// visible to enrollment/it_admin/super_admin roles. Shows source/destination
// sites, approver, reason, and 90-day read-access window for prior site.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { ArrowRightIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface SiteTransfer {
  id: number; status: string; reason: string | null
  requested_at: string; effective_date: string | null
  from_site: { id: number; name: string } | null
  to_site: { id: number; name: string } | null
  requested_by: { id: number; first_name: string; last_name: string } | null
  approved_by: { id: number; first_name: string; last_name: string } | null
}

interface Site { id: number; name: string }
interface Participant { id: number }

const props = defineProps<{
  participant: Participant
  transfers: SiteTransfer[]
  availableSites?: Site[]
}>()

const page = usePage()
const auth = computed(() => (page.props as Record<string, unknown>).auth as { user: { department: string; is_super_admin?: boolean } } | null)
const canRequestTransfer = computed(() => {
  const dept = auth.value?.user.department
  return dept === 'enrollment' || dept === 'it_admin' || auth.value?.user.is_super_admin
})

const transfers = ref<SiteTransfer[]>(props.transfers)
const showModal = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({ to_site_id: '', reason: '', effective_date: '' })

const pendingTransfer = computed(() => transfers.value.find(t => t.status === 'pending'))

const STATUS_COLORS: Record<string, string> = {
  pending:   'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300',
  approved:  'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
  completed: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
  cancelled: 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
}

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function submit() {
  if (!form.value.to_site_id) { error.value = 'Destination site is required.'; return }
  saving.value = true; error.value = ''
  try {
    const res = await axios.post(`/participants/${props.participant.id}/transfers`, form.value)
    transfers.value.unshift(res.data)
    showModal.value = false
    form.value = { to_site_id: '', reason: '', effective_date: '' }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to request transfer.'
  } finally {
    // Phase W1 — Audit-11 H1: clear saving on every path.
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <!-- Pending transfer alert -->
    <div v-if="pendingTransfer" class="mb-4 bg-amber-50 dark:bg-amber-950/30 border border-amber-300 dark:border-amber-700 rounded-lg p-4">
      <div class="flex items-start gap-2">
        <ExclamationTriangleIcon class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
        <div>
          <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Transfer Pending Approval</p>
          <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">
            Transfer to <strong>{{ pendingTransfer.to_site?.name }}</strong> requested on {{ fmtDate(pendingTransfer.requested_at) }}.
            <span v-if="pendingTransfer.reason"> Reason: {{ pendingTransfer.reason }}</span>
          </p>
        </div>
      </div>
    </div>

    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Site Transfers</h2>
      <button
        v-if="canRequestTransfer && !pendingTransfer"
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showModal = true"
      >
        Request Transfer
      </button>
    </div>

    <div v-if="transfers.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No transfer history.</div>
    <div v-else class="space-y-2">
      <div
        v-for="transfer in transfers"
        :key="transfer.id"
        class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
      >
        <div class="flex items-center gap-2 mb-1">
          <span class="text-sm text-gray-700 dark:text-slate-300">{{ transfer.from_site?.name ?? 'Unknown' }}</span>
          <ArrowRightIcon class="w-3.5 h-3.5 text-gray-400 dark:text-slate-500 shrink-0" />
          <span class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ transfer.to_site?.name ?? 'Unknown' }}</span>
          <span :class="['text-xs px-1.5 py-0.5 rounded ml-auto', STATUS_COLORS[transfer.status] ?? '']">{{ transfer.status }}</span>
        </div>
        <div class="text-xs text-gray-400 dark:text-slate-500 flex gap-3 flex-wrap">
          <span>Requested {{ fmtDate(transfer.requested_at) }}</span>
          <span v-if="transfer.effective_date">Effective {{ fmtDate(transfer.effective_date) }}</span>
          <span v-if="transfer.requested_by">By {{ transfer.requested_by.first_name }} {{ transfer.requested_by.last_name }}</span>
          <span v-if="transfer.approved_by">Approved by {{ transfer.approved_by.first_name }} {{ transfer.approved_by.last_name }}</span>
        </div>
        <p v-if="transfer.reason" class="text-xs text-gray-500 dark:text-slate-400 mt-1">{{ transfer.reason }}</p>
      </div>
    </div>

    <!-- Request transfer modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-sm w-full p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Request Site Transfer</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Destination Site *</label>
            <select name="to_site_id" v-model="form.to_site_id" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
              <option value="">Select site...</option>
              <option v-for="site in availableSites" :key="site.id" :value="site.id">{{ site.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Effective Date</label>
            <input v-model="form.effective_date" type="date" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Reason</label>
            <textarea v-model="form.reason" rows="3" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
        </div>
        <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mt-2">{{ error }}</p>
        <div class="flex gap-2 mt-4">
          <button :disabled="saving" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="submit">
            {{ saving ? 'Submitting...' : 'Request Transfer' }}
          </button>
          <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors" @click="showModal = false; error = ''">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</template>
