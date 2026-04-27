<script setup lang="ts">
// ─── OrdersTab.vue ─────────────────────────────────────────────────────────
// Clinical orders: CPOE (Computerized Provider Order Entry). Lists
// orders for a participant with status lifecycle, expand-to-history,
// and inline action buttons.
//
// Status flow: pending → acknowledged → [resulted] → completed
//                                   └────────────────→ cancelled
//
// Routes: POST /orders (prescriber depts only); /orders/{id}/
// acknowledge, /result, /complete, /cancel.
// ───────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import axios from 'axios'
import {
  ClipboardDocumentListIcon,
  ChevronDownIcon,
  CheckCircleIcon,
  XCircleIcon,
  ClipboardDocumentCheckIcon,
  PlusIcon,
} from '@heroicons/vue/24/outline'

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
  priority: string | null
  target_department: string | null
  due_date: string | null
  is_overdue: boolean
  ordered_by: { id: number; name: string } | null
  acknowledged_at: string | null
  acknowledged_by: { id: number; name: string } | null
  resulted_at: string | null
  result_summary: string | null
  completed_at: string | null
  cancellation_reason: string | null
  created_at: string | null
}

const orders     = ref<Order[]>([])
const loading    = ref(true)
const error      = ref('')
const expandedId = ref<number | null>(null)

// ── New order form ────────────────────────────────────────────────────────────
const showNewOrderForm = ref(false)
const newOrderSaving   = ref(false)
const newOrderError    = ref('')
const newOrderForm = ref({
  order_type:          'lab',
  priority:            'routine',
  instructions:        '',
  clinical_indication: '',
  due_date:            '',
})

const ORDER_TYPE_LABELS: Record<string, string> = {
  lab:               'Laboratory',
  imaging:           'Imaging / Radiology',
  consult:           'Specialist Consult',
  therapy_pt:        'Physical Therapy',
  therapy_ot:        'Occupational Therapy',
  therapy_st:        'Speech Therapy',
  therapy_speech:    'Speech-Language Pathology',
  dme:               'DME / Equipment',
  medication_change: 'Medication Change',
  home_health:       'Home Health',
  hospice_referral:  'Hospice Referral',
  other:             'Other',
}

// ── Cancel modal state ────────────────────────────────────────────────────────
const showCancelModal  = ref(false)
const cancelOrderId    = ref<number | null>(null)
const cancelReason     = ref('')
const cancelSaving     = ref(false)
const cancelError      = ref('')

// ── Result modal state ────────────────────────────────────────────────────────
const showResultModal  = ref(false)
const resultOrderId    = ref<number | null>(null)
const resultSummary    = ref('')
const resultSaving     = ref(false)
const resultError      = ref('')

// ── Action saving state ───────────────────────────────────────────────────────
const actionSaving = ref<Record<number, boolean>>({})

const STATUS_COLORS: Record<string, string> = {
  pending:     'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
  acknowledged:'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
  in_progress: 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300',
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

function fmtDateTime(val: string | null | undefined): string {
  if (!val) return '-'
  return new Date(val).toLocaleString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
  })
}

function isTerminal(order: Order): boolean {
  return ['completed', 'cancelled'].includes(order.status)
}

function toggleExpand(id: number) {
  expandedId.value = expandedId.value === id ? null : id
}

function updateOrder(updated: Order) {
  const idx = orders.value.findIndex(o => o.id === updated.id)
  if (idx >= 0) orders.value[idx] = updated
}

// ── New order submit ──────────────────────────────────────────────────────────
async function submitNewOrder() {
  if (!newOrderForm.value.instructions.trim()) {
    newOrderError.value = 'Instructions are required.'
    return
  }
  newOrderSaving.value = true; newOrderError.value = ''
  try {
    const payload: Record<string, unknown> = {
      order_type:          newOrderForm.value.order_type,
      priority:            newOrderForm.value.priority,
      instructions:        newOrderForm.value.instructions,
      clinical_indication: newOrderForm.value.clinical_indication || null,
      due_date:            newOrderForm.value.due_date || null,
    }
    const res = await axios.post(`/participants/${props.participant.id}/orders`, payload)
    orders.value.unshift(res.data.order)
    showNewOrderForm.value = false
    newOrderForm.value = { order_type: 'lab', priority: 'routine', instructions: '', clinical_indication: '', due_date: '' }
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    const errors = err.response?.data?.errors
    newOrderError.value = errors
      ? Object.values(errors).flat().join(' ')
      : (err.response?.data?.message ?? 'Failed to create order.')
  } finally {
    newOrderSaving.value = false
  }
}

