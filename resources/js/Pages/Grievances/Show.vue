<!--
  Grievance Detail View — two-column layout showing grievance information,
  investigation notes, resolution/escalation sections, CMS reporting controls,
  notify participant, and a full activity timeline. QA admins can take action
  to investigate, resolve, escalate, or withdraw the grievance.
-->
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head, usePage, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
  ExclamationTriangleIcon,
  CheckCircleIcon,
  ArrowLeftIcon,
  FlagIcon,
  BellIcon,
  UserCircleIcon,
  ClockIcon,
  DocumentTextIcon,
  ArrowUpIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────

interface Grievance {
  id: number
  reference_number: string
  participant_id: number
  participant_name: string
  category: string
  category_label: string
  filed_by_name: string
  filed_by_type: string
  filed_at: string
  priority: string
  status: string
  description: string
  investigation_notes: string | null
  resolution_text: string | null
  resolution_date: string | null
  escalation_reason: string | null
  escalated_to_name: string | null
  escalated_to_user_id: number | null
  cms_reportable: boolean
  cms_submitted_at: string | null
  notification_method: string | null
  notified_at: string | null
  assigned_to_name: string | null
}

interface ActivityEntry {
  id: number
  action: string
  description: string
  user_name: string
  created_at: string
}

interface StaffMember {
  id: number
  name: string
  department: string
}

// ── Props ──────────────────────────────────────────────────────────────────

const props = defineProps<{
  grievance: Grievance
  activity: ActivityEntry[]
  categories: Record<string, string>
  statuses: Record<string, string>
  isQaAdmin: boolean
  notificationMethods: Record<string, string>
}>()

// ── Auth ───────────────────────────────────────────────────────────────────

const page = usePage()
const user = computed(() => (page.props.auth as any)?.user)

// ── Derived state ──────────────────────────────────────────────────────────

const isClosed = computed(() =>
  props.grievance.status === 'resolved' || props.grievance.status === 'withdrawn'
)

// ── Escalation staff ──────────────────────────────────────────────────────

const escalationStaff = ref<StaffMember[]>([])

onMounted(async () => {
  if (
    props.isQaAdmin &&
    !isClosed.value &&
    ['open', 'under_review'].includes(props.grievance.status)
  ) {
    try {
      const res = await axios.get('/grievances/escalation-staff')
      escalationStaff.value = res.data.data ?? res.data
    } catch {
      // non-critical
    }
  }
})

// ── Action forms ──────────────────────────────────────────────────────────

const actionLoading = ref(false)

// Resolve form
const showResolveForm = ref(false)
const resolveForm = ref({ resolution_text: '', resolution_date: new Date().toISOString().slice(0, 10) })

// Escalate form
const showEscalateForm = ref(false)
const escalateForm = ref({ escalation_reason: '', escalated_to_user_id: null as number | null })

// Withdraw confirmation
const showWithdrawConfirm = ref(false)

// Notify participant form
const showNotifyForm = ref(false)
const notifyForm = ref({ notification_method: 'phone' })

async function postAction(endpoint: string, data: object = {}) {
  actionLoading.value = true
  try {
    await axios.post(`/grievances/${props.grievance.id}/${endpoint}`, data)
    router.reload()
  } finally {
    actionLoading.value = false
  }
}

async function startInvestigation() {
  await postAction('investigate')
}

async function submitResolve() {
  await postAction('resolve', resolveForm.value)
  showResolveForm.value = false
}

async function submitEscalate() {
  await postAction('escalate', escalateForm.value)
  showEscalateForm.value = false
}

async function submitWithdraw() {
  await postAction('withdraw')
  showWithdrawConfirm.value = false
}

async function submitNotify() {
  await postAction('notify-participant', notifyForm.value)
  showNotifyForm.value = false
}

// CMS actions
async function toggleCmsFlag() {
  const endpoint = props.grievance.cms_reportable ? 'unflag-cms' : 'flag-cms'
  await postAction(endpoint)
}

