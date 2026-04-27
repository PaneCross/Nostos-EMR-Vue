<!--
  QA/Compliance Dashboard: qa_compliance department view.
  Displays 11 KPI cards pre-loaded from server, an open-incident queue,
  four lazy-loaded compliance tabs (incidents, unsigned notes, overdue
  assessments, grievances), a security posture widget, and CSV exports.
  All data except compliance tabs is delivered as Inertia props.
-->
<script setup lang="ts">
// ─── Qa/Dashboard ───────────────────────────────────────────────────────────
// QA Compliance landing page: 11 KPIs, open incident queue, lazy-loaded
// compliance tabs (incidents / unsigned notes / overdue assessments /
// grievances), security posture widget, and CSV exports.
//
// Audience: QA Compliance department, Executive read-through.
//
// Notable rules:
//   - All KPIs feed the org's CMS audit posture; this is the single page a
//     QA director should be able to walk a surveyor through.
//   - Security widget summarizes BAA / SRA / encryption status (see
//     ItAdmin/Security.vue for the editable detail).
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head, usePage, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
  ExclamationTriangleIcon,
  CheckCircleIcon,
  ClockIcon,
  ShieldCheckIcon,
  DocumentTextIcon,
  ArrowDownTrayIcon,
  ChevronRightIcon,
  InformationCircleIcon,
} from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────

interface Kpis {
  open_incidents_count: number
  unsigned_notes_count: number
  overdue_assessments_count: number
  open_grievances_count: number
  active_qapi_count: number
  overdue_care_plans_count: number
  sdr_compliance_rate: number
  hospitalizations_month: number
  missing_npp_count: number
  pending_cms_disenrollment_count: number
  cms_notification_overdue_count: number
}

interface IncidentRow {
  id: number
  reference_number: string
  incident_type: string
  participant_name: string
  occurred_at: string
  status: string
  rca_required: boolean
  rca_completed_at: string | null
  // Phase I2: sentinel classification state
  is_sentinel?: boolean | null
  sentinel_classified_at?: string | null
}

interface CompliancePosture {
  baa_active: boolean
  sra_current: boolean
  encryption_enabled: boolean
  baa_expires_at: string | null
  sra_completed_at: string | null
}

interface UnsignedNote {
  id: number
  note_type: string
  participant_name: string
  author_name: string
  created_at: string
  hours_overdue: number
}

interface OverdueAssessment {
  id: number
  assessment_type: string
  participant_name: string
  due_date: string
  days_overdue: number
  assigned_to: string
}

interface GrievanceRow {
  id: number
  reference_number: string
  participant_name: string
  category: string
  status: string
  filed_at: string
  priority: string
}

// ── Props ──────────────────────────────────────────────────────────────────

const props = defineProps<{
  kpis: Kpis
  openIncidents: IncidentRow[]
  incidentTypes: Record<string, string>
  statuses: Record<string, string>
  compliance_posture: CompliancePosture
}>()

// ── Auth ───────────────────────────────────────────────────────────────────

const page = usePage()
const user = computed(() => (page.props.auth as any)?.user)

// ── Phase I2: Sentinel classify ───────────────────────────────────────────
const canClassifySentinel = computed(() => {
  const u = user.value
  if (!u) return false
  if (u.role === 'super_admin') return true
  return ['qa_compliance', 'executive'].includes(u.department)
})

const sentinelOpen = ref(false)
const sentinelTarget = ref<IncidentRow | null>(null)
const sentinelReason = ref('')
const sentinelSubmitting = ref(false)
const sentinelError = ref<string | null>(null)

