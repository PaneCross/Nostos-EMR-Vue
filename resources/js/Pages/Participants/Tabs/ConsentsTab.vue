<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { DocumentCheckIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
  participant: { id: number }
}>()

interface ConsentRecord {
  id: number
  consent_type: string
  status: string
  signed_at: string | null
  expires_at: string | null
  signed_by_name: string | null
  witnessed_by: { first_name: string; last_name: string } | null
  notes: string | null
}

const consents = ref<ConsentRecord[]>([])
const loading = ref(true)
const error = ref('')

const STATUS_COLORS: Record<string, string> = {
  signed:    'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
  pending:   'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
  refused:   'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
  expired:   'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
  revoked:   'bg-orange-100 dark:bg-orange-950/40 text-orange-700 dark:text-orange-300',
}

const CONSENT_LABELS: Record<string, string> = {
  npp:              'Notice of Privacy Practices',
  treatment:        'Consent to Treatment',
  pace_enrollment:  'PACE Enrollment Consent',
  photo_release:    'Photo Release',
  research:         'Research Participation',
  medication:       'Medication Consent',
  other:            'Other',
}

onMounted(async () => {
  try {
    const res = await axios.get(`/participants/${props.participant.id}/consents`)
    consents.value = res.data
  } catch {
    error.value = 'Unable to load consent records.'
  } finally {
    loading.value = false
  }
})

function fmtDate(val: string | null): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}
</script>

<template>
  <div class="p-6 max-w-4xl space-y-4">
    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Consents</h2>

    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <div v-else-if="error" class="rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-300">
      {{ error }}
    </div>

    <div v-else-if="consents.length === 0"
      class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-12 text-center"
    >
      <DocumentCheckIcon class="w-8 h-8 mx-auto text-gray-300 dark:text-slate-600 mb-2" />
      <p class="text-sm text-gray-500 dark:text-slate-400">No consent records on file.</p>
    </div>

    <div v-else class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-slate-700/50">
          <tr>
            <th
              v-for="h in ['Consent Type', 'Status', 'Signed', 'Expires', 'Signed By', 'Witnessed By']"
              :key="h"
              class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
            >{{ h }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <tr v-for="c in consents" :key="c.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
            <td class="px-4 py-3 font-medium text-gray-800 dark:text-slate-200">
              {{ CONSENT_LABELS[c.consent_type] ?? c.consent_type.replace(/_/g, ' ') }}
              <div v-if="c.notes" class="text-xs text-gray-500 dark:text-slate-400 font-normal mt-0.5">{{ c.notes }}</div>
            </td>
            <td class="px-4 py-3">
              <span :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize', STATUS_COLORS[c.status] ?? '']">
                {{ c.status }}
              </span>
            </td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 whitespace-nowrap">{{ fmtDate(c.signed_at) }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 whitespace-nowrap">{{ fmtDate(c.expires_at) }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ c.signed_by_name ?? '-' }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
              <span v-if="c.witnessed_by">{{ c.witnessed_by.first_name }} {{ c.witnessed_by.last_name }}</span>
              <span v-else class="text-gray-400 dark:text-slate-500">-</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
