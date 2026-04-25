<script setup lang="ts">
// ─── Compliance / CMS Audit Universes — Phase R11 ──────────────────────────
// CMS PACE Audit Protocol 2.0 universe-pull workspace.
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface UniverseStatus {
  universe: string
  attempts_used: number
  max_attempts: number
  last_passed: boolean | null
  last_attempt_at: string | null
  last_row_count: number | null
}
interface Props {
  audit_id: string
  universes: Record<string, UniverseStatus>
  honest_label: string
}
const props = defineProps<Props>()

const auditId = ref(props.audit_id)

const LABELS: Record<string, string> = {
  sdr: 'Service Determination Requests',
  grievances: 'Grievances',
  disenrollments: 'Disenrollments',
  appeals: 'Appeals',
}

function downloadUrl(universe: string): string {
  return `/compliance/cms-audit-universes/${universe}.csv?audit_id=${auditId.value}`
}
</script>

<template>
  <AppShell>
    <Head title="CMS Audit Universes" />
    <div class="max-w-5xl mx-auto px-6 py-8">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">CMS PACE Audit Universes</h1>
      <p class="text-sm text-gray-600 dark:text-slate-400 mt-1">{{ honest_label }}</p>

      <div class="mt-4">
        <label class="block text-xs font-medium text-gray-500 dark:text-slate-400">Audit ID</label>
        <input v-model="auditId" type="text"
               class="border rounded px-2 py-1 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100" />
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
        <div v-for="(status, key) in universes" :key="key"
             class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 shadow-sm">
          <div class="flex justify-between items-start">
            <div>
              <div class="font-semibold text-gray-900 dark:text-slate-100">{{ LABELS[key] || key }}</div>
              <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                Attempts: <strong :class="status.attempts_used >= status.max_attempts
                                          ? 'text-red-700 dark:text-red-400' : ''">
                  {{ status.attempts_used }}/{{ status.max_attempts }}
                </strong>
                <span v-if="status.last_passed === true"
                      class="ml-2 px-2 py-0.5 rounded-full text-xs bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">
                  passed
                </span>
                <span v-else-if="status.last_passed === false"
                      class="ml-2 px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                  validation errors
                </span>
              </div>
            </div>
            <a v-if="status.attempts_used < status.max_attempts" :href="downloadUrl(key as string)"
               class="text-sm px-3 py-1.5 border border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20">
              Generate
            </a>
            <span v-else class="text-xs text-red-700 dark:text-red-400">Limit reached</span>
          </div>
          <div v-if="status.last_attempt_at" class="text-xs text-gray-500 dark:text-slate-400 mt-2">
            Last: {{ status.last_attempt_at }} · {{ status.last_row_count }} rows
          </div>
        </div>
      </div>
    </div>
  </AppShell>
</template>
