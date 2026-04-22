<script setup lang="ts">
// ─── InsuranceTab.vue ─────────────────────────────────────────────────────────
// Insurance coverage list: Medicare Part A/B/D, Medicaid, supplemental plans.
// Eligibility status badge (active/pending/terminated). Coverage dates shown.
// Add/edit insurance form with plan name, payer_id, group/member numbers.
// ─────────────────────────────────────────────────────────────────────────────

import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import {
    PlusIcon, BanknotesIcon, CheckBadgeIcon, ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

interface InsuranceCoverage {
  id: number; insurance_type: string; plan_name: string; payer_id: string | null
  group_number: string | null; member_id: string | null
  eligibility_status: string; effective_date: string | null; termination_date: string | null
  is_primary: boolean; notes: string | null
  verified_by: { id: number; first_name: string; last_name: string } | null
  verified_at: string | null
}

interface Participant { id: number }

const props = defineProps<{
  participant: Participant
  insurances: InsuranceCoverage[]
}>()

const INSURANCE_TYPE_LABELS: Record<string, string> = {
  medicare_a:   'Medicare Part A',
  medicare_b:   'Medicare Part B',
  medicare_d:   'Medicare Part D',
  medicaid:     'Medicaid',
  medigap:      'Medigap / Supplement',
  employer:     'Employer Plan',
  va:           'VA Benefits',
  private:      'Private Insurance',
  other:        'Other',
}

const ELIGIBILITY_COLORS: Record<string, string> = {
  active:      'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
  pending:     'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300',
  terminated:  'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300',
  inactive:    'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
  unknown:     'bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500',
}

const coverages = ref<InsuranceCoverage[]>(props.insurances)
const showModal = ref(false)
const saving = ref(false)
const error = ref('')
const editingId = ref<number | null>(null)

const blankForm = () => ({
  insurance_type: 'medicare_b', plan_name: '', payer_id: '',
  group_number: '', member_id: '', eligibility_status: 'active',
  effective_date: '', termination_date: '', is_primary: false, notes: '',
})
const form = ref(blankForm())

function openAdd() {
  editingId.value = null; form.value = blankForm(); error.value = ''; showModal.value = true
}

function openEdit(cov: InsuranceCoverage) {
  editingId.value = cov.id
  form.value = {
    insurance_type: cov.insurance_type, plan_name: cov.plan_name,
    payer_id: cov.payer_id ?? '', group_number: cov.group_number ?? '',
    member_id: cov.member_id ?? '', eligibility_status: cov.eligibility_status,
    effective_date: cov.effective_date?.slice(0, 10) ?? '',
    termination_date: cov.termination_date?.slice(0, 10) ?? '',
    is_primary: cov.is_primary, notes: cov.notes ?? '',
  }
  error.value = ''; showModal.value = true
}

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  const d = new Date(val.slice(0, 10) + 'T12:00:00')
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function submit() {
  if (!form.value.plan_name.trim()) { error.value = 'Plan name is required.'; return }
  saving.value = true; error.value = ''
  const payload = {
    ...form.value,
    payer_id: form.value.payer_id || null,
    group_number: form.value.group_number || null,
    member_id: form.value.member_id || null,
    effective_date: form.value.effective_date || null,
    termination_date: form.value.termination_date || null,
    notes: form.value.notes || null,
  }
  try {
    if (editingId.value) {
      const res = await axios.put(`/participants/${props.participant.id}/insurance/${editingId.value}`, payload)
      const idx = coverages.value.findIndex(c => c.id === editingId.value)
      if (idx !== -1) coverages.value[idx] = res.data
    } else {
      const res = await axios.post(`/participants/${props.participant.id}/insurance`, payload)
      coverages.value.push(res.data)
    }
    showModal.value = false; form.value = blankForm()
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save.'
    saving.value = false
  }
}

// ─── Phase 7 (MVP roadmap): Medicaid spend-down / share-of-cost sub-panel ────

interface SpendDownStatus {
    obligation: number; paid: number; remaining: number; met: boolean
    state: string | null; period: string; coverage_id: number
}
interface SpendDownPaymentRow {
    id: number; amount: number; paid_at: string | null; period: string
    payment_method: string; method_label: string
    reference_number: string | null; notes: string | null
    recorded_by: string | null
}
interface SpendDownPayload {
    coverage: null | {
        id: number; has_spend_down: boolean
        share_of_cost_monthly_amount: number
        spend_down_threshold: number
        spend_down_period_start: string | null
        spend_down_period_end: string | null
        spend_down_state: string | null
        plan_name: string | null
    }
    current_status: SpendDownStatus | null
    payments: SpendDownPaymentRow[]
    methods: Record<string, string>
}

const spendDown = ref<SpendDownPayload | null>(null)
const spendDownLoading = ref(false)

async function loadSpendDown() {
    spendDownLoading.value = true
    try {
        const res = await axios.get(`/participants/${props.participant.id}/spend-down`)
        spendDown.value = res.data
    } catch { /* silent */ }
    finally { spendDownLoading.value = false }
}

onMounted(loadSpendDown)

// Payment form
const paymentForm = ref({
    amount: '' as string | number,
    paid_at: new Date().toISOString().slice(0, 10),
    period_month_year: new Date().toISOString().slice(0, 7),
    payment_method: 'check',
    reference_number: '',
    notes: '',
})
const savingPayment = ref(false)
const showPaymentForm = ref(false)

async function submitPayment() {
    if (!paymentForm.value.amount) { alert('Amount is required'); return }
    savingPayment.value = true
    try {
        await axios.post(`/participants/${props.participant.id}/spend-down/payments`, paymentForm.value)
        showPaymentForm.value = false
        paymentForm.value.amount = ''
        paymentForm.value.reference_number = ''
        paymentForm.value.notes = ''
        await loadSpendDown()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to record payment.')
    } finally {
        savingPayment.value = false
    }
}

async function deletePayment(id: number) {
    if (!confirm('Delete this payment record?')) return
    try {
        await axios.delete(`/spend-down/payments/${id}`)
        await loadSpendDown()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to delete.')
    }
}

// Coverage config modal
const showCoverageForm = ref(false)
const savingCoverage = ref(false)
const coverageForm = ref({
    coverage_id: 0,
    share_of_cost_monthly_amount: '' as string | number,
    spend_down_threshold: '' as string | number,
    spend_down_period_start: '',
    spend_down_period_end: '',
    spend_down_state: '',
})

function openCoverageForm() {
    const cov = spendDown.value?.coverage
    coverageForm.value = {
        coverage_id: cov?.id ?? 0,
        share_of_cost_monthly_amount: cov?.share_of_cost_monthly_amount ?? '',
        spend_down_threshold: cov?.spend_down_threshold ?? '',
        spend_down_period_start: cov?.spend_down_period_start ?? '',
        spend_down_period_end: cov?.spend_down_period_end ?? '',
        spend_down_state: cov?.spend_down_state ?? '',
    }
    showCoverageForm.value = true
}

async function submitCoverage() {
    if (!coverageForm.value.coverage_id) {
        alert('No active Medicaid coverage on file — add one first.')
        return
    }
    savingCoverage.value = true
    try {
        await axios.post(`/participants/${props.participant.id}/spend-down/coverage`, coverageForm.value)
        showCoverageForm.value = false
        await loadSpendDown()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to update coverage.')
    } finally {
        savingCoverage.value = false
    }
}

const progressPct = computed(() => {
    const s = spendDown.value?.current_status
    if (!s || s.obligation === 0) return 0
    return Math.min(100, Math.round((s.paid / s.obligation) * 100))
})

function money(n: number): string {
    return '$' + (n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <div class="p-6">

    <!-- Phase 7 (MVP roadmap): Medicaid spend-down / share-of-cost panel -->
    <section v-if="spendDown && spendDown.coverage?.has_spend_down"
             class="mb-6 rounded-xl border p-5 shadow-sm"
             :class="spendDown.current_status?.met
               ? 'bg-emerald-50 dark:bg-emerald-950/30 border-emerald-200 dark:border-emerald-800'
               : 'bg-amber-50 dark:bg-amber-950/30 border-amber-200 dark:border-amber-800'">
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div class="flex items-start gap-3">
          <BanknotesIcon class="w-6 h-6 text-amber-600 dark:text-amber-300 mt-0.5" />
          <div>
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
              Medicaid Spend-Down / Share-of-Cost
              <span v-if="spendDown.coverage.spend_down_state" class="text-xs font-medium text-slate-500 ml-1">
                ({{ spendDown.coverage.spend_down_state }})
              </span>
            </h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
              Period {{ spendDown.current_status?.period ?? '—' }} ·
              <template v-if="spendDown.current_status?.met">
                <span class="text-emerald-700 dark:text-emerald-300 inline-flex items-center gap-1 font-semibold">
                  <CheckBadgeIcon class="w-3.5 h-3.5" /> Obligation Met
                </span>
              </template>
              <template v-else>
                <span class="text-amber-700 dark:text-amber-300 inline-flex items-center gap-1 font-semibold">
                  <ExclamationTriangleIcon class="w-3.5 h-3.5" /> Capitation Blocked
                </span>
              </template>
            </p>
          </div>
        </div>
        <div class="flex gap-2">
          <button
            class="text-xs px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700"
            @click="openCoverageForm"
          >Edit Obligation</button>
          <button
            class="text-xs px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-medium hover:bg-indigo-700"
            @click="showPaymentForm = !showPaymentForm"
          >{{ showPaymentForm ? 'Cancel' : 'Record Payment' }}</button>
        </div>
      </div>

      <!-- Progress bar -->
      <div class="mt-4">
        <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-300 mb-1">
          <span>Paid this period</span>
          <span class="tabular-nums">
            {{ money(spendDown.current_status?.paid ?? 0) }} /
            {{ money(spendDown.current_status?.obligation ?? 0) }}
            <span v-if="!spendDown.current_status?.met && (spendDown.current_status?.remaining ?? 0) > 0"
                  class="text-amber-700 dark:text-amber-300 font-semibold ml-1">
              · {{ money(spendDown.current_status.remaining) }} remaining
            </span>
          </span>
        </div>
        <div class="h-3 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
          <div class="h-full transition-all"
               :class="spendDown.current_status?.met
                 ? 'bg-emerald-500'
                 : progressPct > 50 ? 'bg-amber-400' : 'bg-orange-500'"
               :style="{ width: progressPct + '%' }"></div>
        </div>
      </div>

      <!-- Payment form -->
      <div v-if="showPaymentForm" class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-700 grid grid-cols-1 sm:grid-cols-5 gap-3">
        <div>
          <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Amount</label>
          <input v-model="paymentForm.amount" type="number" step="0.01" min="0"
            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Paid</label>
          <input v-model="paymentForm.paid_at" type="date"
            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Period</label>
          <input v-model="paymentForm.period_month_year" type="month"
            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Method</label>
          <select v-model="paymentForm.payment_method"
            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2">
            <option v-for="(label, key) in spendDown.methods" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Reference #</label>
          <input v-model="paymentForm.reference_number" type="text" placeholder="check #, EFT ref, etc."
            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
        </div>
        <div class="sm:col-span-5 flex justify-end">
          <button :disabled="savingPayment" @click="submitPayment"
            class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
            {{ savingPayment ? 'Saving...' : 'Record Payment' }}
          </button>
        </div>
      </div>

      <!-- Payment history -->
      <div v-if="spendDown.payments.length > 0" class="mt-5 border-t border-slate-200 dark:border-slate-700 pt-4">
        <h4 class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">
          Payment History (past 12 months)
        </h4>
        <table class="w-full text-xs">
          <thead class="text-slate-500 dark:text-slate-400">
            <tr>
              <th class="px-2 py-1 text-left">Period</th>
              <th class="px-2 py-1 text-left">Paid</th>
              <th class="px-2 py-1 text-right">Amount</th>
              <th class="px-2 py-1 text-left">Method</th>
              <th class="px-2 py-1 text-left">Reference</th>
              <th class="px-2 py-1"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            <tr v-for="p in spendDown.payments" :key="p.id">
              <td class="px-2 py-1 font-semibold">{{ p.period }}</td>
              <td class="px-2 py-1 text-slate-500">{{ p.paid_at ?? '—' }}</td>
              <td class="px-2 py-1 text-right tabular-nums">{{ money(p.amount) }}</td>
              <td class="px-2 py-1 text-slate-600 dark:text-slate-300">{{ p.method_label }}</td>
              <td class="px-2 py-1 text-slate-500 dark:text-slate-400 truncate">{{ p.reference_number ?? '—' }}</td>
              <td class="px-2 py-1 text-right">
                <button @click="deletePayment(p.id)" class="text-slate-400 hover:text-red-600 text-xs">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Offer to configure spend-down if a Medicaid coverage exists but spend-down isn't set -->
    <div v-else-if="spendDown && spendDown.coverage && !spendDown.coverage.has_spend_down"
         class="mb-4 flex items-center justify-between rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 px-4 py-3">
      <p class="text-xs text-slate-600 dark:text-slate-300">
        <BanknotesIcon class="w-4 h-4 inline -mt-1 mr-1 text-slate-500" />
        Medicaid coverage on file. If this participant has a share-of-cost obligation, configure it here.
      </p>
      <button class="text-xs px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700"
              @click="openCoverageForm">Configure Spend-Down</button>
    </div>

    <!-- Coverage config modal -->
    <div v-if="showCoverageForm"
         class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
         @click.self="showCoverageForm = false">
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
          <h3 class="font-semibold text-slate-900 dark:text-slate-100">Configure Spend-Down / Share-of-Cost</h3>
        </div>
        <div class="px-6 py-5 grid grid-cols-2 gap-4">
          <div class="col-span-2">
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Monthly Share-of-Cost Amount</label>
            <input v-model="coverageForm.share_of_cost_monthly_amount" type="number" step="0.01" min="0"
              class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">State</label>
            <input v-model="coverageForm.spend_down_state" maxlength="2" placeholder="CA"
              class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Threshold (optional)</label>
            <input v-model="coverageForm.spend_down_threshold" type="number" step="0.01" min="0"
              class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Period Start</label>
            <input v-model="coverageForm.spend_down_period_start" type="date"
              class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Period End</label>
            <input v-model="coverageForm.spend_down_period_end" type="date"
              class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
          </div>
        </div>
        <div class="px-6 py-3 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-2">
          <button @click="showCoverageForm = false" class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 text-sm">Cancel</button>
          <button @click="submitCoverage" :disabled="savingCoverage"
            class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
            {{ savingCoverage ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </div>
    </div>

    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Insurance</h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="openAdd"
      >
        <PlusIcon class="w-3 h-3" />
        Add Coverage
      </button>
    </div>

    <div v-if="coverages.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No insurance on file.</div>
    <div v-else class="space-y-2">
      <div
        v-for="cov in coverages"
        :key="cov.id"
        class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
      >
        <div class="flex items-start gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ cov.plan_name }}</span>
              <span class="text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-1.5 py-0.5 rounded">{{ INSURANCE_TYPE_LABELS[cov.insurance_type] ?? cov.insurance_type }}</span>
              <span v-if="cov.is_primary" class="text-xs bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded">Primary</span>
              <span :class="['text-xs px-1.5 py-0.5 rounded capitalize', ELIGIBILITY_COLORS[cov.eligibility_status] ?? '']">{{ cov.eligibility_status }}</span>
            </div>
            <div class="text-xs text-gray-500 dark:text-slate-400 mt-1 flex gap-3 flex-wrap">
              <span v-if="cov.member_id">Member ID: {{ cov.member_id }}</span>
              <span v-if="cov.group_number">Group: {{ cov.group_number }}</span>
              <span v-if="cov.payer_id">Payer: {{ cov.payer_id }}</span>
            </div>
            <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5 flex gap-3 flex-wrap">
              <span v-if="cov.effective_date">Effective: {{ fmtDate(cov.effective_date) }}</span>
              <span v-if="cov.termination_date">Ends: {{ fmtDate(cov.termination_date) }}</span>
            </div>
            <p v-if="cov.notes" class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">{{ cov.notes }}</p>
          </div>
          <button
            class="text-xs px-2 py-1 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 rounded hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors shrink-0"
            @click="openEdit(cov)"
          >
            Edit
          </button>
        </div>
      </div>
    </div>

    <!-- Add/Edit modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-lg w-full p-5 max-h-[90vh] overflow-y-auto">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">{{ editingId ? 'Edit Coverage' : 'Add Coverage' }}</h3>
        <div class="grid grid-cols-2 gap-3 mb-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Type</label>
            <select name="insurance_type" v-model="form.insurance_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
              <option v-for="(label, key) in INSURANCE_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Eligibility Status</label>
            <select name="eligibility_status" v-model="form.eligibility_status" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
              <option value="active">Active</option>
              <option value="pending">Pending</option>
              <option value="terminated">Terminated</option>
              <option value="inactive">Inactive</option>
              <option value="unknown">Unknown</option>
            </select>
          </div>
          <div class="col-span-2">
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Plan Name *</label>
            <input v-model="form.plan_name" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Member ID</label>
            <input v-model="form.member_id" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Group Number</label>
            <input v-model="form.group_number" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Effective Date</label>
            <input v-model="form.effective_date" type="date" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Termination Date</label>
            <input v-model="form.termination_date" type="date" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
        </div>
        <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-slate-300 mb-3 cursor-pointer">
          <input v-model="form.is_primary" type="checkbox" class="rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700" />
          Primary coverage
        </label>
        <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
        <div class="flex gap-2">
          <button :disabled="saving" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="submit">
            {{ saving ? 'Saving...' : 'Save' }}
          </button>
          <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors" @click="showModal = false; error = ''">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</template>