function openSentinelModal(incident: IncidentRow) {
  sentinelTarget.value = incident
  sentinelReason.value = ''
  sentinelError.value = null
  sentinelOpen.value = true
}
function closeSentinelModal() {
  sentinelOpen.value = false
  sentinelTarget.value = null
  sentinelError.value = null
}
async function submitSentinel() {
  if (!sentinelTarget.value) return
  if (sentinelReason.value.trim().length < 10) {
    sentinelError.value = 'Reason must be at least 10 characters.'
    return
  }
  sentinelSubmitting.value = true
  sentinelError.value = null
  try {
    await axios.post(`/qa/incidents/${sentinelTarget.value.id}/classify-sentinel`, {
      reason: sentinelReason.value.trim(),
    })
    // Mark the in-memory row as classified so UI updates immediately
    sentinelTarget.value.is_sentinel = true
    sentinelTarget.value.sentinel_classified_at = new Date().toISOString()
    closeSentinelModal()
  } catch (e: any) {
    sentinelError.value = e?.response?.data?.message ?? 'Classification failed.'
  } finally {
    sentinelSubmitting.value = false
  }
}

// ── Compliance tabs ────────────────────────────────────────────────────────

type ComplianceTab = 'incidents' | 'unsigned_notes' | 'overdue_assessments' | 'grievances'
const complianceTab = ref<ComplianceTab>('incidents')

const unsignedNotes = ref<UnsignedNote[]>([])
const unsignedNotesLoading = ref(false)
const unsignedNotesLoaded = ref(false)

const overdueAssessments = ref<OverdueAssessment[]>([])
const overdueAssessmentsLoading = ref(false)
const overdueAssessmentsLoaded = ref(false)

const grievances = ref<GrievanceRow[]>([])
const grievancesLoading = ref(false)
const grievancesLoaded = ref(false)

async function loadTab(tab: ComplianceTab) {
  if (tab === 'unsigned_notes' && !unsignedNotesLoaded.value) {
    unsignedNotesLoading.value = true
    try {
      const res = await axios.get('/qa/compliance/unsigned-notes')
      unsignedNotes.value = res.data.data ?? res.data
      unsignedNotesLoaded.value = true
    } finally {
      unsignedNotesLoading.value = false
    }
  }
  if (tab === 'overdue_assessments' && !overdueAssessmentsLoaded.value) {
    overdueAssessmentsLoading.value = true
    try {
      const res = await axios.get('/qa/compliance/overdue-assessments')
      overdueAssessments.value = res.data.data ?? res.data
      overdueAssessmentsLoaded.value = true
    } finally {
      overdueAssessmentsLoading.value = false
    }
  }
  if (tab === 'grievances' && !grievancesLoaded.value) {
    grievancesLoading.value = true
    try {
      const res = await axios.get('/grievances?status=open&per_page=50')
      grievances.value = res.data.data ?? res.data
      grievancesLoaded.value = true
    } finally {
      grievancesLoading.value = false
    }
  }
}

function switchTab(tab: ComplianceTab) {
  complianceTab.value = tab
  loadTab(tab)
}

// ── Status badge helper ────────────────────────────────────────────────────

const STATUS_CLASSES: Record<string, string> = {
  open:          'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
  under_review:  'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
  rca_pending:   'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
  rca_complete:  'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
  resolved:      'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
  closed:        'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300',
}

function statusBadgeClass(status: string): string {
  return STATUS_CLASSES[status] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300'
}

function statusLabel(status: string): string {
  return props.statuses[status] ?? status.replace(/_/g, ' ')
}

// ── KPI card color map ────────────────────────────────────────────────────

const KPI_COLORS: Record<string, { bg: string; text: string; border: string }> = {
  blue:   { bg: 'bg-blue-50 dark:bg-blue-900/20',   text: 'text-blue-700 dark:text-blue-300',   border: 'border-blue-200 dark:border-blue-800' },
  amber:  { bg: 'bg-amber-50 dark:bg-amber-900/20', text: 'text-amber-700 dark:text-amber-300', border: 'border-amber-200 dark:border-amber-800' },
  red:    { bg: 'bg-red-50 dark:bg-red-900/20',     text: 'text-red-700 dark:text-red-300',     border: 'border-red-200 dark:border-red-800' },
  green:  { bg: 'bg-green-50 dark:bg-green-900/20', text: 'text-green-700 dark:text-green-300', border: 'border-green-200 dark:border-green-800' },
  purple: { bg: 'bg-purple-50 dark:bg-purple-900/20', text: 'text-purple-700 dark:text-purple-300', border: 'border-purple-200 dark:border-purple-800' },
  slate:  { bg: 'bg-slate-50 dark:bg-slate-800',    text: 'text-slate-700 dark:text-slate-300', border: 'border-slate-200 dark:border-slate-700' },
}

