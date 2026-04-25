<script setup lang="ts">
// ─── Operations/StateMedicaidSubmissions.vue — Phase O11 ────────────────────
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

defineProps<{
  submissions: any[]
  banner: string
}>()
</script>

<template>
  <Head title="State Medicaid Submissions" />
  <AppShell>
    <div class="p-6 space-y-4">
      <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">State Medicaid Submissions</h1>

      <!-- Phase O11 — honest-labeling banner -->
      <div
        class="rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/30 p-4 flex items-start gap-3"
        data-testid="state-medicaid-banner"
      >
        <ExclamationTriangleIcon class="h-5 w-5 text-amber-700 dark:text-amber-300 flex-shrink-0 mt-0.5" />
        <div class="text-sm text-amber-900 dark:text-amber-200">
          {{ banner }}
        </div>
      </div>

      <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs uppercase text-gray-500 dark:text-slate-400">
            <tr>
              <th class="px-3 py-2">State</th>
              <th class="px-3 py-2">Format</th>
              <th class="px-3 py-2">Status</th>
              <th class="px-3 py-2">Created</th>
              <th class="px-3 py-2">Notes</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <tr v-for="s in submissions" :key="s.id">
              <td class="px-3 py-2 font-mono">{{ s.state_code }}</td>
              <td class="px-3 py-2">{{ s.submission_format }}</td>
              <td class="px-3 py-2">
                <span class="inline-block rounded px-2 py-0.5 text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300">
                  {{ s.status }}
                </span>
              </td>
              <td class="px-3 py-2 text-gray-500 dark:text-slate-400">
                {{ String(s.created_at ?? '').slice(0, 16).replace('T', ' ') }}
              </td>
              <td class="px-3 py-2 text-xs text-gray-600 dark:text-slate-400 max-w-md truncate">
                {{ s.response_notes }}
              </td>
            </tr>
            <tr v-if="!submissions?.length">
              <td colspan="5" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-slate-400">
                No submissions staged yet.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
