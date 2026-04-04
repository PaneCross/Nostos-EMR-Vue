<template>
  <AppShell>
    <Head title="HOS-M Surveys" />

    <div class="p-6 space-y-6">
      <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">HOS-M Surveys</h1>
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
          {{ currentYear }}
        </span>
      </div>

      <div class="flex items-center gap-3">
        <div class="relative flex-1 max-w-sm">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            v-model="search"
            type="text"
            placeholder="Search by participant..."
            class="w-full pl-9 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
      </div>

      <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table v-if="filtered.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Participant</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Year</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Baseline Score</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Follow-up Score</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Submitted</th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="survey in filtered" :key="survey.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
              <td class="px-6 py-4 text-sm">
                <a
                  v-if="survey.participant"
                  :href="`/participants/${survey.participant.id}`"
                  class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium"
                >
                  {{ survey.participant.first_name }} {{ survey.participant.last_name }}
                  <span class="text-gray-400 font-normal ml-1">({{ survey.participant.mrn }})</span>
                </a>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ survey.survey_year }}</td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                {{ survey.baseline_score !== null ? survey.baseline_score : '-' }}
              </td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                {{ survey.followup_score !== null ? survey.followup_score : '-' }}
              </td>
              <td class="px-6 py-4">
                <span :class="statusClass(survey.status)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize">
                  {{ survey.status }}
                </span>
              </td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                {{ survey.submitted_at ?? '-' }}
              </td>
            </tr>
          </tbody>
        </table>

        <div v-else class="flex flex-col items-center justify-center py-16 text-gray-500 dark:text-gray-400">
          <ClipboardDocumentListIcon class="w-10 h-10 mb-3 text-gray-300 dark:text-gray-600" />
          <p class="text-sm">No surveys.</p>
        </div>
      </div>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import { MagnifyingGlassIcon, ClipboardDocumentListIcon } from '@heroicons/vue/24/outline'

interface HosMSurvey {
  id: number
  participant: { id: number; mrn: string; first_name: string; last_name: string } | null
  survey_year: number
  baseline_score: number | null
  followup_score: number | null
  status: string
  submitted_at: string | null
}

const props = defineProps<{
  surveys: HosMSurvey[]
  currentYear: number
}>()

const search = ref('')

const filtered = computed(() => {
  const q = search.value.toLowerCase().trim()
  if (!q) return props.surveys
  return props.surveys.filter((s) => {
    if (!s.participant) return false
    const name = `${s.participant.first_name} ${s.participant.last_name}`.toLowerCase()
    return name.includes(q) || s.participant.mrn.toLowerCase().includes(q)
  })
})

function statusClass(status: string): string {
  if (status === 'complete') return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
  if (status === 'submitted') return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'
  return 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'
}
</script>
