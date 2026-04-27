<script setup lang="ts">
// ─── DisenrollmentTab.vue ─────────────────────────────────────────────────────
// Disenrollment status and workflow tab.
// - Enrolled participants: shows "Disenroll" button (warning confirmation modal)
// - Disenrolled participants: shows disenrollment record + "Re-enroll" button
//
// Backend routes:
//   POST /participants/{participant}/disenroll   → ReferralController::disenroll()
//   POST /participants/{participant}/reenroll    → ReferralController::reenroll()
//
// Authorization:
//   Disenroll: enrollment, it_admin, super_admin
//   Re-enroll: enrollment, it_admin, super_admin
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { UserMinusIcon, ExclamationTriangleIcon, UserPlusIcon } from '@heroicons/vue/24/outline'

interface Participant {
  id: number
  first_name: string
  last_name: string
  mrn: string
  enrollment_status: string
  enrollment_date: string | null
  disenrollment_date: string | null
  disenrollment_reason: string | null
  site: { name: string }
}

const props = withDefaults(defineProps<{
  participant: Participant
  // Grouped reason labels from the backend (App\Support\DisenrollmentTaxonomy).
  // Shape: { death: {death:'Death'}, voluntary: {...}, involuntary: {...} }
  disenrollmentReasons?: Record<string, Record<string, string>>
}>(), {
  disenrollmentReasons: () => ({}),
})

// 42 CFR §460.160(b): death is a reason, not a status. 'disenrolled' is the only
// terminal status now; legacy 'deceased'/'transferred' kept for old records.
const isDisenrolled = computed(() =>
  ['disenrolled', 'deceased', 'transferred'].includes(props.participant.enrollment_status),
)

const isEnrolled = computed(() => props.participant.enrollment_status === 'enrolled')

// Canonical + legacy reason labels. Canonical comes from the server prop;
// the inline map handles historical rows with free-text or old-enum reasons.
const LEGACY_REASON_LABELS: Record<string, string> = {
  voluntary:              'Voluntary Disenrollment (legacy)',
  involuntary:            'Involuntary Disenrollment (legacy)',
  deceased:               'Death (legacy)',
  moved:                  'Moved Out of Service Area (legacy)',
  nf_admission:           'Nursing Facility Admission (legacy)',
  other:                  'Other (legacy)',
  moved_out_of_area:      'Moved Out of Service Area (legacy)',
  nursing_facility:       'Nursing Facility Admission (legacy)',
  hospitalization:        'Extended Hospitalization (legacy)',
  transferred:            'Transferred to Another PACE (legacy)',
  non_compliance:         'Non-Compliance (legacy)',
  medicaid_ineligibility: 'Loss of Medicaid Eligibility (legacy)',
  medicare_ineligibility: 'Loss of Medicare Eligibility (legacy)',
}

// Flatten the server-provided grouped labels into one lookup for display.
const REASON_LABELS = computed<Record<string, string>>(() => {
  const flat: Record<string, string> = { ...LEGACY_REASON_LABELS }
  for (const group of Object.values(props.disenrollmentReasons ?? {})) {
    for (const [k, v] of Object.entries(group)) flat[k] = v
  }
  return flat
})

// Order groups in the select: death | voluntary | involuntary.
const REASON_GROUPS: Array<{ key: string; label: string }> = [
  { key: 'death',       label: 'Death (42 CFR §460.160(b))' },
  { key: 'voluntary',   label: 'Voluntary (42 CFR §460.162)' },
  { key: 'involuntary', label: 'Involuntary (42 CFR §460.164)' },
]

function fmtDate(val: string | null): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

// ── Disenroll modal ───────────────────────────────────────────────────────────
const showDisenrollModal = ref(false)
const disenrollSaving    = ref(false)
const disenrollError     = ref('')
// Default reason is a canonical voluntary code: staff pick the specific
// sub-reason from the grouped dropdown.
const disenrollForm = ref({
  reason:                    'voluntary_other',
  effective_date:            new Date().toISOString().slice(0, 10),
  notes:                     '',
  cms_notification_required: true,
})

function openDisenrollModal() {
  disenrollForm.value = {
    reason: 'voluntary_other',
    effective_date: new Date().toISOString().slice(0, 10),
    notes: '',
    cms_notification_required: true,
  }
  disenrollError.value    = ''
  showDisenrollModal.value = true
}

