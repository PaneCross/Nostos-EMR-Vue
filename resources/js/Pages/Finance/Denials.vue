<template>
  <AppShell>
    <Head title="Denials" />

    <div class="p-6 space-y-6">
      <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Denials</h1>
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">
          {{ denials.total }} total
        </span>
      </div>

      <!-- Filter bar -->
      <div class="flex items-center gap-3">
        <select name="statusFilter"
          v-model="statusFilter"
          @change="applyFilter"
          class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <option value="">All Statuses</option>
          <option value="open">Open</option>
          <option value="appealed">Appealed</option>
          <option value="resolved">Resolved</option>
          <option value="written_off">Written Off</option>
        </select>
      </div>

      <!-- Table -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table v-if="denials.data.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Participant</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Code</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Reason</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Amount</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Service Date</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            <tr
              v-for="denial in denials.data"
              :key="denial.id"
              class="hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer"
              @click="denial.participant && navigateToParticipant(denial.participant.id)"
            >
              <td class="px-6 py-4 text-sm">
                <span v-if="denial.participant" class="text-indigo-600 dark:text-indigo-400 font-medium">
                  {{ denial.participant.first_name }} {{ denial.participant.last_name }}
                  <span class="text-gray-400 font-normal ml-1">({{ denial.participant.mrn }})</span>
                </span>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="px-6 py-4 text-sm font-mono text-gray-700 dark:text-gray-300">
                {{ denial.denial_code ?? '-' }}
              </td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300 max-w-xs truncate">
                {{ denial.denial_reason ?? '-' }}
              </td>
              <td class="px-6 py-4 text-sm text-gray-900 dark:text-white font-medium">
                ${{ denial.amount.toLocaleString() }}
              </td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                {{ denial.service_date ?? '-' }}
              </td>
              <td class="px-6 py-4">
                <span :class="statusClass(denial.status)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize">
                  {{ denial.status.replace('_', ' ') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>

        <div v-else class="flex flex-col items-center justify-center py-16 text-gray-500 dark:text-gray-400">
          <DocumentMagnifyingGlassIcon class="w-10 h-10 mb-3 text-gray-300 dark:text-gray-600" />
          <p class="text-sm">No denials found.</p>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="denials.last_page > 1" class="flex items-center justify-between">
        <p class="text-sm text-gray-600 dark:text-gray-400">
          Page {{ denials.current_page }} of {{ denials.last_page }}
        </p>
        <div class="flex gap-2">
          <button
            @click="changePage(denials.current_page - 1)"
            :disabled="denials.current_page <= 1"
            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <ChevronLeftIcon class="w-4 h-4" />
          </button>
          <button
            @click="changePage(denials.current_page + 1)"
            :disabled="denials.current_page >= denials.last_page"
            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <ChevronRightIcon class="w-4 h-4" />
          </button>
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
import {
  ChevronLeftIcon,
  ChevronRightIcon,
  DocumentMagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'

interface Denial {
  id: number
  participant: { id: number; mrn: string; first_name: string; last_name: string } | null
  denial_reason: string | null
  denial_code: string | null
  amount: number
  service_date: string | null
  status: 'open' | 'appealed' | 'resolved' | 'written_off'
  created_at: string
}

interface Paginator {
  data: Denial[]
  current_page: number
  last_page: number
  total: number
}

const props = defineProps<{
  denials: Paginator
  filters: { status?: string }
}>()

const statusFilter = ref(props.filters?.status ?? '')

function applyFilter() {
  router.get('/billing/denials', { status: statusFilter.value || undefined }, { preserveState: true })
}

function changePage(page: number) {
  router.get('/billing/denials', { status: statusFilter.value || undefined, page }, { preserveState: true })
}

function navigateToParticipant(id: number) {
  router.visit(`/participants/${id}`)
}

function statusClass(status: string): string {
  if (status === 'open') return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
  if (status === 'appealed') return 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'
  if (status === 'resolved') return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
  return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
}
</script>