interface KpiDef {
  label: string
  value: number
  sublabel: string
  color: string
  alert: boolean
}

const kpiCards = computed<KpiDef[]>(() => [
  { label: 'Open Incidents',          value: props.kpis.open_incidents_count,              sublabel: 'Require attention',          color: props.kpis.open_incidents_count > 0 ? 'red' : 'green',   alert: props.kpis.open_incidents_count > 0 },
  { label: 'Unsigned Notes > 24h',    value: props.kpis.unsigned_notes_count,              sublabel: 'Clinical documentation',     color: props.kpis.unsigned_notes_count > 0 ? 'amber' : 'green', alert: props.kpis.unsigned_notes_count > 0 },
  { label: 'Overdue Assessments',     value: props.kpis.overdue_assessments_count,         sublabel: 'Past due date',              color: props.kpis.overdue_assessments_count > 0 ? 'amber' : 'green', alert: props.kpis.overdue_assessments_count > 0 },
  { label: 'Open Grievances',         value: props.kpis.open_grievances_count,             sublabel: 'Active grievance queue',     color: props.kpis.open_grievances_count > 0 ? 'amber' : 'green', alert: props.kpis.open_grievances_count > 0 },
  { label: 'Active QAPI Projects',    value: props.kpis.active_qapi_count,                 sublabel: '42 CFR §460.140',            color: 'blue',   alert: false },
  { label: 'Care Plans Overdue',      value: props.kpis.overdue_care_plans_count,          sublabel: 'Within 30 days',             color: props.kpis.overdue_care_plans_count > 0 ? 'amber' : 'green', alert: props.kpis.overdue_care_plans_count > 0 },
  { label: 'SDR Compliance',          value: props.kpis.sdr_compliance_rate,               sublabel: '72h rule %',                 color: props.kpis.sdr_compliance_rate >= 90 ? 'green' : props.kpis.sdr_compliance_rate >= 75 ? 'amber' : 'red', alert: props.kpis.sdr_compliance_rate < 75 },
  { label: 'Hospitalizations',        value: props.kpis.hospitalizations_month,            sublabel: 'This month',                 color: 'slate',  alert: false },
  { label: 'Missing NPP',             value: props.kpis.missing_npp_count,                 sublabel: 'Consent on file',            color: props.kpis.missing_npp_count > 0 ? 'amber' : 'green', alert: props.kpis.missing_npp_count > 0 },
  { label: 'CMS Disenrollment',       value: props.kpis.pending_cms_disenrollment_count,   sublabel: 'Pending notification',       color: props.kpis.pending_cms_disenrollment_count > 0 ? 'red' : 'green', alert: props.kpis.pending_cms_disenrollment_count > 0 },
  { label: 'CMS Notif. Overdue',      value: props.kpis.cms_notification_overdue_count,    sublabel: '72h deadline',               color: props.kpis.cms_notification_overdue_count > 0 ? 'red' : 'green', alert: props.kpis.cms_notification_overdue_count > 0 },
])

// ── CSV export ────────────────────────────────────────────────────────────

function exportCsv(type: string) {
  window.location.href = `/qa/reports/export?type=${type}`
}

// ── Date helper ───────────────────────────────────────────────────────────

function fmt(dateStr: string | null): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString()
}
</script>

