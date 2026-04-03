<script setup lang="ts">
// ─── Tabs/AuditTab.vue ────────────────────────────────────────────────────────
// Displays the append-only audit trail for this participant record. Shows all
// actions taken (create, update, sign, delete, etc.) with timestamp and user.
// Data is passed as a prop from Show.vue (pre-loaded via Inertia).
// ─────────────────────────────────────────────────────────────────────────────

interface AuditEntry {
  id: number
  action: string
  description: string | null
  user_id: number | null
  created_at: string
}

defineProps<{
  logs: AuditEntry[]
}>()
</script>

<template>
  <div>
    <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Audit Trail ({{ logs.length }} events)</h3>
    <p v-if="logs.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">No audit events on file.</p>
    <div class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
      <table class="text-sm w-full">
        <thead class="bg-gray-50 dark:bg-slate-700/50">
          <tr>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Timestamp</th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Action</th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Description</th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">User</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <tr v-for="entry in logs" :key="entry.id" class="bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700/50">
            <td class="px-4 py-2 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">
              {{ new Date(entry.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) }}
            </td>
            <td class="px-4 py-2 text-xs font-mono text-slate-600 dark:text-slate-300">{{ entry.action }}</td>
            <td class="px-4 py-2 text-xs text-gray-700 dark:text-slate-300 max-w-md">{{ entry.description ?? '-' }}</td>
            <td class="px-4 py-2 text-xs text-gray-500 dark:text-slate-400">{{ entry.user_id ?? 'System' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