// When the chosen reason is 'death', relax the effective-date rule per
// 42 CFR §460.160(b): date of death is the canonical disenrollment date.
const isDeathReason = computed(() => disenrollForm.value.reason === 'death')

async function submitDisenroll() {
  disenrollSaving.value = true; disenrollError.value = ''
  try {
    await axios.post(`/participants/${props.participant.id}/disenroll`, {
      reason:                    disenrollForm.value.reason,
      effective_date:            disenrollForm.value.effective_date,
      notes:                     disenrollForm.value.notes || null,
      cms_notification_required: disenrollForm.value.cms_notification_required,
    })
    showDisenrollModal.value = false
    // Reload participant prop from server
    router.reload({ only: ['participant'] })
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string; error?: string } } }
    disenrollError.value = err.response?.data?.message ?? err.response?.data?.error ?? 'Failed to disenroll participant.'
  } finally {
    disenrollSaving.value = false
  }
}

// ── Re-enroll ─────────────────────────────────────────────────────────────────
const showReenrollConfirm = ref(false)
const reenrollSaving      = ref(false)
const reenrollError       = ref('')

async function submitReenroll() {
  reenrollSaving.value = true; reenrollError.value = ''
  try {
    await axios.post(`/participants/${props.participant.id}/reenroll`)
    showReenrollConfirm.value = false
    router.reload({ only: ['participant'] })
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    reenrollError.value = err.response?.data?.message ?? 'Failed to re-enroll participant.'
    showReenrollConfirm.value = false
  } finally {
    reenrollSaving.value = false
  }
}
</script>