// ── Acknowledge ───────────────────────────────────────────────────────────────
async function acknowledge(order: Order) {
  actionSaving.value[order.id] = true
  try {
    const res = await axios.post(`/participants/${props.participant.id}/orders/${order.id}/acknowledge`)
    updateOrder(res.data.order)
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: string } } }
    alert(err.response?.data?.error ?? 'Failed to acknowledge order.')
  } finally {
    delete actionSaving.value[order.id]
  }
}

// ── Complete ──────────────────────────────────────────────────────────────────
async function complete(order: Order) {
  actionSaving.value[order.id] = true
  try {
    const res = await axios.post(`/participants/${props.participant.id}/orders/${order.id}/complete`)
    updateOrder(res.data.order)
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: string } } }
    alert(err.response?.data?.error ?? 'Failed to complete order.')
  } finally {
    delete actionSaving.value[order.id]
  }
}

// ── Cancel modal ──────────────────────────────────────────────────────────────
function openCancelModal(order: Order) {
  cancelOrderId.value = order.id
  cancelReason.value  = ''
  cancelError.value   = ''
  showCancelModal.value = true
}

async function submitCancel() {
  if (!cancelReason.value.trim()) { cancelError.value = 'Cancellation reason is required.'; return }
  cancelSaving.value = true; cancelError.value = ''
  try {
    const res = await axios.post(
      `/participants/${props.participant.id}/orders/${cancelOrderId.value}/cancel`,
      { cancellation_reason: cancelReason.value },
    )
    updateOrder(res.data.order)
    showCancelModal.value = false
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: string } } }
    cancelError.value = err.response?.data?.error ?? 'Failed to cancel order.'
  } finally {
    cancelSaving.value = false
  }
}

// ── Result modal ──────────────────────────────────────────────────────────────
function openResultModal(order: Order) {
  resultOrderId.value  = order.id
  resultSummary.value  = ''
  resultError.value    = ''
  showResultModal.value = true
}

async function submitResult() {
  if (!resultSummary.value.trim()) { resultError.value = 'Result summary is required.'; return }
  resultSaving.value = true; resultError.value = ''
  try {
    const res = await axios.post(
      `/participants/${props.participant.id}/orders/${resultOrderId.value}/result`,
      { result_summary: resultSummary.value },
    )
    updateOrder(res.data.order)
    showResultModal.value = false
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: string } } }
    resultError.value = err.response?.data?.error ?? 'Failed to record result.'
  } finally {
    resultSaving.value = false
  }
}
</script>