async function submitToCms() {
  await postAction('submit-cms')
}

// ── Status badge ───────────────────────────────────────────────────────────

const STATUS_CLASSES: Record<string, string> = {
  open:         'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
  under_review: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
  escalated:    'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
  resolved:     'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
  withdrawn:    'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300',
}

function statusClass(status: string): string {
  return STATUS_CLASSES[status] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300'
}

// ── Activity timeline config ───────────────────────────────────────────────

const ACTIVITY_CONFIG: Record<string, { dot: string; text: string }> = {
  filed:       { dot: 'bg-blue-500',   text: 'text-blue-700 dark:text-blue-300' },
  investigated:{ dot: 'bg-amber-500',  text: 'text-amber-700 dark:text-amber-300' },
  resolved:    { dot: 'bg-green-500',  text: 'text-green-700 dark:text-green-300' },
  escalated:   { dot: 'bg-purple-500', text: 'text-purple-700 dark:text-purple-300' },
  withdrawn:   { dot: 'bg-slate-400',  text: 'text-slate-600 dark:text-slate-400' },
  cms_flagged: { dot: 'bg-red-500',    text: 'text-red-700 dark:text-red-300' },
  notified:    { dot: 'bg-teal-500',   text: 'text-teal-700 dark:text-teal-300' },
}

function activityDot(action: string): string {
  return ACTIVITY_CONFIG[action]?.dot ?? 'bg-gray-400'
}

function activityText(action: string): string {
  return ACTIVITY_CONFIG[action]?.text ?? 'text-gray-600 dark:text-slate-400'
}

// ── Date format ───────────────────────────────────────────────────────────

function fmt(d: string | null): string {
  if (!d) return '-'
  return new Date(d).toLocaleDateString()
}

function fmtDateTime(d: string | null): string {
  if (!d) return '-'
  return new Date(d).toLocaleString()
}
</script>

