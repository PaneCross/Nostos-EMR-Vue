<script setup lang="ts">
import { computed } from 'vue'
import { UserMinusIcon } from '@heroicons/vue/24/outline'

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

const props = defineProps<{
  participant: Participant
}>()

const isDisenrolled = computed(() =>
  ['disenrolled', 'deceased', 'transferred'].includes(props.participant.enrollment_status)
)

const REASON_LABELS: Record<string, string> = {
  voluntary:              'Voluntary Disenrollment',
  moved_out_of_area:      'Moved Out of Service Area',
  nursing_facility:       'Nursing Facility Admission',
  hospitalization:        'Extended Hospitalization',
  deceased:               'Deceased',
  transferred:            'Transferred to Another PACE',
  non_compliance:         'Non-Compliance',
  medicaid_ineligibility: 'Loss of Medicaid Eligibility',
  medicare_ineligibility: 'Loss of Medicare Eligibility',
  other:                  'Other',
}

function fmtDate(val: string | null): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}
</script>

<template>
  <div class="p-6 max-w-3xl space-y-6">
    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Disenrollment</h2>

    <!-- Not yet disenrolled -->
    <div
      v-if="!isDisenrolled"
      class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950/40 px-6 py-8 text-center"
    >
      <UserMinusIcon class="w-8 h-8 mx-auto text-green-400 dark:text-green-600 mb-2" />
      <p class="text-sm font-medium text-green-700 dark:text-green-300">
        {{ participant.first_name }} {{ participant.last_name }} is currently
        <span class="capitalize font-semibold">{{ participant.enrollment_status }}</span>.
      </p>
      <p class="text-xs text-green-600 dark:text-green-400 mt-1">No disenrollment on record.</p>
    </div>

    <!-- Disenrollment summary -->
    <div
      v-else
      class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-6"
    >
      <div class="flex items-center gap-2 mb-4">
        <UserMinusIcon class="w-5 h-5 text-gray-500 dark:text-slate-400" />
        <h3 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Disenrollment Record</h3>
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
  </div>
</template>
