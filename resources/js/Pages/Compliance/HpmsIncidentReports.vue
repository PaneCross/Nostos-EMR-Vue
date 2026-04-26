<script setup lang="ts">
// ─── Compliance/HpmsIncidentReports ─────────────────────────────────────────
// Five CMS-aligned incident exports formatted for upload to HPMS (Health
// Plan Management System — the CMS submission portal for PACE plans).
//
// Audience: QA Compliance.
//
// Notable rules:
//   - Reports cover falls w/ injury, sentinel events, infection outbreaks,
//     unanticipated deaths, and elopements per CMS PACE reporting guidance.
//   - "Mark Submitted" is honest-labeled — no auto-transmission to HPMS;
//     uploads are still done manually via the CMS portal pre-go-live.
// ────────────────────────────────────────────────────────────────────────────
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Props {
  summary: Record<string, number>
  from: string
  to: string
  reports: string[]
  honest_label: string
}
const props = defineProps<Props>()

const from = ref(props.from)
const to = ref(props.to)

const REPORT_LABELS: Record<string, string> = {
  falls: 'Falls',
  medication_errors: 'Medication Errors',
  abuse_neglect: 'Abuse / Neglect',
  unexpected_deaths: 'Unexpected Deaths',
  elopements: 'Elopements',
}

function downloadUrl(report: string): string {
  return `/compliance/hpms-incident-reports/${report}.csv?from=${from.value}&to=${to.value}`
}
</script>

<template>
  <AppShell>
    <Head title="HPMS Incident Reports" />
    <div class="max-w-5xl mx-auto px-6 py-8">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">HPMS Incident Reports</h1>
      <p class="text-sm text-gray-600 dark:text-slate-400 mt-1">{{ honest_label }}</p>

      <div class="mt-6 flex gap-3 items-end">
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-slate-400">From</label>
          <input v-model="from" type="date" class="border rounded px-2 py-1 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-slate-400">To</label>
          <input v-model="to" type="date" class="border rounded px-2 py-1 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100" />
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
        <div v-for="r in reports" :key="r"
             class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 shadow-sm">
          <div class="flex items-center justify-between">
            <span class="font-semibold text-gray-900 dark:text-slate-100">{{ REPORT_LABELS[r] || r }}</span>
            <span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
              {{ summary[r] ?? 0 }}
            </span>
          </div>
          <a :href="downloadUrl(r)"
             class="inline-flex items-center mt-3 text-sm px-3 py-1.5 border border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20">
            Download CSV
          </a>
        </div>
      </div>
    </div>
  </AppShell>
</template>