<template>
  <AppShell>
    <Head :title="`Grievance ${props.grievance.reference_number}`" />

    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

      <!-- Back link -->
      <button
        @click="router.visit('/grievances')"
        class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 mb-6 transition"
      >
        <ArrowLeftIcon class="w-4 h-4" />
        Back to Grievances
      </button>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- LEFT COLUMN: main info -->
        <div class="lg:col-span-2 space-y-6">

          <!-- Header card -->
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
              <div>
                <p class="text-xs font-mono text-gray-500 dark:text-slate-400 mb-1">{{ props.grievance.reference_number }}</p>
                <h1 class="text-xl font-bold text-gray-900 dark:text-slate-100">{{ props.grievance.category_label }}</h1>
              </div>
              <div class="flex gap-2 flex-wrap">
                <!-- Priority -->
                <span
                  v-if="props.grievance.priority === 'urgent'"
                  class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300"
                >
                  <ExclamationTriangleIcon class="w-3.5 h-3.5" />
                  Urgent
                </span>
                <span
                  v-else
                  class="px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-300"
                >
                  Standard
                </span>
                <!-- Status -->
                <span :class="['px-2 py-1 rounded-full text-xs font-medium', statusClass(props.grievance.status)]">
                  {{ props.statuses[props.grievance.status] ?? props.grievance.status.replace(/_/g, ' ') }}
                </span>
                <!-- CMS flag -->
                <span
                  v-if="props.grievance.cms_reportable"
                  class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300"
                >
                  <FlagIcon class="w-3.5 h-3.5" />
                  CMS Reportable
                </span>
              </div>
            </div>

            <!-- Meta grid -->
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
              <div>
                <p class="text-xs text-gray-500 dark:text-slate-400">Participant</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ props.grievance.participant_name }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500 dark:text-slate-400">Filed By</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ props.grievance.filed_by_name }}</p>
                <p class="text-xs text-gray-500 dark:text-slate-400 capitalize">{{ props.grievance.filed_by_type.replace(/_/g, ' ') }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500 dark:text-slate-400">Date Filed</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ fmt(props.grievance.filed_at) }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500 dark:text-slate-400">Assigned To</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ props.grievance.assigned_to_name || '-' }}</p>
              </div>
              <div v-if="props.grievance.resolution_date">
                <p class="text-xs text-gray-500 dark:text-slate-400">Resolution Date</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ fmt(props.grievance.resolution_date) }}</p>
              </div>
              <div v-if="props.grievance.notified_at">
                <p class="text-xs text-gray-500 dark:text-slate-400">Participant Notified</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ fmt(props.grievance.notified_at) }}</p>
              </div>
            </div>
          </div>

          <!-- Description -->
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-6">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase mb-3 flex items-center gap-2">
              <DocumentTextIcon class="w-4 h-4" />
              Grievance Description
            </h2>
            <p class="text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">{{ props.grievance.description }}</p>
          </div>

          <!-- Investigation Notes -->
          <div
            v-if="props.grievance.investigation_notes"
            class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-6"
          >
            <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase mb-3">Investigation Notes</h2>
            <p class="text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">{{ props.grievance.investigation_notes }}</p>
          </div>

          <!-- Resolution (resolved status) -->
          <div
            v-if="props.grievance.status === 'resolved' && props.grievance.resolution_text"
            class="bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800 p-6"
          >
            <h2 class="text-sm font-semibold text-green-700 dark:text-green-300 uppercase mb-3 flex items-center gap-2">
              <CheckCircleIcon class="w-4 h-4" />
              Resolution
            </h2>
            <p class="text-sm text-green-800 dark:text-green-200 whitespace-pre-wrap leading-relaxed">{{ props.grievance.resolution_text }}</p>
          </div>

          <!-- Escalation (escalated status) -->
          <div
            v-if="props.grievance.status === 'escalated' && props.grievance.escalation_reason"
            class="bg-red-50 dark:bg-red-900/20 rounded-xl border border-red-200 dark:border-red-800 p-6"
          >
            <h2 class="text-sm font-semibold text-red-700 dark:text-red-300 uppercase mb-3 flex items-center gap-2">
              <ArrowUpIcon class="w-4 h-4" />
              Escalation
            </h2>
            <p class="text-sm text-red-800 dark:text-red-200 whitespace-pre-wrap leading-relaxed">{{ props.grievance.escalation_reason }}</p>
            <div v-if="props.grievance.escalated_to_name" class="mt-2">
              <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">
                Escalated to: {{ props.grievance.escalated_to_name }}
              </span>
            </div>
          </div>

        </div>

        <!-- RIGHT COLUMN: actions + timeline -->
        <div class="space-y-5">

          <!-- Actions panel (QA admin, not closed) -->
          <div
            v-if="props.isQaAdmin && !isClosed"
            class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-3"
          >
            <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase">Actions</h2>

            <!-- Start Investigation -->
            <button
              v-if="props.grievance.status === 'open'"
              @click="startInvestigation()"
              :disabled="actionLoading"
              class="w-full px-4 py-2.5 text-sm rounded-lg bg-amber-600 text-white font-medium hover:bg-amber-700 disabled:opacity-50 transition"
            >
              Start Investigation
            </button>

            <!-- Resolve -->
            <div>
              <button
                v-if="!showResolveForm"
                @click="showResolveForm = true"
                class="w-full px-4 py-2.5 text-sm rounded-lg bg-green-600 text-white font-medium hover:bg-green-700 transition"
              >
                Resolve Grievance
              </button>
              <div v-else class="space-y-2 border border-green-200 dark:border-green-800 rounded-lg p-4 bg-green-50 dark:bg-green-900/20">
                <label class="text-xs font-medium text-green-700 dark:text-green-300">Resolution</label>
                <textarea
                  v-model="resolveForm.resolution_text"
                  rows="3"
                  placeholder="Describe the resolution..."
                  class="w-full px-3 py-2 text-sm rounded-lg border border-green-300 dark:border-green-700 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100 focus:ring-2 focus:ring-green-500 outline-none"
                />
                <input
                  v-model="resolveForm.resolution_date"
                  type="date"
                  class="w-full px-3 py-2 text-sm rounded-lg border border-green-300 dark:border-green-700 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100 focus:ring-2 focus:ring-green-500 outline-none"
                />
                <div class="flex gap-2">
                  <button @click="submitResolve()" :disabled="actionLoading" class="flex-1 px-3 py-1.5 text-xs rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 transition">Confirm</button>
                  <button @click="showResolveForm = false" class="px-3 py-1.5 text-xs rounded-lg border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition">Cancel</button>
                </div>
              </div>
            </div>

            <!-- Escalate -->
            <div v-if="['open', 'under_review'].includes(props.grievance.status)">
              <button
                v-if="!showEscalateForm"
                @click="showEscalateForm = true"
                class="w-full px-4 py-2.5 text-sm rounded-lg bg-purple-100 text-purple-700 dark:bg-purple-900/20 dark:text-purple-300 font-medium hover:bg-purple-200 dark:hover:bg-purple-900/40 transition"
              >
                Escalate
              </button>
              <div v-else class="space-y-2 border border-purple-200 dark:border-purple-800 rounded-lg p-4 bg-purple-50 dark:bg-purple-900/20">
                <label class="text-xs font-medium text-purple-700 dark:text-purple-300">Escalation Reason</label>
                <textarea
                  v-model="escalateForm.escalation_reason"
                  rows="2"
                  class="w-full px-3 py-2 text-sm rounded-lg border border-purple-300 dark:border-purple-700 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100 focus:ring-2 focus:ring-purple-500 outline-none"
                />
                <select
                  v-model="escalateForm.escalated_to_user_id"
                  class="w-full px-3 py-2 text-sm rounded-lg border border-purple-300 dark:border-purple-700 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100 focus:ring-2 focus:ring-purple-500 outline-none"
                >
                  <option :value="null">Escalate to (optional)...</option>
                  <option v-for="s in escalationStaff" :key="s.id" :value="s.id">{{ s.name }} — {{ s.department }}</option>
                </select>
                <div class="flex gap-2">
                  <button @click="submitEscalate()" :disabled="actionLoading" class="flex-1 px-3 py-1.5 text-xs rounded-lg bg-purple-600 text-white hover:bg-purple-700 disabled:opacity-50 transition">Escalate</button>
                  <button @click="showEscalateForm = false" class="px-3 py-1.5 text-xs rounded-lg border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition">Cancel</button>
                </div>
              </div>
            </div>

            <!-- Withdraw -->
            <div>
              <button
                v-if="!showWithdrawConfirm"
                @click="showWithdrawConfirm = true"
                class="w-full px-4 py-2.5 text-sm rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 font-medium hover:bg-slate-200 dark:hover:bg-slate-600 transition"
              >
                Withdraw
              </button>
              <div v-else class="border border-slate-200 dark:border-slate-600 rounded-lg p-4 bg-slate-50 dark:bg-slate-700/50 text-sm">
                <p class="text-gray-700 dark:text-slate-300 mb-3">Confirm withdrawal of this grievance?</p>
                <div class="flex gap-2">
                  <button @click="submitWithdraw()" :disabled="actionLoading" class="flex-1 px-3 py-1.5 text-xs rounded-lg bg-slate-600 text-white hover:bg-slate-700 disabled:opacity-50 transition">Confirm</button>
                  <button @click="showWithdrawConfirm = false" class="px-3 py-1.5 text-xs rounded-lg border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition">Cancel</button>
                </div>
              </div>
            </div>

          </div>

          <!-- CMS Reporting (QA admin always visible) -->
          <div
            v-if="props.isQaAdmin"
            class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-3"
          >
            <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase flex items-center gap-2">
              <FlagIcon class="w-4 h-4 text-red-500" />
              CMS Reporting
            </h2>

            <div class="flex items-center gap-2 text-sm">
              <span :class="['font-medium', props.grievance.cms_reportable ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-slate-400']">
                {{ props.grievance.cms_reportable ? 'Flagged as CMS Reportable' : 'Not flagged' }}
              </span>
            </div>

            <div v-if="props.grievance.cms_submitted_at" class="text-xs text-gray-500 dark:text-slate-400">
              Submitted: {{ fmtDateTime(props.grievance.cms_submitted_at) }}
            </div>

            <div class="flex flex-col gap-2">
              <button
                @click="toggleCmsFlag()"
                :disabled="actionLoading"
                class="px-4 py-2 text-sm rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-50 transition"
              >
                {{ props.grievance.cms_reportable ? 'Remove CMS Flag' : 'Flag as CMS Reportable' }}
              </button>
              <button
                v-if="props.grievance.cms_reportable && !props.grievance.cms_submitted_at"
                @click="submitToCms()"
                :disabled="actionLoading"
                class="px-4 py-2 text-sm rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-50 transition"
              >
                Submit to CMS
              </button>
            </div>
          </div>

          <!-- Notify Participant (QA admin, resolved) -->
          <div
            v-if="props.isQaAdmin && props.grievance.status === 'resolved'"
            class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-3"
          >
            <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase flex items-center gap-2">
              <BellIcon class="w-4 h-4 text-teal-500" />
              Notify Participant
            </h2>

            <div v-if="props.grievance.notified_at" class="text-sm text-teal-600 dark:text-teal-400 flex items-center gap-1">
              <CheckCircleIcon class="w-4 h-4" />
              Notified {{ fmt(props.grievance.notified_at) }} via {{ props.grievance.notification_method }}
            </div>

            <div v-else>
              <button
                v-if="!showNotifyForm"
                @click="showNotifyForm = true"
                class="w-full px-4 py-2.5 text-sm rounded-lg bg-teal-600 text-white font-medium hover:bg-teal-700 transition"
              >
                Notify Participant
              </button>
              <div v-else class="space-y-2">
                <select
                  v-model="notifyForm.notification_method"
                  class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 focus:ring-2 focus:ring-teal-500 outline-none"
                >
                  <option v-for="(label, key) in props.notificationMethods" :key="key" :value="key">{{ label }}</option>
                </select>
                <div class="flex gap-2">
                  <button @click="submitNotify()" :disabled="actionLoading" class="flex-1 px-3 py-1.5 text-xs rounded-lg bg-teal-600 text-white hover:bg-teal-700 disabled:opacity-50 transition">Record Notification</button>
                  <button @click="showNotifyForm = false" class="px-3 py-1.5 text-xs rounded-lg border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition">Cancel</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Activity Timeline -->
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase mb-4 flex items-center gap-2">
              <ClockIcon class="w-4 h-4" />
              Activity Timeline
            </h2>

            <div v-if="props.activity.length === 0" class="text-sm text-gray-400 dark:text-slate-500">No activity recorded.</div>

            <ol v-else class="relative border-l border-gray-200 dark:border-slate-700 space-y-5 ml-2">
              <li
                v-for="entry in props.activity"
                :key="entry.id"
                class="ml-4"
              >
                <span
                  :class="['absolute w-3 h-3 rounded-full -left-1.5 border-2 border-white dark:border-slate-800', activityDot(entry.action)]"
                />
                <p :class="['text-xs font-semibold', activityText(entry.action)]">
                  {{ entry.description }}
                </p>
                <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                  {{ entry.user_name }} · {{ fmtDateTime(entry.created_at) }}
                </p>
              </li>
            </ol>
          </div>

        </div>
      </div>
    </div>
  </AppShell>
</template>
