<!--
  IDT Meetings — Paginated list of all IDT meetings with status filter pills.
  Clicking a row navigates to the meeting detail / run-meeting page.
  Filterable by: All, Scheduled, In Progress, Completed.

  Route:   GET /idt/meetings -> Inertia::render('Idt/Meetings')
  Props:   meetings (paginator), filters (status string)
-->
<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ─────────────────────────────────────────────────────────────────────

interface Facilitator {
  id: number
  first_name: string
  last_name: string
}

interface Meeting {
  id: number
  meeting_date: string
  meeting_time: string | null
  meeting_type: string
  status: 'scheduled' | 'in_progress' | 'completed'
  facilitator: Facilitator | null
  site_id: number | null
}

interface PaginatorLink {
  url: string | null
  label: string
  active: boolean
}

interface Paginator<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  links: PaginatorLink[]
}

const props = defineProps<{
  meetings: Paginator<Meeting>
  filters: { status: string }
}>()

// ── Constants ─────────────────────────────────────────────────────────────────

const STATUS_PILL: Record<string, string> = {
  scheduled:   'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
  in_progress: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
  completed:   'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
}

const TYPE_LABELS: Record<string, string> = {
  weekly:    'Weekly IDT',
  monthly:   'Monthly IDT',
  quarterly: 'Quarterly IDT',
  emergency: 'Emergency IDT',
  custom:    'Ad-Hoc Meeting',
}

// ── Filter state ──────────────────────────────────────────────────────────────

const statusFilter = ref(props.filters.status ?? '')

function applyFilter(status: string) {
  statusFilter.value = status
  router.get('/idt/meetings', { status: status || undefined }, { preserveState: true, replace: true })
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatDate(dateStr: string): string {
  return new Date(dateStr + 'T00:00:00').toLocaleDateString('en-US', {
    weekday: 'short',
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

function formatTime(timeStr: string | null): string {
  if (!timeStr) return '-'
  return new Date(`2000-01-01T${timeStr}`).toLocaleTimeString('en-US', {
    hour: 'numeric',
    minute: '2-digit',
  })
}

function statusLabel(status: string): string {
  return status.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())
}
</script>

<template>
  <AppShell>
    <Head title="Meeting Minutes" />

    <!-- Breadcrumb -->
    <div class="px-6 pt-4 pb-0 text-sm text-gray-500 dark:text-slate-400">
      <Link href="/idt" class="hover:text-blue-600 dark:hover:text-blue-400">IDT</Link>
      <span class="mx-2">/</span>
      <span class="text-gray-900 dark:text-slate-100">Meeting Minutes</span>
    </div>

    <div class="px-6 py-6 space-y-5">

      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-xl font-bold text-gray-900 dark:text-slate-100">IDT Meeting Minutes</h1>
          <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
            {{ props.meetings.total }} meeting{{ props.meetings.total !== 1 ? 's' : '' }} on record
          </p>
        </div>
        <Link
          href="/idt"
          class="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
        >
          IDT Dashboard
        </Link>
      </div>

      <!-- Status filter pills -->
      <div class="flex gap-2 flex-wrap">
        <button
          v-for="opt in [
            { value: '', label: 'All' },
            { value: 'scheduled', label: 'Scheduled' },
            { value: 'in_progress', label: 'In Progress' },
            { value: 'completed', label: 'Completed' },
          ]"
          :key="opt.value"
          :class="[
            'text-xs px-3 py-1.5 rounded-full border font-medium transition-colors',
            statusFilter === opt.value
              ? 'bg-blue-600 text-white border-blue-600'
              : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400 dark:hover:border-blue-500',
          ]"
          @click="applyFilter(opt.value)"
        >
          {{ opt.label }}
        </button>
      </div>

      <!-- Meetings table -->
      <div class="border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden bg-white dark:bg-slate-800 shadow-sm">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 dark:bg-slate-700/50">
            <tr>
              <th
                v-for="h in ['Date', 'Time', 'Type', 'Facilitator', 'Status']"
                :key="h"
                class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
              >
                {{ h }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr v-if="props.meetings.data.length === 0">
              <td colspan="5" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                No meetings found{{ statusFilter ? ` with status "${statusFilter}"` : '' }}.
              </td>
            </tr>
            <tr
              v-for="meeting in props.meetings.data"
              :key="meeting.id"
              class="bg-white dark:bg-slate-800 hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors"
              @click="router.visit(`/idt/meetings/${meeting.id}`)"
            >
              <td class="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">
                {{ formatDate(meeting.meeting_date) }}
              </td>
              <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                {{ formatTime(meeting.meeting_time) }}
              </td>
              <td class="px-4 py-3 text-gray-700 dark:text-slate-300">
                {{ TYPE_LABELS[meeting.meeting_type] ?? meeting.meeting_type }}
              </td>
              <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                <span v-if="meeting.facilitator">
                  {{ meeting.facilitator.last_name }}, {{ meeting.facilitator.first_name }}
                </span>
                <span v-else class="text-gray-400 dark:text-slate-500">Unassigned</span>
              </td>
              <td class="px-4 py-3">
                <span
                  :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium', STATUS_PILL[meeting.status] ?? '']"
                >
                  {{ statusLabel(meeting.status) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="props.meetings.last_page > 1" class="flex justify-center gap-1">
        <button
          v-for="(link, i) in props.meetings.links"
          :key="i"
          :disabled="!link.url"
          :class="[
            'px-3 py-1.5 text-xs rounded border transition-colors',
            link.active
              ? 'bg-blue-600 text-white border-blue-600'
              : link.url
                ? 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400'
                : 'bg-white dark:bg-slate-800 text-gray-300 dark:text-slate-600 border-gray-200 dark:border-slate-700 cursor-not-allowed',
          ]"
          @click="link.url && router.visit(link.url, { preserveState: true })"
          v-html="link.label"
        />
      </div>

    </div>
  </AppShell>
</template>
