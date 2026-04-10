<script setup lang="ts">
import { ref, computed } from 'vue'
import { ShieldCheckIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

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

const search = ref('')
const filterAction = ref('')

const ACTION_COLORS: Record<string, string> = {
  'participant.profile.viewed': 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
  'participant.updated':        'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
  'participant.created':        'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
  'participant.deleted':        'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
  'note.created':               'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300',
  'break_glass.activated':      'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
}

// Derive unique action category prefixes from the log for the filter dropdown
const actionCategories = computed(() => {
  const cats = new Set<string>()
  for (const e of (props.auditLogs ?? [])) {
    const prefix = e.action.split('.')[0]
    if (prefix) cats.add(prefix)
  }
  return [...cats].sort()
})

const filteredLogs = computed(() => {
  let list = props.auditLogs ?? []
  if (filterAction.value) {
    list = list.filter(e => e.action.startsWith(filterAction.value + '.') || e.action === filterAction.value)
  }
  if (search.value.trim()) {
    const q = search.value.trim().toLowerCase()
    list = list.filter(e =>
      e.action.toLowerCase().includes(q) ||
      (e.description ?? '').toLowerCase().includes(q) ||
      (e.user ? `${e.user.first_name} ${e.user.last_name}`.toLowerCase().includes(q) : false)
    )
  }
  return list
})

function fmtDateTime(val: string): string {
  return new Date(val).toLocaleString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit',
  })
}

function actionLabel(action: string): string {
  return action.split('.').map(s => s.replace(/_/g, ' ')).join(' / ')
}

function categoryLabel(cat: string): string {
  return cat.charAt(0).toUpperCase() + cat.slice(1).replace(/_/g, ' ')
}
</script>

<template>
  <div class="p-6">
    <div class="max-w-5xl mx-auto space-y-4">
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

      <template v-else>
        <!-- Search + filter bar -->
        <div class="flex items-center gap-2 flex-wrap">
          <div class="relative flex-1 min-w-[200px]">
            <MagnifyingGlassIcon class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 dark:text-slate-500 pointer-events-none" />
            <input
              v-model="search"
              type="search"
              placeholder="Search actions, descriptions, users..."
              class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-200 placeholder-gray-400 dark:placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
          <select name="filterAction"
            v-model="filterAction"
            class="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-2.5 py-1.5 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-200"
          >
            <option value="">All categories</option>
            <option v-for="cat in actionCategories" :key="cat" :value="cat">{{ categoryLabel(cat) }}</option>
          </select>
          <button
            v-if="search || filterAction"
            class="text-xs text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 px-2 py-1.5"
            @click="search = ''; filterAction = ''"
          >
            Clear
          </button>
        </div>

        <!-- Empty state -->
        <div
          v-if="filteredLogs.length === 0"
          class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-12 text-center"
        >
          <ShieldCheckIcon class="w-8 h-8 mx-auto text-gray-300 dark:text-slate-600 mb-2" />
          <p class="text-sm text-gray-500 dark:text-slate-400">
            {{ (search || filterAction) ? 'No entries match your search.' : 'No audit entries found.' }}
          </p>
        </div>

        <!-- Log table -->
        <div v-else class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
          <div class="px-4 py-2 border-b border-gray-100 dark:border-slate-700 text-xs text-gray-400 dark:text-slate-500">
            {{ filteredLogs.length }} {{ filteredLogs.length === 1 ? 'entry' : 'entries' }}
          </div>
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
              <tr v-for="entry in filteredLogs" :key="entry.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
                <td class="px-4 py-3 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">{{ fmtDateTime(entry.created_at) }}</td>
                <td class="px-4 py-3">
                  <span :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium', ACTION_COLORS[entry.action] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300']">
                    {{ actionLabel(entry.action) }}
                  </span>
                </td>
                <td class="px-4 py-3 text-gray-600 dark:text-slate-400 text-xs">
                  <span v-if="entry.user">{{ entry.user.first_name }} {{ entry.user.last_name }}</span>
                  <span v-else class="text-gray-400 dark:text-slate-500">System</span>
                </td>
                <td class="px-4 py-3 text-gray-600 dark:text-slate-400 text-xs">{{ entry.description ?? '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </div>
</template>