<template>
  <AppShell>
    <Head title="QA / Compliance Dashboard" />

    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

      <!-- Page header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">QA / Compliance Dashboard</h1>
          <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">42 CFR Part 460 compliance monitoring</p>
        </div>
        <div class="flex gap-2">
          <button
            @click="exportCsv('incidents')"
            class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-200 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 transition"
          >
            <ArrowDownTrayIcon class="w-4 h-4" />
            Export Incidents
          </button>
          <button
            @click="exportCsv('grievances')"
            class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-200 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 transition"
          >
            <ArrowDownTrayIcon class="w-4 h-4" />
            Export Grievances
          </button>
        </div>
      </div>

      <!-- KPI Cards -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4">
        <div
          v-for="kpi in kpiCards"
          :key="kpi.label"
          :class="[
            'rounded-xl border p-4 relative',
            KPI_COLORS[kpi.color]?.bg ?? 'bg-slate-50 dark:bg-slate-800',
            KPI_COLORS[kpi.color]?.border ?? 'border-slate-200 dark:border-slate-700',
          ]"
        >
          <div v-if="kpi.alert" class="absolute top-2 right-2">
            <ExclamationTriangleIcon class="w-4 h-4 text-current opacity-60" />
          </div>
          <p class="text-xs font-medium text-gray-600 dark:text-slate-400 leading-tight">{{ kpi.label }}</p>
          <p
            :class="[
              'mt-1 text-3xl font-bold tabular-nums',
              KPI_COLORS[kpi.color]?.text ?? 'text-slate-700 dark:text-slate-300',
            ]"
          >
            {{ kpi.value }}
          </p>
          <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-500">{{ kpi.sublabel }}</p>
        </div>
      </div>

      <!-- Security Posture Widget -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5">
        <div class="flex items-center gap-2 mb-4">
          <ShieldCheckIcon class="w-5 h-5 text-blue-600 dark:text-blue-400" />
          <h2 class="font-semibold text-gray-900 dark:text-slate-100">Security Posture</h2>
        </div>
        <div class="flex flex-wrap gap-3">
          <!-- BAA -->
          <div
            :class="[
              'flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium border',
              props.compliance_posture.baa_active
                ? 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 border-green-200 dark:border-green-800'
                : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800',
            ]"
          >
            <CheckCircleIcon v-if="props.compliance_posture.baa_active" class="w-4 h-4" />
            <ExclamationTriangleIcon v-else class="w-4 h-4" />
            BAA
            <span class="text-xs opacity-75">
              {{ props.compliance_posture.baa_active ? (props.compliance_posture.baa_expires_at ? 'Expires ' + fmt(props.compliance_posture.baa_expires_at) : 'Active') : 'Missing' }}
            </span>
          </div>
          <!-- SRA -->
          <div
            :class="[
              'flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium border',
              props.compliance_posture.sra_current
                ? 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 border-green-200 dark:border-green-800'
                : 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800',
            ]"
          >
            <CheckCircleIcon v-if="props.compliance_posture.sra_current" class="w-4 h-4" />
            <ExclamationTriangleIcon v-else class="w-4 h-4" />
            SRA
            <span class="text-xs opacity-75">
              {{ props.compliance_posture.sra_current ? (props.compliance_posture.sra_completed_at ? 'Completed ' + fmt(props.compliance_posture.sra_completed_at) : 'Current') : 'Due' }}
            </span>
          </div>
          <!-- Encryption -->
          <div
            :class="[
              'flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium border',
              props.compliance_posture.encryption_enabled
                ? 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 border-green-200 dark:border-green-800'
                : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800',
            ]"
          >
            <CheckCircleIcon v-if="props.compliance_posture.encryption_enabled" class="w-4 h-4" />
            <ExclamationTriangleIcon v-else class="w-4 h-4" />
            Encryption at Rest
            <span class="text-xs opacity-75">{{ props.compliance_posture.encryption_enabled ? 'Enabled' : 'Not Configured' }}</span>
          </div>
        </div>
      </div>

      <!-- Open Incident Queue -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 dark:border-slate-700">
          <div class="flex items-center gap-2">
            <ExclamationTriangleIcon class="w-5 h-5 text-red-500" />
            <h2 class="font-semibold text-gray-900 dark:text-slate-100">Open Incidents</h2>
            <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 font-medium">
              {{ props.openIncidents.length }}
            </span>
          </div>
          <button
            @click="exportCsv('incidents_open')"
            class="text-sm text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1"
          >
            <ArrowDownTrayIcon class="w-4 h-4" />
            Export
          </button>
        </div>

        <div v-if="props.openIncidents.length === 0" class="px-5 py-10 text-center text-gray-500 dark:text-slate-400 text-sm">
          No open incidents. All clear.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-slate-700/50 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">
                <th class="px-4 py-3 text-left">Reference</th>
                <th class="px-4 py-3 text-left">Type</th>
                <th class="px-4 py-3 text-left">Participant</th>
                <th class="px-4 py-3 text-left">Occurred</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">RCA</th>
                <th class="px-4 py-3 text-left"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
              <tr
                v-for="incident in props.openIncidents"
                :key="incident.id"
                class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition"
              >
                <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-slate-300">{{ incident.reference_number }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-slate-300">{{ props.incidentTypes[incident.incident_type] ?? incident.incident_type }}</td>
                <td class="px-4 py-3 text-gray-700 dark:text-slate-300">{{ incident.participant_name }}</td>
                <td class="px-4 py-3 text-gray-500 dark:text-slate-400">{{ fmt(incident.occurred_at) }}</td>
                <td class="px-4 py-3">
                  <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', statusBadgeClass(incident.status)]">
                    {{ statusLabel(incident.status) }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <span v-if="incident.rca_required && !incident.rca_completed_at" class="flex items-center gap-1 text-amber-600 dark:text-amber-400 text-xs font-medium">
                    <ClockIcon class="w-3.5 h-3.5" />
                    Pending
                  </span>
                  <span v-else-if="incident.rca_required && incident.rca_completed_at" class="flex items-center gap-1 text-green-600 dark:text-green-400 text-xs font-medium">
                    <CheckCircleIcon class="w-3.5 h-3.5" />
                    Done
                  </span>
                  <span v-else class="text-gray-400 dark:text-slate-500 text-xs">N/A</span>
                </td>
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2">
                    <span
                      v-if="incident.is_sentinel"
                      class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border border-red-300 dark:border-red-700"
                      title="Classified as sentinel event"
                    >SENTINEL</span>
                    <button
                      v-else-if="canClassifySentinel"
                      type="button"
                      class="text-xs px-2 py-0.5 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 rounded hover:bg-red-50 dark:hover:bg-red-900/20"
                      @click="openSentinelModal(incident)"
                      data-testid="sentinel-classify-btn"
                    >Classify sentinel</button>
                    <a
                      :href="`/qa/incidents/${incident.id}`"
                      class="text-blue-600 dark:text-blue-400 hover:underline text-xs flex items-center gap-0.5"
                    >
                      View <ChevronRightIcon class="w-3 h-3" />
                    </a>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Compliance Tabs -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700">
        <div class="flex border-b border-gray-200 dark:border-slate-700 overflow-x-auto">
          <button
            v-for="tab in [
              { key: 'incidents',             label: 'All Incidents' },
              { key: 'unsigned_notes',        label: 'Unsigned Notes' },
              { key: 'overdue_assessments',   label: 'Overdue Assessments' },
              { key: 'grievances',            label: 'Open Grievances' },
            ]"
            :key="tab.key"
            @click="switchTab(tab.key as ComplianceTab)"
            :class="[
              'px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition',
              complianceTab === tab.key
                ? 'border-blue-600 text-blue-600 dark:text-blue-400 dark:border-blue-400'
                : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200',
            ]"
          >
            {{ tab.label }}
          </button>
        </div>

        <div class="p-5">

          <!-- All Incidents tab (uses openIncidents data) -->
          <div v-if="complianceTab === 'incidents'">
            <p class="text-sm text-gray-500 dark:text-slate-400 mb-4">All open incidents are shown in the queue above. Navigate to an individual incident to view details and RCA documentation.</p>
            <div class="flex gap-3">
              <button
                @click="exportCsv('incidents')"
                class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition"
              >
                <ArrowDownTrayIcon class="w-4 h-4" />
                Export All Incidents CSV
              </button>
            </div>
          </div>

          <!-- Unsigned Notes tab -->
          <div v-else-if="complianceTab === 'unsigned_notes'">
            <div v-if="unsignedNotesLoading" class="py-8 text-center text-gray-500 dark:text-slate-400 text-sm">Loading...</div>
            <div v-else-if="unsignedNotesLoaded && unsignedNotes.length === 0" class="py-8 text-center text-green-600 dark:text-green-400 text-sm flex flex-col items-center gap-2">
              <CheckCircleIcon class="w-8 h-8" />
              No notes unsigned beyond 24 hours.
            </div>
            <template v-else-if="unsignedNotesLoaded">
              <div class="flex items-center justify-between mb-4">
                <p class="text-sm text-gray-500 dark:text-slate-400">{{ unsignedNotes.length }} notes unsigned beyond 24 hours</p>
                <button @click="exportCsv('unsigned_notes')" class="text-sm text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1">
                  <ArrowDownTrayIcon class="w-4 h-4" /> Export
                </button>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-gray-50 dark:bg-slate-700/50 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase">
                      <th class="px-4 py-3 text-left">Note Type</th>
                      <th class="px-4 py-3 text-left">Participant</th>
                      <th class="px-4 py-3 text-left">Author</th>
                      <th class="px-4 py-3 text-left">Created</th>
                      <th class="px-4 py-3 text-left">Hours Overdue</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                    <tr v-for="note in unsignedNotes" :key="note.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                      <td class="px-4 py-3 text-gray-700 dark:text-slate-300">{{ note.note_type }}</td>
                      <td class="px-4 py-3 text-gray-700 dark:text-slate-300">{{ note.participant_name }}</td>
                      <td class="px-4 py-3 text-gray-500 dark:text-slate-400">{{ note.author_name }}</td>
                      <td class="px-4 py-3 text-gray-500 dark:text-slate-400">{{ fmt(note.created_at) }}</td>
                      <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                          {{ note.hours_overdue }}h
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </template>
          </div>

          <!-- Overdue Assessments tab -->
          <div v-else-if="complianceTab === 'overdue_assessments'">
            <div v-if="overdueAssessmentsLoading" class="py-8 text-center text-gray-500 dark:text-slate-400 text-sm">Loading...</div>
            <div v-else-if="overdueAssessmentsLoaded && overdueAssessments.length === 0" class="py-8 text-center text-green-600 dark:text-green-400 text-sm flex flex-col items-center gap-2">
              <CheckCircleIcon class="w-8 h-8" />
              No overdue assessments.
            </div>
            <template v-else-if="overdueAssessmentsLoaded">
              <div class="flex items-center justify-between mb-4">
                <p class="text-sm text-gray-500 dark:text-slate-400">{{ overdueAssessments.length }} overdue assessments</p>
                <button @click="exportCsv('overdue_assessments')" class="text-sm text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1">
                  <ArrowDownTrayIcon class="w-4 h-4" /> Export
                </button>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-gray-50 dark:bg-slate-700/50 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase">
                      <th class="px-4 py-3 text-left">Assessment Type</th>
                      <th class="px-4 py-3 text-left">Participant</th>
                      <th class="px-4 py-3 text-left">Due Date</th>
                      <th class="px-4 py-3 text-left">Days Overdue</th>
                      <th class="px-4 py-3 text-left">Assigned To</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                    <tr v-for="a in overdueAssessments" :key="a.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                      <td class="px-4 py-3 text-gray-700 dark:text-slate-300">{{ a.assessment_type }}</td>
                      <td class="px-4 py-3 text-gray-700 dark:text-slate-300">{{ a.participant_name }}</td>
                      <td class="px-4 py-3 text-gray-500 dark:text-slate-400">{{ fmt(a.due_date) }}</td>
                      <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
                          {{ a.days_overdue }}d
                        </span>
                      </td>
                      <td class="px-4 py-3 text-gray-500 dark:text-slate-400">{{ a.assigned_to }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </template>
          </div>

          <!-- Open Grievances tab -->
          <div v-else-if="complianceTab === 'grievances'">
            <div v-if="grievancesLoading" class="py-8 text-center text-gray-500 dark:text-slate-400 text-sm">Loading...</div>
            <div v-else-if="grievancesLoaded && grievances.length === 0" class="py-8 text-center text-green-600 dark:text-green-400 text-sm flex flex-col items-center gap-2">
              <CheckCircleIcon class="w-8 h-8" />
              No open grievances.
            </div>
            <template v-else-if="grievancesLoaded">
              <div class="flex items-center justify-between mb-4">
                <p class="text-sm text-gray-500 dark:text-slate-400">{{ grievances.length }} open grievances</p>
                <button @click="exportCsv('grievances')" class="text-sm text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1">
                  <ArrowDownTrayIcon class="w-4 h-4" /> Export
                </button>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="bg-gray-50 dark:bg-slate-700/50 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase">
                      <th class="px-4 py-3 text-left">Reference</th>
                      <th class="px-4 py-3 text-left">Participant</th>
                      <th class="px-4 py-3 text-left">Category</th>
                      <th class="px-4 py-3 text-left">Filed</th>
                      <th class="px-4 py-3 text-left">Priority</th>
                      <th class="px-4 py-3 text-left">Status</th>
                      <th class="px-4 py-3"></th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                    <tr v-for="g in grievances" :key="g.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/40 cursor-pointer" @click="router.visit(`/grievances/${g.id}`)">
                      <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-slate-300">{{ g.reference_number }}</td>
                      <td class="px-4 py-3 text-gray-700 dark:text-slate-300">{{ g.participant_name }}</td>
                      <td class="px-4 py-3 text-gray-700 dark:text-slate-300">{{ g.category }}</td>
                      <td class="px-4 py-3 text-gray-500 dark:text-slate-400">{{ fmt(g.filed_at) }}</td>
                      <td class="px-4 py-3">
                        <span :class="[
                          'px-2 py-0.5 rounded-full text-xs font-medium',
                          g.priority === 'urgent'
                            ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
                            : 'bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-300',
                        ]">{{ g.priority }}</span>
                      </td>
                      <td class="px-4 py-3">
                        <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', statusBadgeClass(g.status)]">
                          {{ g.status.replace(/_/g, ' ') }}
                        </span>
                      </td>
                      <td class="px-4 py-3">
                        <ChevronRightIcon class="w-4 h-4 text-gray-400 dark:text-slate-500" />
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </template>
          </div>

        </div>
      </div>

    </div>

    <!-- Phase I2: Sentinel classify modal -->
    <Teleport to="body">
      <div v-if="sentinelOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="closeSentinelModal">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg p-6">
          <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Classify incident as sentinel event</h2>
          <p class="text-xs text-gray-500 dark:text-slate-400 mb-4">
            Incident {{ sentinelTarget?.reference_number }} ·
            {{ props.incidentTypes[sentinelTarget?.incident_type ?? ''] ?? sentinelTarget?.incident_type }} ·
            {{ sentinelTarget?.participant_name }}
          </p>
          <p class="text-xs text-gray-600 dark:text-slate-300 mb-4 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded p-2">
            42 CFR §460.136. Classification auto-sets a 5-day CMS notification deadline and a 30-day RCA completion deadline. Missed deadlines escalate to executive.
          </p>

          <form @submit.prevent="submitSentinel" class="space-y-3">
            <div>
              <label class="text-xs font-medium text-gray-600 dark:text-slate-400 block">Classification reason (≥10 chars)</label>
              <textarea v-model="sentinelReason" rows="3"
                class="mt-1 w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-900 dark:text-slate-100"
                placeholder="Why does this incident meet the sentinel-event criteria?"
                data-testid="sentinel-reason-input"
                required
              />
            </div>

            <p v-if="sentinelError" class="text-xs text-red-600 dark:text-red-400">{{ sentinelError }}</p>

            <div class="flex items-center justify-end gap-2 pt-2">
              <button type="button" class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700" @click="closeSentinelModal">Cancel</button>
              <button type="submit" :disabled="sentinelSubmitting" class="text-xs px-3 py-1.5 bg-red-600 text-white rounded hover:bg-red-700 disabled:opacity-50">
                {{ sentinelSubmitting ? 'Classifying…' : 'Classify as sentinel' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </AppShell>
</template>
