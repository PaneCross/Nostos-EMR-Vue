<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ClipboardDocumentListIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
  participant: { id: number }
}>()

interface Order {
  id: number
  ordered_at: string
  order_type: string
  order_type_label: string
  instructions: string | null
  clinical_indication: string | null
  status: string
  ordered_by: { id: number; name: string } | null
  priority: string | null
  target_department: string | null
  due_date: string | null
  is_overdue: boolean
}

const orders = ref<Order[]>([])
const loading = ref(true)
const error = ref('')

const STATUS_COLORS: Record<string, string> = {
  pending:     'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
  acknowledged:'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
  resulted:    'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300',
  completed:   'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
  cancelled:   'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
}

const PRIORITY_COLORS: Record<string, string> = {
  stat:    'text-red-600 dark:text-red-400 font-semibold',
  urgent:  'text-orange-600 dark:text-orange-400',
  routine: 'text-gray-500 dark:text-slate-400',
}

onMounted(async () => {
  try {
    const res = await axios.get(`/participants/${props.participant.id}/orders`)
    orders.value = res.data.orders ?? []
  } catch {
    error.value = 'Unable to load orders.'
  } finally {
    loading.value = false
  }
})

function fmtDate(val: string | null | undefined): string {
  if (!val) return '-'
  return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}
</script>

<template>
  <div class="p-6 max-w-5xl space-y-4">
    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Orders</h2>

    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <div v-else-if="error" class="rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-300">
      {{ error }}
    </div>

    <div v-else-if="orders.length === 0"
      class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-12 text-center"
    >
      <ClipboardDocumentListIcon class="w-8 h-8 mx-auto text-gray-300 dark:text-slate-600 mb-2" />
      <p class="text-sm text-gray-500 dark:text-slate-400">No orders on file.</p>
    </div>

    <div v-else class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-slate-700/50">
          <tr>
            <th
              v-for="h in ['Date', 'Type', 'Instructions', 'Priority', 'Ordered By', 'Status']"
              :key="h"
              class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
            >{{ h }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <tr
            v-for="order in orders"
            :key="order.id"
            :class="['hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors', order.is_overdue ? 'bg-red-50/50 dark:bg-red-950/10' : '']"
          >
            <td class="px-4 py-3 text-gray-700 dark:text-slate-300 whitespace-nowrap text-xs">
              {{ fmtDate(order.ordered_at) }}
              <div v-if="order.due_date" :class="['text-xs mt-0.5', order.is_overdue ? 'text-red-500 dark:text-red-400 font-medium' : 'text-gray-400 dark:text-slate-500']">
                Due: {{ fmtDate(order.due_date) }}{{ order.is_overdue ? ' (overdue)' : '' }}
              </div>
            </td>
            <td class="px-4 py-3 text-gray-700 dark:text-slate-300">{{ order.order_type_label || order.order_type.replace(/_/g, ' ') }}</td>
            <td class="px-4 py-3 text-gray-800 dark:text-slate-200 max-w-xs">
              <div class="truncate">{{ order.instructions || '-' }}</div>
              <div v-if="order.clinical_indication" class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 truncate">{{ order.clinical_indication }}</div>
            </td>
            <td class="px-4 py-3 capitalize">
              <span v-if="order.priority" :class="['text-xs', PRIORITY_COLORS[order.priority] ?? '']">{{ order.priority }}</span>
              <span v-else class="text-gray-400 dark:text-slate-500 text-xs">-</span>
            </td>
            <td class="px-4 py-3 text-gray-600 dark:text-slate-400 text-xs whitespace-nowrap">
              {{ order.ordered_by?.name ?? '-' }}
            </td>
            <td class="px-4 py-3">
              <span :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize', STATUS_COLORS[order.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-500']">
                {{ order.status.replace(/_/g, ' ') }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