<template>
  <div class="p-6 max-w-3xl space-y-6">
    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Disenrollment</h2>

    <!-- Currently enrolled: green banner + disenroll button -->
    <div v-if="!isDisenrolled" class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950/40 px-6 py-8">
      <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <UserPlusIcon class="w-8 h-8 text-green-500 dark:text-green-400 shrink-0" />
          <div>
            <p class="text-sm font-semibold text-green-700 dark:text-green-300">
              {{ participant.first_name }} {{ participant.last_name }} is currently
              <span class="capitalize">{{ participant.enrollment_status }}</span>.
            </p>
            <p class="text-xs text-green-600 dark:text-green-400 mt-0.5">
              Enrolled since {{ fmtDate(participant.enrollment_date) }}. No disenrollment on record.
            </p>
          </div>
        </div>

        <!-- Disenroll button (only when enrolled, not just active) -->
        <button
          v-if="isEnrolled"
          class="inline-flex items-center gap-2 px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors whitespace-nowrap shrink-0"
          @click="openDisenrollModal"
        >
          <UserMinusIcon class="w-4 h-4" />
          Disenroll Participant
        </button>
      </div>
    </div>

    <!-- Disenrolled / deceased / transferred: record card + re-enroll -->
    <div v-else class="space-y-4">
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-6">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
          <div class="flex items-center gap-2">
            <UserMinusIcon class="w-5 h-5 text-gray-500 dark:text-slate-400" />
            <h3 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Disenrollment Record</h3>
          </div>

          <!-- Re-enroll button (only for disenrolled, not deceased/transferred) -->
          <button
            v-if="participant.enrollment_status === 'disenrolled'"
            class="inline-flex items-center gap-2 px-3 py-1.5 text-sm border border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-950/40 transition-colors"
            @click="showReenrollConfirm = true"
          >
            <UserPlusIcon class="w-4 h-4" />
            Re-enroll
          </button>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <dt class="text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-0.5">Participant</dt>
            <dd class="text-sm font-medium text-gray-900 dark:text-slate-100">
              {{ participant.last_name }}, {{ participant.first_name }}
              <span class="font-mono text-xs text-gray-500 dark:text-slate-400 ml-1">{{ participant.mrn }}</span>
            </dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-0.5">Final Status</dt>
            <dd class="text-sm font-semibold text-gray-900 dark:text-slate-100 capitalize">{{ participant.enrollment_status }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-0.5">Enrollment Date</dt>
            <dd class="text-sm text-gray-900 dark:text-slate-100">{{ fmtDate(participant.enrollment_date) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-0.5">Disenrollment Date</dt>
            <dd class="text-sm text-gray-900 dark:text-slate-100">{{ fmtDate(participant.disenrollment_date) }}</dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-0.5">Reason</dt>
            <dd class="text-sm text-gray-900 dark:text-slate-100">
              {{ participant.disenrollment_reason
                ? (REASON_LABELS[participant.disenrollment_reason] ?? participant.disenrollment_reason.replace(/_/g, ' '))
                : '-' }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-0.5">Site</dt>
            <dd class="text-sm text-gray-900 dark:text-slate-100">{{ participant.site.name }}</dd>
          </div>
        </dl>
      </div>

      <p v-if="reenrollError" class="text-sm text-red-600 dark:text-red-400">{{ reenrollError }}</p>
    </div>

    <!-- ── Disenroll confirmation modal ──────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showDisenrollModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg p-6 space-y-5">

          <!-- Warning header -->
          <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/50 flex items-center justify-center">
              <ExclamationTriangleIcon class="w-5 h-5 text-red-600 dark:text-red-400" />
            </div>
            <div>
              <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Disenroll Participant</h3>
              <p class="text-sm text-gray-600 dark:text-slate-400 mt-0.5">
                This will disenroll <strong>{{ participant.first_name }} {{ participant.last_name }}</strong> ({{ participant.mrn }}).
                A transition plan SDR will be created for Social Work. This action can be reversed.
              </p>
            </div>
          </div>

          <!-- Form -->
          <div class="space-y-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
                Reason <span class="text-red-500">*</span>
              </label>
              <!-- Canonical CMS reasons, grouped by type. See App\Support\DisenrollmentTaxonomy. -->
              <select name="reason" v-model="disenrollForm.reason"
                class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100">
                <template v-for="group in REASON_GROUPS" :key="group.key">
                  <optgroup
                    v-if="disenrollmentReasons[group.key] && Object.keys(disenrollmentReasons[group.key]).length"
                    :label="group.label"
                  >
                    <option
                      v-for="(label, code) in disenrollmentReasons[group.key]"
                      :key="code"
                      :value="code"
                    >
                      {{ label }}
                    </option>
                  </optgroup>
                </template>
              </select>
              <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                Per 42 CFR §460.160-164. Selecting <strong>Death</strong> skips the transition plan (§460.116).
              </p>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
                {{ isDeathReason ? 'Date of Death' : 'Effective Date' }} <span class="text-red-500">*</span>
              </label>
              <input
                v-model="disenrollForm.effective_date"
                type="date"
                class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100"
              />
              <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                <template v-if="isDeathReason">
                  Per 42 CFR §460.160(b): for death, the disenrollment date is the actual date of death (no "1st of month" rule).
                </template>
                <template v-else>
                  Effective date is typically the 1st of the month following the request (42 CFR §460.162(b) / §460.164(c)).
                </template>
              </p>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Notes</label>
              <textarea
                v-model="disenrollForm.notes"
                rows="3"
                placeholder="Additional context (optional)..."
                class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 resize-none"
              />
            </div>

            <label class="flex items-center gap-2 cursor-pointer">
              <input
                v-model="disenrollForm.cms_notification_required"
                type="checkbox"
                class="rounded border-gray-300 dark:border-slate-600 text-blue-600"
              />
              <span class="text-sm text-gray-700 dark:text-slate-300">CMS notification required (HPMS reporting)</span>
            </label>
          </div>

          <p v-if="disenrollError" class="text-xs text-red-600 dark:text-red-400">{{ disenrollError }}</p>

          <div class="flex gap-2 justify-end pt-1">
            <button
              class="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
              @click="showDisenrollModal = false"
            >Cancel</button>
            <button
              :disabled="disenrollSaving"
              class="text-sm px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 transition-colors font-medium"
              @click="submitDisenroll"
            >{{ disenrollSaving ? 'Processing...' : 'Confirm Disenrollment' }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── Re-enroll confirmation modal ─────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showReenrollConfirm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md p-6 space-y-4">
          <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center">
              <UserPlusIcon class="w-5 h-5 text-blue-600 dark:text-blue-400" />
            </div>
            <div>
              <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Re-enroll Participant</h3>
              <p class="text-sm text-gray-600 dark:text-slate-400 mt-0.5">
                This will restore <strong>{{ participant.first_name }} {{ participant.last_name }}</strong> to enrolled status.
                Disenrollment dates and reason will be cleared.
              </p>
            </div>
          </div>

          <div class="flex gap-2 justify-end">
            <button
              class="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
              @click="showReenrollConfirm = false"
            >Cancel</button>
            <button
              :disabled="reenrollSaving"
              class="text-sm px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors font-medium"
              @click="submitReenroll"
            >{{ reenrollSaving ? 'Processing...' : 'Confirm Re-enrollment' }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
