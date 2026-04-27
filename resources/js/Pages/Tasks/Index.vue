<script setup lang="ts">
// ─── Tasks/Index.vue ────────────────────────────────────────────────────────
// Phase I5: staff task queue. 3-tab view (Mine / My Dept / All) with filter
// pills, overdue highlighting, quick Start/Complete/Cancel actions, and
// navigation back to the related resource if polymorphic.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, router, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

interface Participant { id: number; mrn: string; first_name: string; last_name: string }
interface AssignedUser { id: number; first_name: string; last_name: string; department: string | null }

interface Task {
  id: number
  title: string
  description: string | null
  priority: 'low' | 'normal' | 'high' | 'urgent'
  status: 'pending' | 'in_progress' | 'completed' | 'cancelled'
  due_at: string | null
  completed_at: string | null
  completion_note: string | null
  assigned_to_user_id: number | null
  assigned_to_department: string | null
  participant_id: number | null
  related_to_type: string | null
  related_to_id: number | null
  participant?: Participant | null
  assigned_user?: AssignedUser | null
}

const props = defineProps<{
  tasks: Task[]
  overdue_count: number
  view: 'mine' | 'my_department' | 'all'
  current_user_department: string
}>()

const VIEWS = [
  { key: 'mine',          label: 'Mine' },
  { key: 'my_department', label: 'My department' },
  { key: 'all',           label: 'All' },
] as const

const priorityFilter = ref<'all'|'low'|'normal'|'high'|'urgent'>('all')
const statusFilter = ref<'open'|'completed'|'all'>('open')

const PRIORITY_CLASS: Record<string, string> = {
  urgent: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800',
  high:   'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300 border-orange-200 dark:border-orange-800',
  normal: 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-600',
  low:    'bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border-slate-200 dark:border-slate-700',
}

const STATUS_CLASS: Record<string, string> = {
  pending:     'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
  in_progress: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
  completed:   'bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300',
  cancelled:   'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
}

function changeView(v: typeof props.view) {
  router.visit('/tasks?view=' + v, { preserveScroll: true })
}

const filteredTasks = computed(() => {
  return props.tasks.filter(t => {
    if (priorityFilter.value !== 'all' && t.priority !== priorityFilter.value) return false
    if (statusFilter.value === 'open' && !['pending', 'in_progress'].includes(t.status)) return false
    if (statusFilter.value === 'completed' && t.status !== 'completed') return false
    return true
  })
})

function isOverdue(t: Task): boolean {
  if (!['pending', 'in_progress'].includes(t.status)) return false
  if (!t.due_at) return false
  return new Date(t.due_at).getTime() < Date.now()
}

