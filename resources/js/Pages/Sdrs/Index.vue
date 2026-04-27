<!--
  SDR Management: Service Delivery Requests with 72-hour enforcement tracking.

  Shows four filter tabs: My Department / Assigned To Me / Overdue / All (QA only).
  Each SDR card displays participant, request type, priority, time remaining (or
  overdue), and status with inline action buttons to Acknowledge / In Progress /
  Complete via axios PATCH.  A New SDR modal allows submitting a new request.
  An overdue banner appears when any SDR has breached the 72-hour window.

  Route:   GET /sdrs -> Inertia::render('Sdrs/Index')
  Props:   myDeptSdrs, assignedToMe, overdueSdrs, allSdrs, userDept,
           requestTypes, departments
-->
<script setup lang="ts">
// ─── Sdrs/Index ─────────────────────────────────────────────────────────────
// SDR (Service Delivery Request) queue: the internal hand-off ticket between
// PACE departments (e.g. Primary Care -> Pharmacy "please refill X").
//
// Audience: every clinical/operational department. Filter tabs scope to the
// user's own dept, items assigned to them, the org-wide overdue list, and
// (QA Compliance only) the all-tenants view.
//
// Notable rules:
//   - 42 CFR §460.121: service delivery requests must be acted on within
//     a 72-hour window; the time-remaining countdown drives this UI.
//   - Status transitions (acknowledge/in-progress/complete) are PATCH'd via
//     axios so Inertia state is preserved (no full-page reload).
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { PlusIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

// ── Types ─────────────────────────────────────────────────────────────────────

interface ParticipantSummary {
  id:         number
  mrn:        string
  first_name: string
  last_name:  string
}

interface UserSummary {
  id:         number
  first_name: string
  last_name:  string
}

interface SdrItem {
  id:                   number
  request_type:         string
  description:          string
  priority:             'routine' | 'urgent' | 'emergent'
  sdr_type:             'standard' | 'expedited'
  status:               'submitted' | 'acknowledged' | 'in_progress' | 'completed' | 'cancelled' | 'denied'
  requesting_department: string
  assigned_department:  string
  submitted_at:         string
  due_at:               string
  completed_at:         string | null
  escalated:            boolean
  participant:          ParticipantSummary
  requesting_user:      UserSummary | null
}

type TabKey = 'my_dept' | 'assigned_to_me' | 'overdue' | 'all'

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
  myDeptSdrs:   SdrItem[]
  assignedToMe: SdrItem[]
  overdueSdrs:  SdrItem[]
  allSdrs:      { data: SdrItem[] } | null
  userDept:     string
  requestTypes: string[]
  departments:  string[]
}>()

// ── Constants ─────────────────────────────────────────────────────────────────

const TYPE_LABELS: Record<string, string> = {
  lab_order:          'Lab Order',
  referral:           'Referral',
  home_care_visit:    'Home Care Visit',
  transport_request:  'Transport Request',
  equipment_dme:      'Equipment / DME',
  pharmacy_change:    'Pharmacy Change',
  assessment_request: 'Assessment Request',
  care_plan_update:   'Care Plan Update',
  other:              'Other',
}

const PRIORITY_CLASSES: Record<string, string> = {
  emergent: 'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 ring-red-600/20',
  urgent:   'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 ring-amber-600/20',
  routine:  'bg-gray-50 dark:bg-slate-800 text-gray-600 dark:text-slate-400 ring-gray-500/10',
}

