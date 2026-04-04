<script setup lang="ts">
import { computed } from 'vue'
import { ShieldCheckIcon } from '@heroicons/vue/24/outline'

interface AuditEntry {
  id: number
  action: string
  description: string | null
  created_at: string
  user: { first_name: string; last_name: string } | null
}

const props = defineProps<{
  participant: { id: number; first_name: string; last_name: string }
  auditLogs?: AuditEntry[]
  canViewAudit?: boolean
}>()

const logs = computed(() => props.auditLogs ?? [])

const ACTION_COLORS: Record<string, string> = {
  'participant.profile.viewed': 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
  'participant.updated':        'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
  'participant.created':        'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
  'participant.deleted':        'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
  'note.created':               'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300',
  'break_glass.activated':      'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
}

function fmtDateTime(val: string): string {
  return new Date(val).toLocaleString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit',
  })
}

function actionLabel(action: string): string {
  return action.split('.').map(s => s.replace(/_/g, ' ')).join(' / ')
}
</script>

<template>
  <div class="p-6 max-w-4xl space-y-4">
    <div class="flex items-center gap-2">
      <ShieldCheckIcon class="w-5 h-5 text-gray-500 dark:text-slate-400" />
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Audit Trail</h2>
    </div>

    <!-- Access restricted -->
    <div
      v-if="!canViewAudit"
      class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-6 py-8 text-center"
    >
      <ShieldCheckIcon class="w-8 h-8 mx-auto text-amber-400 mb-2" />
      <p class="text-sm font-medium text-amber-700 dark:text-amber-300">Restricted</p>
      <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">Audit trail access is limited to IT Admin and QA Compliance departments.</p>
    </div>

    <!-- Empty -->
    <div
      v-else-if="logs.length === 0"
      class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-12 text-center"
    >
      <ShieldCheckIcon class="w-8 h-8 mx-auto text-gray-300 dark:text-slate-600 mb-2" />
      <p class="text-sm text-gray-500 dark:text-slate-400">No audit entries found.</p>
    </div>

    <!-- Log table -->
    <div v-else class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-slate-700/50">
          <tr>
            <th
              v-for="h in ['Timestamp', 'Action', 'User', 'Description']"
              :key="h"
              class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
            >{{ h }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <tr v-for="entry in logs" :key="entry.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
            <td class="px-4 py-3 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">{{ fmtDateTime(entry.created_at) }}</td>
            <td class="px-4 py-3">
              <span :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium', ACTION_COLORS[entry.action] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300']">
                {{ actionLabel(entry.action) }}
              </span>
            </td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
              <span v-if="entry.user">{{ entry.user.first_name }} {{ entry.user.last_name }}</span>
              <span v-else class="text-gray-400 dark:text-slate-500">System</span>
            </td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 text-xs">{{ entry.description ?? '-' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
