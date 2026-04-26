<template>
  <AppShell>
    <Head title="HPMS Submissions" />

    <div class="p-6 space-y-6">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">HPMS Submissions</h1>
        <button
          @click="showModal = true"
          class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <PlusIcon class="w-4 h-4" />
          Generate
        </button>
      </div>

      <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table v-if="submissions.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Period</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Submitted At</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Created</th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="sub in submissions" :key="sub.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
              <td class="px-6 py-4 text-sm text-gray-900 dark:text-white">
                {{ submissionTypes[sub.submission_type] ?? sub.submission_type }}
              </td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                <span v-if="sub.period_start || sub.period_end">
                  {{ sub.period_start ?? '-' }} to {{ sub.period_end ?? '-' }}
                </span>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="px-6 py-4">
                <span
                  :class="sub.status === 'submitted'
                    ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                    : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'"
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize"
                >
                  {{ sub.status }}
                </span>
              </td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                {{ sub.submitted_at ?? '-' }}
              </td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                {{ sub.created_at }}
              </td>
            </tr>
          </tbody>
        </table>

        <div v-else class="flex flex-col items-center justify-center py-16 text-gray-500 dark:text-gray-400">
          <DocumentTextIcon class="w-10 h-10 mb-3 text-gray-300 dark:text-gray-600" />
          <p class="text-sm">No submissions.</p>
        </div>
      </div>
    </div>

    <!-- Generate Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md p-6 space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Generate HPMS Submission</h2>
          <button @click="closeModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Submission Type</label>
            <select name="type"
              v-model="form.type"
              class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">Select type...</option>
              <option v-for="(label, key) in submissionTypes" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>

          <div v-if="isEnrollmentType">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Month</label>
            <input
              type="date"
              v-model="form.month"
              class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>

          <template v-if="isQualityType">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Year</label>
              <input
                type="number"
                v-model.number="form.year"
                :min="2020"
                :max="2099"
                class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quarter</label>
              <select name="select"
                v-model.number="form.quarter"
                class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              >
                <option value="">Select quarter...</option>
                <option :value="1">Q1</option>
                <option :value="2">Q2</option>
                <option :value="3">Q3</option>
                <option :value="4">Q4</option>
              </select>
            </div>
          </template>
        </div>

        <p v-if="modalError" class="text-sm text-red-600 dark:text-red-400">{{ modalError }}</p>

        <div class="flex justify-end gap-3 pt-2">
          <button
            @click="closeModal"
            class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700"
          >
            Cancel
          </button>
          <button
            @click="submitGenerate"
            :disabled="submitting"
            class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:opacity-50"
          >
            {{ submitting ? 'Generating...' : 'Generate' }}
          </button>
        </div>
      </div>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
// ─── Finance/Hpms ───────────────────────────────────────────────────────────
// HPMS Submissions tracker. Lists every PACE submission generated for the
// CMS HPMS portal (enrollment files, quality data) with status, period, and
// generated-on timestamp. Generate button creates the next period's file.
//
// Data: paginated HPMS submission rows. Audience: Finance / Compliance dept.
// Key actions: Generate new submission, download generated payload, mark as
// submitted to CMS once uploaded to HPMS.
//
// Acronyms:
//   HPMS = Health Plan Management System (CMS portal for PACE submissions).
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import { PlusIcon, XMarkIcon, DocumentTextIcon } from '@heroicons/vue/24/outline'

interface HpmsSubmission {
  id: number
  submission_type: string
  period_start: string | null
  period_end: string | null
  status: 'draft' | 'submitted'
  submitted_at: string | null
  created_at: string
}

const props = defineProps<{
  submissions: HpmsSubmission[]
  submissionTypes: Record<string, string>
}>()

const showModal = ref(false)
const submitting = ref(false)
const modalError = ref('')

const form = ref({
  type: '',
  month: '',
  year: new Date().getFullYear(),
  quarter: '' as number | '',
})

const isEnrollmentType = computed(() =>
  ['enrollment', 'disenrollment'].includes(form.value.type)
)

const isQualityType = computed(() =>
  form.value.type === 'quality_data'
)

function closeModal() {
  showModal.value = false
  modalError.value = ''
  form.value = { type: '', month: '', year: new Date().getFullYear(), quarter: '' }
}

async function submitGenerate() {
  if (!form.value.type) {
    modalError.value = 'Please select a submission type.'
    return
  }
  submitting.value = true
  modalError.value = ''
  try {
    await axios.post('/billing/hpms/generate', form.value)
    closeModal()
    router.reload()
  } catch (err: any) {
    modalError.value = err?.response?.data?.message ?? 'An error occurred.'
  } finally {
    submitting.value = false
  }
}
</script>
