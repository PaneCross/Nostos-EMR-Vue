<template>
  <AppShell>
    <Head title="Billing Compliance Checklist" />

    <div class="p-6 space-y-6">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Billing Compliance Checklist</h1>
        <button
          @click="runCheck"
          :disabled="running"
          class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <ArrowPathIcon class="w-4 h-4" :class="{ 'animate-spin': running }" />
          {{ running ? 'Running...' : 'Run Check' }}
        </button>
      </div>

      <!-- Overall Status Banner -->
      <div :class="bannerClass" class="rounded-lg p-4 flex items-center gap-3">
        <component :is="bannerIcon" class="w-5 h-5 flex-shrink-0" />
        <div>
          <p class="font-semibold text-sm">
            Overall Status:
            <span class="capitalize">{{ overallStatus }}</span>
          </p>
          <p v-if="lastChecked" class="text-xs mt-0.5 opacity-75">Last checked: {{ lastChecked }}</p>
          <p v-else class="text-xs mt-0.5 opacity-75">Not yet checked</p>
        </div>
      </div>

      <!-- Checks grouped by category -->
      <div v-if="groupedChecks.length > 0" class="space-y-4">
        <div
          v-for="group in groupedChecks"
          :key="group.category"
          class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden"
        >
          <div class="px-6 py-3 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">{{ group.category }}</h2>
          </div>
          <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            <li
              v-for="check in group.checks"
              :key="check.label"
              class="px-6 py-4 flex items-start gap-4"
            >
              <span :class="checkBadgeClass(check.status)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium flex-shrink-0 mt-0.5">
                <CheckCircleIcon v-if="check.status === 'pass'" class="w-3.5 h-3.5" />
                <ExclamationTriangleIcon v-else-if="check.status === 'warn'" class="w-3.5 h-3.5" />
                <XCircleIcon v-else class="w-3.5 h-3.5" />
                <span class="capitalize">{{ check.status }}</span>
              </span>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ check.label }}</p>
                <p v-if="check.detail" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ check.detail }}</p>
              </div>
              <span v-if="check.checked_at" class="text-xs text-gray-400 flex-shrink-0">{{ check.checked_at }}</span>
            </li>
          </ul>
        </div>
      </div>

      <div v-else class="flex flex-col items-center justify-center py-16 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-lg shadow">
        <ClipboardDocumentCheckIcon class="w-10 h-10 mb-3 text-gray-300 dark:text-gray-600" />
        <p class="text-sm">No compliance checks available. Run a check to begin.</p>
      </div>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
// ─── Finance/ComplianceChecklist ────────────────────────────────────────────
// Billing Compliance Checklist. Runs and displays results of automated
// pre-submission billing-data integrity checks (missing diagnoses, unsigned
// notes, encounters without provider, etc.) so Finance can fix issues before
// the next 837P claim batch goes out.
//
// Data: pulls last check run + per-rule pass/fail rows. Audience: Finance /
// Billing dept. Key actions: "Run Check" button POSTs to re-execute.
//
// Acronyms:
//   837P = X12 EDI format for professional medical claim submission.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
  ArrowPathIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  XCircleIcon,
  ClipboardDocumentCheckIcon,
} from '@heroicons/vue/24/outline'

interface ComplianceCheck {
  category: string
  label: string
  status: 'pass' | 'warn' | 'fail'
  detail: string | null
  checked_at: string | null
}

const props = defineProps<{
  checks: ComplianceCheck[]
  overallStatus: 'pass' | 'warn' | 'fail'
  lastChecked: string | null
}>()

const running = ref(false)

const groupedChecks = computed(() => {
  const map: Record<string, ComplianceCheck[]> = {}
  for (const check of props.checks) {
    if (!map[check.category]) map[check.category] = []
    map[check.category].push(check)
  }
  return Object.entries(map).map(([category, checks]) => ({ category, checks }))
})

const bannerClass = computed(() => {
  if (props.overallStatus === 'pass') return 'bg-green-50 border border-green-200 text-green-800 dark:bg-green-950 dark:border-green-800 dark:text-green-200'
  if (props.overallStatus === 'warn') return 'bg-amber-50 border border-amber-200 text-amber-800 dark:bg-amber-950 dark:border-amber-800 dark:text-amber-200'
  return 'bg-red-50 border border-red-200 text-red-800 dark:bg-red-950 dark:border-red-800 dark:text-red-200'
})

const bannerIcon = computed(() => {
  if (props.overallStatus === 'pass') return CheckCircleIcon
  if (props.overallStatus === 'warn') return ExclamationTriangleIcon
  return XCircleIcon
})

function checkBadgeClass(status: string): string {
  if (status === 'pass') return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
  if (status === 'warn') return 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'
  return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
}

async function runCheck() {
  // Phase U1: checklist data is computed live by the GET /billing/compliance-checklist/data
  // endpoint; there is no separate POST. "Run Check" simply refreshes the data.
  running.value = true
  try {
    await axios.get('/billing/compliance-checklist/data')
    router.reload()
  } finally {
    running.value = false
  }
}
</script>