const STATUS_CLASSES: Record<string, string> = {
  submitted:    'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300',
  acknowledged: 'bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300',
  in_progress:  'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300',
  completed:    'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300',
  cancelled:    'bg-gray-50 dark:bg-slate-800 text-gray-400 dark:text-slate-500 line-through',
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function deptLabel(dept: string): string {
  return dept.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function hoursRemaining(dueAt: string): number {
  return (new Date(dueAt).getTime() - Date.now()) / 3_600_000
}

function urgencyColor(hrs: number): string {
  if (hrs < 0)   return 'text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-950/60'
  if (hrs <= 8)  return 'text-orange-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-950/60'
  if (hrs <= 24) return 'text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/60'
  return 'text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-slate-900'
}

function formatHours(hrs: number): string {
  if (hrs < 0) {
    const over = Math.abs(hrs)
    return `${over.toFixed(0)}h overdue`
  }
  if (hrs < 1) return `${Math.round(hrs * 60)}m left`
  return `${hrs.toFixed(0)}h left`
}

function isTerminal(status: string): boolean {
  // 'denied' added in Phase 1 (MVP roadmap) for §460.122 denial workflow.
  return status === 'completed' || status === 'cancelled' || status === 'denied'
}

// ── Tab state ─────────────────────────────────────────────────────────────────

const activeTab = ref<TabKey>('my_dept')

interface TabDef {
  key:     TabKey
  label:   string
  count:   number | null
  visible: boolean
}

const tabs = computed((): TabDef[] => [
  { key: 'my_dept',        label: 'My Department',  count: props.myDeptSdrs.length,                         visible: true },
  { key: 'assigned_to_me', label: 'Assigned to Me', count: props.assignedToMe.length,                       visible: true },
  { key: 'overdue',        label: 'Overdue',        count: props.overdueSdrs.length,                        visible: true },
  { key: 'all',            label: 'All SDRs',       count: props.allSdrs?.data?.length ?? null,             visible: props.userDept === 'qa_compliance' },
])

const visibleTabs = computed(() => tabs.value.filter(t => t.visible))

const activeSdrs = computed((): SdrItem[] => {
  if (activeTab.value === 'my_dept')        return props.myDeptSdrs
  if (activeTab.value === 'assigned_to_me') return props.assignedToMe
  if (activeTab.value === 'overdue')        return props.overdueSdrs
  if (activeTab.value === 'all')            return props.allSdrs?.data ?? []
  return []
})

const activeTabLabel = computed(() =>
  tabs.value.find(t => t.key === activeTab.value)?.label ?? '',
)

// ── SDR card actions ──────────────────────────────────────────────────────────

// Tracks which SDR is currently being updated (by id)
const updatingId = ref<number | null>(null)

async function updateStatus(sdrId: number, status: string) {
  updatingId.value = sdrId
  try {
    await axios.patch(`/sdrs/${sdrId}`, { status })
    router.reload()
  } catch {
    // Non-blocking: silently ignore update errors
  } finally {
    updatingId.value = null
  }
}

// Phase 1 (MVP roadmap): Deny SDR + issue §460.122 denial notice
const denyingSdr = ref<{ id: number } | null>(null)
const denying = ref(false)
const denyForm = ref({
  reason_code: '',
  reason_narrative: '',
  delivery_method: 'mail',
})
function openDenyModal(sdr: { id: number }) {
  denyingSdr.value = sdr
  denyForm.value = { reason_code: '', reason_narrative: '', delivery_method: 'mail' }
}
async function submitDeny() {
  if (!denyingSdr.value) return
  if (!denyForm.value.reason_code.trim() || !denyForm.value.reason_narrative.trim()) {
    alert('Reason code and narrative are required.')
    return
  }
  denying.value = true
  try {
    await axios.post(`/sdrs/${denyingSdr.value.id}/deny`, denyForm.value)
    denyingSdr.value = null
    router.reload()
  } catch (err: any) {
    alert(err?.response?.data?.message ?? 'Failed to deny SDR.')
  } finally {
    denying.value = false
  }
}

// ── New SDR modal state ────────────────────────────────────────────────────────

const showNew    = ref(false)
const newForm    = ref({
  participant_id:      '',
  request_type:        props.requestTypes[0] ?? 'lab_order',
  priority:            'routine',
  sdr_type:            'standard',          // Phase 2 (MVP): 72h standard / 24h expedited
  assigned_department: props.departments[0] ?? 'primary_care',
  description:         '',
})
const newSaving  = ref(false)
const newError   = ref('')

function openNewModal() {
  newForm.value = {
    participant_id:      '',
    request_type:        props.requestTypes[0] ?? 'lab_order',
    priority:            'routine',
    sdr_type:            'standard',
    assigned_department: props.departments[0] ?? 'primary_care',
    description:         '',
  }
  newError.value = ''
  showNew.value  = true
}

function closeNewModal() {
  showNew.value  = false
  newError.value = ''
}

async function submitNewSdr() {
  if (!newForm.value.participant_id || !newForm.value.description.trim()) {
    newError.value = 'Participant ID and description are required.'
    return
  }
  newSaving.value = true
  newError.value  = ''
  try {
    await axios.post('/sdrs', newForm.value)
    closeNewModal()
    router.reload()
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    newError.value = err.response?.data?.message ?? 'Failed to submit SDR.'
  } finally {
    newSaving.value = false
  }
}
</script>

<template>
  <AppShell>
    <Head title="SDRs: Service Delivery Requests" />

    <div class="px-6 py-6 space-y-5">

      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-xl font-bold text-gray-900 dark:text-slate-100">Service Delivery Requests</h1>
          <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
            72-hour completion window. Requests are escalated automatically when overdue.
          </p>
        </div>
        <button
          class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-sm"
          @click="openNewModal"
        >
          <PlusIcon class="w-4 h-4" aria-hidden="true" />
          New SDR
        </button>
      </div>

      <!-- Overdue banner (hidden while on the Overdue tab) -->
      <div
        v-if="props.overdueSdrs.length > 0 && activeTab !== 'overdue'"
        class="flex items-center gap-3 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3"
      >
        <ExclamationTriangleIcon class="w-5 h-5 text-red-500 shrink-0" aria-hidden="true" />
        <div class="flex-1">
          <p class="text-sm font-semibold text-red-800 dark:text-red-300">
            {{ props.overdueSdrs.length }} SDR{{ props.overdueSdrs.length !== 1 ? 's' : '' }}
            past the 72-hour window
          </p>
          <p class="text-xs text-red-700 dark:text-red-300">
            These have been escalated and flagged for QA review.
          </p>
        </div>
        <button
          class="text-xs font-medium text-red-700 dark:text-red-300 hover:text-red-900 dark:hover:text-red-100 underline shrink-0"
          @click="activeTab = 'overdue'"
        >
          View overdue
        </button>
      </div>

      <!-- Tab bar -->
      <div class="border-b border-gray-200 dark:border-slate-700">
        <nav class="flex gap-1" aria-label="SDR tabs">
          <button
            v-for="t in visibleTabs"
            :key="t.key"
            :class="[
              'px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px',
              activeTab === t.key
                ? 'border-blue-600 text-blue-700 dark:text-blue-300'
                : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 hover:border-gray-300 dark:hover:border-slate-500',
            ]"
            @click="activeTab = t.key"
          >
            {{ t.label }}
            <span
              v-if="t.count !== null && t.count > 0"
              :class="[
                'ml-1.5 inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-xs font-bold min-w-[18px]',
                t.key === 'overdue'
                  ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                  : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
              ]"
            >
              {{ t.count }}
            </span>
          </button>
        </nav>
      </div>

      <!-- SDR list -->
      <div
        v-if="activeSdrs.length === 0"
        class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-10 text-center text-sm text-gray-500 dark:text-slate-400"
      >
        No {{ activeTabLabel.toLowerCase() }} at this time.
      </div>

      <div v-else class="grid grid-cols-1 xl:grid-cols-2 gap-3">
        <!-- SDR card -->
        <div
          v-for="sdr in activeSdrs"
          :key="sdr.id"
          :class="[
            'rounded-xl border bg-white dark:bg-slate-800 p-4 space-y-3',
            sdr.escalated
              ? 'border-red-300 dark:border-red-700'
              : 'border-gray-200 dark:border-slate-700',
          ]"
        >
          <!-- Card top row: name + priority -->
          <div class="flex items-start justify-between gap-2">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5 flex-wrap">
                <span class="font-semibold text-sm text-gray-800 dark:text-slate-200 truncate">
                  {{ sdr.participant.first_name }} {{ sdr.participant.last_name }}
                </span>
                <span class="text-xs text-gray-400 dark:text-slate-500">· {{ sdr.participant.mrn }}</span>
                <span
                  v-if="sdr.escalated"
                  class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 rounded text-xs font-bold ring-1 ring-red-300 dark:ring-red-700"
                >
                  ! ESCALATED
                </span>
              </div>
              <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                {{ TYPE_LABELS[sdr.request_type] ?? sdr.request_type }}
                &middot;
                <span class="text-gray-400 dark:text-slate-500">
                  {{ deptLabel(sdr.requesting_department) }} &rarr; {{ deptLabel(sdr.assigned_department) }}
                </span>
              </p>
            </div>

            <!-- Priority + countdown -->
            <div class="flex flex-col items-end gap-1 shrink-0">
              <!-- Phase 2 (MVP roadmap): expedited SDR badge -->
              <span
                v-if="sdr.sdr_type === 'expedited'"
                class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-bold bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 ring-1 ring-inset ring-red-600/20"
                title="Expedited 24-hour decision clock per 42 CFR §460.121"
              >
                EXPEDITED 24h
              </span>
              <span
                :class="[
                  'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset',
                  PRIORITY_CLASSES[sdr.priority] ?? '',
                ]"
              >
                {{ sdr.priority.toUpperCase() }}
              </span>
              <span
                v-if="!isTerminal(sdr.status)"
                :class="[
                  'inline-flex items-center rounded px-2 py-0.5 text-xs font-semibold',
                  urgencyColor(hoursRemaining(sdr.due_at)),
                ]"
              >
                {{ formatHours(hoursRemaining(sdr.due_at)) }}
              </span>
            </div>
          </div>

          <!-- Description snippet -->
          <p class="text-xs text-gray-600 dark:text-slate-400 line-clamp-2">{{ sdr.description }}</p>

          <!-- Footer row: status + actions -->
          <div class="flex items-center justify-between gap-2">
            <span
              :class="[
                'inline-flex items-center rounded px-2 py-0.5 text-xs font-medium',
                STATUS_CLASSES[sdr.status] ?? '',
              ]"
            >
              {{ sdr.status.replace('_', ' ') }}
            </span>

            <div v-if="!isTerminal(sdr.status)" class="flex items-center gap-1.5">
              <button
                v-if="sdr.status === 'submitted'"
                :disabled="updatingId === sdr.id"
                class="px-2 py-1 text-xs font-medium border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-50"
                @click="updateStatus(sdr.id, 'acknowledged')"
              >
                Acknowledge
              </button>
              <button
                v-if="sdr.status === 'submitted' || sdr.status === 'acknowledged'"
                :disabled="updatingId === sdr.id"
                class="px-2 py-1 text-xs font-medium bg-amber-600 text-white rounded hover:bg-amber-700 disabled:opacity-50"
                @click="updateStatus(sdr.id, 'in_progress')"
              >
                In Progress
              </button>
              <button
                v-if="sdr.status === 'in_progress'"
                :disabled="updatingId === sdr.id"
                class="px-2 py-1 text-xs font-medium bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50"
                @click="updateStatus(sdr.id, 'completed')"
              >
                Complete
              </button>
              <!-- Phase 1 (MVP roadmap): §460.122 denial workflow. -->
              <button
                v-if="['submitted','acknowledged','in_progress'].includes(sdr.status)"
                :disabled="updatingId === sdr.id"
                class="px-2 py-1 text-xs font-medium bg-red-600 text-white rounded hover:bg-red-700 disabled:opacity-50"
                @click="openDenyModal(sdr)"
                title="Deny this SDR and issue a CMS-style denial notice"
              >
                Deny
              </button>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- Deny SDR modal (Phase 1) -->
    <div
      v-if="denyingSdr"
      class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
      @click.self="denyingSdr = null"
    >
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700">
          <h3 class="font-semibold text-slate-900 dark:text-slate-100">Deny SDR-{{ denyingSdr.id }}</h3>
          <button class="text-slate-400 hover:text-slate-600" @click="denyingSdr = null">✕</button>
        </div>
        <div class="px-6 py-5 space-y-4">
          <p class="text-xs text-slate-500 dark:text-slate-400">
            Denying this request will generate a CMS-style denial notice PDF and establish the
            participant's right to appeal (42 CFR §460.122). The notice will be stored in the
            participant's documents.
          </p>
          <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Reason Code</label>
            <input v-model="denyForm.reason_code" class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" placeholder="e.g. NOT_MEDICALLY_NECESSARY" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Reason Narrative</label>
            <textarea v-model="denyForm.reason_narrative" rows="5"
              placeholder="Clear explanation to be included in the participant's denial letter..."
              class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Delivery Method</label>
            <select v-model="denyForm.delivery_method" class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2">
              <option value="mail">Mail</option>
              <option value="in_person">In person</option>
              <option value="email">Email</option>
              <option value="secure_portal">Secure portal</option>
              <option value="phone_documented">Phone (documented)</option>
            </select>
          </div>
        </div>
        <div class="px-6 py-3 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-2">
          <button @click="denyingSdr = null" class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 text-sm">Cancel</button>
          <button :disabled="denying" @click="submitDeny" class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 disabled:opacity-50">
            {{ denying ? 'Issuing notice...' : 'Deny + Issue Notice' }}
          </button>
        </div>
      </div>
    </div>

    <!-- New SDR modal -->
    <div
      v-if="showNew"
      class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
      @click.self="closeNewModal"
    >
      <div
        class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg"
        role="dialog"
        aria-modal="true"
      >
        <!-- Modal header -->
        <div class="px-6 py-4 border-b border-gray-100 dark:border-slate-700 flex items-center justify-between">
          <h2 class="font-semibold text-gray-800 dark:text-slate-200">Submit Service Delivery Request</h2>
          <button
            class="text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300"
            aria-label="Close"
            @click="closeNewModal"
          >
            &#x2715;
          </button>
        </div>

        <!-- Modal body -->
        <div class="px-6 py-5 space-y-4">
          <p
            v-if="newError"
            class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/60 rounded-lg px-3 py-2"
          >
            {{ newError }}
          </p>

          <!-- Participant ID -->
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
              Participant ID <span class="text-red-500">*</span>
            </label>
            <input
              v-model="newForm.participant_id"
              type="number"
              placeholder="Enter participant ID"
              class="block w-full rounded-lg border border-gray-300 dark:border-slate-600 text-sm py-2 px-3 dark:bg-slate-700"
            />
          </div>

          <!-- Type + Priority -->
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Request Type</label>
              <select name="request_type"
                v-model="newForm.request_type"
                class="block w-full rounded-lg border border-gray-300 dark:border-slate-600 text-sm py-2 px-3 dark:bg-slate-700"
              >
                <option
                  v-for="t in props.requestTypes"
                  :key="t"
                  :value="t"
                >
                  {{ TYPE_LABELS[t] ?? t }}
                </option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Priority</label>
              <select name="priority"
                v-model="newForm.priority"
                class="block w-full rounded-lg border border-gray-300 dark:border-slate-600 text-sm py-2 px-3 dark:bg-slate-700"
              >
                <option value="routine">Routine</option>
                <option value="urgent">Urgent</option>
                <option value="emergent">Emergent</option>
              </select>
            </div>
          </div>

          <!-- Phase 2 (MVP roadmap): §460.121 SDR dual-clock selector -->
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
              Decision Clock
            </label>
            <div class="flex items-center gap-3">
              <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-slate-300 cursor-pointer">
                <input type="radio" v-model="newForm.sdr_type" value="standard" class="text-blue-600" />
                Standard (72 h)
              </label>
              <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-slate-300 cursor-pointer">
                <input type="radio" v-model="newForm.sdr_type" value="expedited" class="text-red-600" />
                Expedited (24 h)
              </label>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
              Expedited when the standard 72-hour wait could seriously harm health or ability to regain function (42 CFR §460.121).
            </p>
          </div>

          <!-- Assign to department -->
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Assign To Department</label>
            <select name="assigned_department"
              v-model="newForm.assigned_department"
              class="block w-full rounded-lg border border-gray-300 dark:border-slate-600 text-sm py-2 px-3 dark:bg-slate-700"
            >
              <option
                v-for="d in props.departments"
                :key="d"
                :value="d"
              >
                {{ deptLabel(d) }}
              </option>
            </select>
          </div>

          <!-- Description -->
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
              Description <span class="text-red-500">*</span>
            </label>
            <textarea
              v-model="newForm.description"
              rows="4"
              placeholder="Describe the service request, clinical context, and any urgency details."
              class="block w-full rounded-lg border border-gray-300 dark:border-slate-600 text-sm py-2 px-3 dark:bg-slate-700 resize-none"
            />
            <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
              This request will be due within 72 hours of submission (CMS requirement).
            </p>
          </div>
        </div>

        <!-- Modal footer -->
        <div class="px-6 py-4 border-t border-gray-100 dark:border-slate-700 flex justify-end gap-3">
          <button
            class="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700 rounded-lg border border-gray-200 dark:border-slate-600"
            @click="closeNewModal"
          >
            Cancel
          </button>
          <button
            :disabled="newSaving"
            class="px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
            @click="submitNewSdr"
          >
            {{ newSaving ? 'Submitting...' : 'Submit SDR' }}
          </button>
        </div>
      </div>
    </div>

  </AppShell>
</template>