function fmt(dt: string | null): string {
  if (!dt) return '-'
  return new Date(dt).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

// ── Actions ───────────────────────────────────────────────────────────────
const actingOn = ref<number | null>(null)

async function completeTask(t: Task) {
  const note = window.prompt('Completion note (optional):', '')
  if (note === null) return // cancel
  actingOn.value = t.id
  try {
    await axios.post(`/tasks/${t.id}/complete`, { completion_note: note })
    router.reload({ only: ['tasks', 'overdue_count'] })
  } catch {
    // noop
  } finally {
    actingOn.value = null
  }
}
async function cancelTask(t: Task) {
  if (!window.confirm(`Cancel task "${t.title}"?`)) return
  actingOn.value = t.id
  try {
    await axios.post(`/tasks/${t.id}/cancel`)
    router.reload({ only: ['tasks', 'overdue_count'] })
  } catch {
    // noop
  } finally {
    actingOn.value = null
  }
}

function relatedHref(t: Task): string | null {
  if (t.participant_id) return `/participants/${t.participant_id}`
  if (!t.related_to_type || !t.related_to_id) return null
  const map: Record<string, string> = {
    grievance: `/grievances/${t.related_to_id}`,
    incident:  `/qa/incidents/${t.related_to_id}`,
    appeal:    `/appeals/${t.related_to_id}`,
    sdr:       `/sdrs/${t.related_to_id}`,
    care_gap:  `/participants/${t.related_to_id}/care-gaps`,
  }
  return map[t.related_to_type] ?? null
}
</script>

<template>
  <AppShell title="Tasks">
    <Head title="Tasks" />
    <div class="max-w-7xl mx-auto p-6 space-y-4">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Staff tasks</h1>
          <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ props.tasks.length }} visible · {{ props.overdue_count }} overdue
          </p>
        </div>
      </div>

      <!-- View tabs -->
      <div class="flex gap-1">
        <button
          v-for="v in VIEWS"
          :key="v.key"
          @click="changeView(v.key)"
          :class="[
            'text-xs px-3 py-1.5 rounded border',
            props.view === v.key
              ? 'bg-blue-600 text-white border-blue-600'
              : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-blue-300',
          ]"
        >{{ v.label }}</button>
      </div>

      <!-- Filter pills -->
      <div class="flex flex-wrap gap-2 items-center text-xs">
        <span class="text-slate-500 dark:text-slate-400">Priority:</span>
        <button
          v-for="p in (['all','urgent','high','normal','low'] as const)"
          :key="p"
          @click="priorityFilter = p"
          :class="[
            'px-2 py-0.5 rounded-full border capitalize',
            priorityFilter === p ? 'bg-blue-600 text-white border-blue-600' : 'border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:border-blue-300',
          ]"
        >{{ p }}</button>
        <span class="ml-3 text-slate-500 dark:text-slate-400">Status:</span>
        <button
          v-for="s in (['open','completed','all'] as const)"
          :key="s"
          @click="statusFilter = s"
          :class="[
            'px-2 py-0.5 rounded-full border capitalize',
            statusFilter === s ? 'bg-blue-600 text-white border-blue-600' : 'border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:border-blue-300',
          ]"
        >{{ s }}</button>
      </div>

      <!-- Task table -->
      <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase text-slate-500 dark:text-slate-400">
            <tr>
              <th class="px-3 py-2 text-left">Priority</th>
              <th class="px-3 py-2 text-left">Title</th>
              <th class="px-3 py-2 text-left">Due</th>
              <th class="px-3 py-2 text-left">Assignee</th>
              <th class="px-3 py-2 text-left">Related</th>
              <th class="px-3 py-2 text-left">Status</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="filteredTasks.length === 0">
              <td colspan="7" class="px-3 py-8 text-center text-slate-400">No tasks match.</td>
            </tr>
            <tr
              v-for="t in filteredTasks"
              :key="t.id"
              :class="['border-t border-slate-100 dark:border-slate-700', isOverdue(t) ? 'bg-red-50/60 dark:bg-red-950/20' : '']"
            >
              <td class="px-3 py-2">
                <span :class="['inline-block text-xs px-2 py-0.5 rounded-full border capitalize', PRIORITY_CLASS[t.priority]]">
                  {{ t.priority }}
                </span>
              </td>
              <td class="px-3 py-2">
                <div class="font-medium text-slate-900 dark:text-slate-100">{{ t.title }}</div>
                <div v-if="t.description" class="text-xs text-slate-500 dark:text-slate-400 line-clamp-2">{{ t.description }}</div>
              </td>
              <td class="px-3 py-2 text-slate-600 dark:text-slate-300">
                {{ fmt(t.due_at) }}
                <span v-if="isOverdue(t)" class="ml-1 inline-flex px-1.5 py-0.5 rounded text-xs bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300">OVERDUE</span>
              </td>
              <td class="px-3 py-2 text-slate-600 dark:text-slate-300">
                <template v-if="t.assigned_user">
                  {{ t.assigned_user.first_name }} {{ t.assigned_user.last_name }}
                  <div class="text-xs text-slate-500">{{ t.assigned_user.department }}</div>
                </template>
                <span v-else-if="t.assigned_to_department" class="text-xs">Dept: {{ t.assigned_to_department }}</span>
                <span v-else class="text-slate-400">-</span>
              </td>
              <td class="px-3 py-2">
                <Link v-if="relatedHref(t)" :href="relatedHref(t)!" class="text-blue-600 dark:text-blue-400 hover:underline text-xs">
                  <template v-if="t.participant">{{ t.participant.first_name }} {{ t.participant.last_name }}</template>
                  <template v-else-if="t.related_to_type">{{ t.related_to_type }} #{{ t.related_to_id }}</template>
                  <template v-else>link</template>
                </Link>
                <span v-else class="text-slate-400">-</span>
              </td>
              <td class="px-3 py-2">
                <span :class="['inline-block text-xs px-2 py-0.5 rounded-full', STATUS_CLASS[t.status]]">
                  {{ t.status.replace('_', ' ') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right">
                <div v-if="['pending','in_progress'].includes(t.status)" class="inline-flex gap-1">
                  <button
                    class="text-xs px-2 py-1 border border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-400 rounded hover:bg-emerald-50 dark:hover:bg-emerald-900/20 disabled:opacity-50"
                    :disabled="actingOn === t.id"
                    @click="completeTask(t)"
                  >Complete</button>
                  <button
                    class="text-xs px-2 py-1 border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded hover:bg-slate-50 dark:hover:bg-slate-700 disabled:opacity-50"
                    :disabled="actingOn === t.id"
                    @click="cancelTask(t)"
                  >Cancel</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
