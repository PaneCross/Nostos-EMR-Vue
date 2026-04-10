<script setup lang="ts">
// ─── InsuranceTab.vue ─────────────────────────────────────────────────────────
// Insurance coverage list: Medicare Part A/B/D, Medicaid, supplemental plans.
// Eligibility status badge (active/pending/terminated). Coverage dates shown.
// Add/edit insurance form with plan name, payer_id, group/member numbers.
// ─────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

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
</script>

<template>
  <div class="p-6">
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