<template>
  <div class="p-6 max-w-5xl space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Orders</h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showNewOrderForm = !showNewOrderForm"
      >
        <PlusIcon class="w-3 h-3" />
        New Order
      </button>
    </div>

    <!-- New order form -->
    <div v-if="showNewOrderForm" class="bg-gray-50 dark:bg-slate-700/50 rounded-xl border border-gray-200 dark:border-slate-600 p-5">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-4">New Clinical Order</h3>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Order Type</label>
          <select name="order_type" v-model="newOrderForm.order_type"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100">
            <option v-for="(label, key) in ORDER_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Priority</label>
          <select name="priority" v-model="newOrderForm.priority"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100">
            <option value="routine">Routine</option>
            <option value="urgent">Urgent</option>
            <option value="stat">STAT</option>
          </select>
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
            Instructions <span class="text-red-500">*</span>
          </label>
          <textarea v-model="newOrderForm.instructions" rows="3"
            placeholder="Describe the order in detail..."
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100 resize-none" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Clinical Indication</label>
          <input v-model="newOrderForm.clinical_indication" type="text" placeholder="Reason / diagnosis (optional)"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Due Date</label>
          <input v-model="newOrderForm.due_date" type="date"
            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" />
        </div>
      </div>
      <p v-if="newOrderError" class="text-xs text-red-600 dark:text-red-400 mb-2">{{ newOrderError }}</p>
      <div class="flex gap-2">
        <button :disabled="newOrderSaving" @click="submitNewOrder"
          class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">
          {{ newOrderSaving ? 'Placing...' : 'Place Order' }}
        </button>
        <button @click="showNewOrderForm = false"
          class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
          Cancel
        </button>
      </div>
    </div>

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

    <!-- Orders list -->
    <div v-else class="space-y-2">
      <div
        v-for="order in orders"
        :key="order.id"
        :class="['rounded-xl border overflow-hidden',
          order.is_overdue
            ? 'border-red-300 dark:border-red-700 bg-red-50/30 dark:bg-red-950/10'
            : 'border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800']"
      >
        <!-- Row header (clickable to expand) -->
        <button
          class="w-full flex items-start gap-3 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors"
          @click="toggleExpand(order.id)"
        >
          <!-- Priority stripe -->
          <div :class="['w-1 self-stretch rounded-full shrink-0',
            order.priority === 'stat' ? 'bg-red-500' :
            order.priority === 'urgent' ? 'bg-orange-400' : 'bg-gray-200 dark:bg-slate-600']" />

          <div class="flex-1 min-w-0 grid grid-cols-12 gap-2 items-start">
            <!-- Date + type -->
            <div class="col-span-3">
              <div class="text-xs text-gray-700 dark:text-slate-300 whitespace-nowrap">{{ fmtDate(order.ordered_at) }}</div>
              <div class="text-xs font-semibold text-gray-900 dark:text-slate-100 mt-0.5">{{ order.order_type_label }}</div>
              <div v-if="order.priority" :class="['text-xs capitalize mt-0.5', PRIORITY_COLORS[order.priority] ?? '']">
                {{ order.priority }}
              </div>
            </div>

            <!-- Instructions -->
            <div class="col-span-5">
              <div class="text-sm text-gray-800 dark:text-slate-200 line-clamp-2">{{ order.instructions || '-' }}</div>
              <div v-if="order.clinical_indication" class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 line-clamp-1">
                {{ order.clinical_indication }}
              </div>
              <div v-if="order.is_overdue" class="text-xs text-red-600 dark:text-red-400 font-medium mt-0.5">Overdue</div>
            </div>

            <!-- Ordered by -->
            <div class="col-span-2">
              <div class="text-xs text-gray-500 dark:text-slate-400">Ordered by</div>
              <div class="text-xs text-gray-800 dark:text-slate-200">{{ order.ordered_by?.name ?? '-' }}</div>
            </div>

            <!-- Status + chevron -->
            <div class="col-span-2 flex items-start justify-between gap-1">
              <span :class="['inline-flex px-2 py-0.5 rounded text-xs font-medium capitalize whitespace-nowrap', STATUS_COLORS[order.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-500']">
                {{ order.status.replace(/_/g, ' ') }}
              </span>
              <ChevronDownIcon :class="['w-4 h-4 text-gray-400 dark:text-slate-500 shrink-0 mt-0.5 transition-transform', expandedId === order.id ? 'rotate-180' : '']" />
            </div>
          </div>
        </button>

        <!-- Expanded detail + actions -->
        <div v-if="expandedId === order.id" class="border-t border-gray-100 dark:border-slate-700 px-4 py-4 space-y-4">

          <!-- Status timeline -->
          <div>
            <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-2">History</p>
            <ol class="relative border-l border-gray-200 dark:border-slate-700 ml-2 space-y-3">
              <!-- Created -->
              <li class="ml-4">
                <div class="absolute -left-1.5 w-3 h-3 rounded-full bg-blue-500 border-2 border-white dark:border-slate-800" />
                <p class="text-xs font-semibold text-gray-800 dark:text-slate-200">Created</p>
                <p class="text-xs text-gray-500 dark:text-slate-400">
                  {{ fmtDateTime(order.ordered_at) }}
                  <span v-if="order.ordered_by"> by {{ order.ordered_by.name }}</span>
                </p>
              </li>
              <!-- Acknowledged -->
              <li v-if="order.acknowledged_at" class="ml-4">
                <div class="absolute -left-1.5 w-3 h-3 rounded-full bg-blue-500 border-2 border-white dark:border-slate-800" />
                <p class="text-xs font-semibold text-gray-800 dark:text-slate-200">Acknowledged</p>
                <p class="text-xs text-gray-500 dark:text-slate-400">
                  {{ fmtDateTime(order.acknowledged_at) }}
                  <span v-if="order.acknowledged_by"> by {{ order.acknowledged_by.name }}</span>
                </p>
              </li>
              <!-- Resulted -->
              <li v-if="order.resulted_at" class="ml-4">
                <div class="absolute -left-1.5 w-3 h-3 rounded-full bg-purple-500 border-2 border-white dark:border-slate-800" />
                <p class="text-xs font-semibold text-gray-800 dark:text-slate-200">Resulted</p>
                <p class="text-xs text-gray-500 dark:text-slate-400">{{ fmtDateTime(order.resulted_at) }}</p>
                <p v-if="order.result_summary" class="text-xs text-gray-700 dark:text-slate-300 mt-0.5 italic">{{ order.result_summary }}</p>
              </li>
              <!-- Completed -->
              <li v-if="order.completed_at" class="ml-4">
                <div class="absolute -left-1.5 w-3 h-3 rounded-full bg-green-500 border-2 border-white dark:border-slate-800" />
                <p class="text-xs font-semibold text-gray-800 dark:text-slate-200">Completed</p>
                <p class="text-xs text-gray-500 dark:text-slate-400">{{ fmtDateTime(order.completed_at) }}</p>
              </li>
              <!-- Cancelled -->
              <li v-if="order.status === 'cancelled'" class="ml-4">
                <div class="absolute -left-1.5 w-3 h-3 rounded-full bg-gray-400 border-2 border-white dark:border-slate-800" />
                <p class="text-xs font-semibold text-gray-500 dark:text-slate-400">Cancelled</p>
                <p v-if="order.cancellation_reason" class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 italic">{{ order.cancellation_reason }}</p>
              </li>
            </ol>
          </div>

          <!-- Due date (if set) -->
          <div v-if="order.due_date">
            <span class="text-xs text-gray-500 dark:text-slate-400">Due: </span>
            <span :class="['text-xs font-medium', order.is_overdue ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-slate-300']">
              {{ fmtDate(order.due_date) }}{{ order.is_overdue ? ' (overdue)' : '' }}
            </span>
          </div>

          <!-- Action buttons -->
          <div v-if="!isTerminal(order)" class="flex flex-wrap gap-2 pt-1 border-t border-gray-100 dark:border-slate-700">
            <!-- Acknowledge (pending only) -->
            <button
              v-if="order.status === 'pending'"
              :disabled="actionSaving[order.id]"
              class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
              @click.stop="acknowledge(order)"
            >
              <ClipboardDocumentCheckIcon class="w-3.5 h-3.5" />
              {{ actionSaving[order.id] ? 'Saving...' : 'Acknowledge' }}
            </button>

            <!-- Record Result (acknowledged or in_progress) -->
            <button
              v-if="['acknowledged', 'in_progress'].includes(order.status)"
              :disabled="actionSaving[order.id]"
              class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors"
              @click.stop="openResultModal(order)"
            >
              <ClipboardDocumentListIcon class="w-3.5 h-3.5" />
              Record Result
            </button>

            <!-- Complete -->
            <button
              :disabled="actionSaving[order.id]"
              class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 transition-colors"
              @click.stop="complete(order)"
            >
              <CheckCircleIcon class="w-3.5 h-3.5" />
              {{ actionSaving[order.id] ? 'Saving...' : 'Complete' }}
            </button>

            <!-- Cancel -->
            <button
              :disabled="actionSaving[order.id]"
              class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-50 dark:hover:bg-red-950/40 disabled:opacity-50 transition-colors"
              @click.stop="openCancelModal(order)"
            >
              <XCircleIcon class="w-3.5 h-3.5" />
              Cancel Order
            </button>
          </div>

          <!-- Terminal state note -->
          <div v-else class="text-xs text-gray-400 dark:text-slate-500 italic">
            This order is in a terminal state and cannot be modified.
          </div>
        </div>
      </div>
    </div>

    <!-- Cancel modal -->
    <Teleport to="body">
      <div v-if="showCancelModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
          <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Cancel Order</h3>
          <p class="text-sm text-gray-600 dark:text-slate-400">Please provide a reason for cancelling this order.</p>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Cancellation Reason <span class="text-red-500">*</span></label>
            <textarea
              v-model="cancelReason"
              rows="3"
              placeholder="Describe why this order is being cancelled..."
              class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 resize-none focus:ring-2 focus:ring-red-500 focus:border-transparent"
            />
          </div>
          <p v-if="cancelError" class="text-xs text-red-600 dark:text-red-400">{{ cancelError }}</p>
          <div class="flex gap-2 justify-end">
            <button
              class="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
              @click="showCancelModal = false"
            >Dismiss</button>
            <button
              :disabled="cancelSaving"
              class="text-sm px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 transition-colors"
              @click="submitCancel"
            >{{ cancelSaving ? 'Cancelling...' : 'Cancel Order' }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Result modal -->
    <Teleport to="body">
      <div v-if="showResultModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
          <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Record Result</h3>
          <p class="text-sm text-gray-600 dark:text-slate-400">Summarize the result of this order.</p>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Result Summary <span class="text-red-500">*</span></label>
            <textarea
              v-model="resultSummary"
              rows="4"
              placeholder="Enter result details, findings, or outcome..."
              class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 resize-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
            />
          </div>
          <p v-if="resultError" class="text-xs text-red-600 dark:text-red-400">{{ resultError }}</p>
          <div class="flex gap-2 justify-end">
            <button
              class="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
              @click="showResultModal = false"
            >Cancel</button>
            <button
              :disabled="resultSaving"
              class="text-sm px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors"
              @click="submitResult"
            >{{ resultSaving ? 'Saving...' : 'Record Result' }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
