<!--
  Alerts List — shows active and dismissed alerts with severity and read filters.
  All data is loaded via axios (no Inertia props). Users can acknowledge alerts
  (marks as read) and dismiss them (removes from active list). Alert rows link
  to the relevant participant or chat channel.
-->
<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Head, usePage, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
  ExclamationTriangleIcon,
  InformationCircleIcon,
  BellIcon,
  CheckIcon,
  XMarkIcon,
  ChevronRightIcon,
  ArrowPathIcon,
} from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────

interface Alert {
  id: number
  alert_type: string
  severity: 'critical' | 'warning' | 'info'
  title: string
  message: string
  participant_id: number | null
  participant_name: string | null
  source_module: string | null
  channel_id: number | null
  is_active: boolean
  acknowledged_at: string | null
  resolved_at: string | null
  created_at: string
}

// ── View state ────────────────────────────────────────────────────────────

type ViewTab = 'active' | 'dismissed'
type SeverityFilter = 'all' | 'critical' | 'warning' | 'info'
type ReadFilter = 'all' | 'unread'

const viewTab = ref<ViewTab>('active')
const severityFilter = ref<SeverityFilter>('all')
const readFilter = ref<ReadFilter>('all')

const alerts = ref<Alert[]>([])
const loading = ref(false)
const loaded = ref(false)

// ── Load alerts ───────────────────────────────────────────────────────────

async function loadAlerts() {
  loading.value = true
  try {
    const params: Record<string, string> = {
      status: viewTab.value,
      per_page: '100',
    }
    if (severityFilter.value !== 'all') params.severity = severityFilter.value
    if (readFilter.value === 'unread' && viewTab.value === 'active') params.unread_only = '1'

    const query = new URLSearchParams(params).toString()
    const res = await axios.get(`/alerts?${query}`)
    alerts.value = res.data.data ?? res.data
    loaded.value = true
  } finally {
    loading.value = false
  }
}

onMounted(() => loadAlerts())

watch([viewTab, severityFilter, readFilter], () => loadAlerts())

// ── Switch view tab (resets sub-filters) ─────────────────────────────────

function switchView(tab: ViewTab) {
  viewTab.value = tab
  severityFilter.value = 'all'
  readFilter.value = 'all'
}

// ── Acknowledge ───────────────────────────────────────────────────────────

async function acknowledge(alert: Alert) {
  await axios.patch(`/alerts/${alert.id}/acknowledge`)
  alert.acknowledged_at = new Date().toISOString()
}

// ── Dismiss ───────────────────────────────────────────────────────────────

async function dismiss(alert: Alert) {
  await axios.patch(`/alerts/${alert.id}/resolve`)
  alerts.value = alerts.value.filter(a => a.id !== alert.id)
}

// ── Alert href ────────────────────────────────────────────────────────────

function alertHref(alert: Alert): string | null {
  if (alert.source_module === 'chat' && alert.channel_id) {
    return `/chat?channel=${alert.channel_id}`
  }
  if (alert.participant_id) {
    return `/participants/${alert.participant_id}`
  }
  return null
}

// ── Severity styling ──────────────────────────────────────────────────────

const SEVERITY_ROW: Record<string, string> = {
  critical: 'border-l-4 border-red-500',
  warning:  'border-l-4 border-amber-500',
  info:     'border-l-4 border-blue-400',
}

const SEVERITY_BADGE: Record<string, string> = {
  critical: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
  warning:  'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
  info:     'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
}

function severityRowClass(severity: string): string {
  return SEVERITY_ROW[severity] ?? ''
}

function severityBadgeClass(severity: string): string {
  return SEVERITY_BADGE[severity] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300'
}

// ── Time ago ──────────────────────────────────────────────────────────────

function timeAgo(dateStr: string): string {
  const diffMs = Date.now() - new Date(dateStr).getTime()
  const diffMins = Math.floor(diffMs / 60000)
  if (diffMins < 1) return 'just now'
  if (diffMins < 60) return `${diffMins}m ago`
  const diffHrs = Math.floor(diffMins / 60)
  if (diffHrs < 24) return `${diffHrs}h ago`
  const diffDays = Math.floor(diffHrs / 24)
  return `${diffDays}d ago`
}

// ── Unread count ──────────────────────────────────────────────────────────

const unreadCount = computed(() =>
  alerts.value.filter(a => a.is_active && !a.acknowledged_at).length
)
</script>

<template>
  <AppShell>
    <Head title="Alerts" />

    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Alerts</h1>
          <span
            v-if="viewTab === 'active' && unreadCount > 0"
            class="px-2.5 py-1 rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 text-sm font-semibold"
          >
            {{ unreadCount }} unread
          </span>
        </div>
        <button
          @click="loadAlerts()"
          :disabled="loading"
          class="inline-flex items-center gap-1.5 px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-300 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 transition"
        >
          <ArrowPathIcon :class="['w-4 h-4', loading && 'animate-spin']" />
          Refresh
        </button>
      </div>

      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700">

        <!-- Tab bar + filters -->
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-gray-200 dark:border-slate-700">

          <!-- View tabs -->
          <div class="flex">
            <button
              v-for="tab in [{ key: 'active' as ViewTab, label: 'Active' }, { key: 'dismissed' as ViewTab, label: 'Dismissed (30d)' }]"
              :key="tab.key"
              @click="switchView(tab.key)"
              :class="[
                'px-4 py-2 text-sm font-medium border-b-2 transition',
                viewTab === tab.key
                  ? 'border-blue-600 text-blue-600 dark:text-blue-400 dark:border-blue-400'
                  : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200',
              ]"
            >
              {{ tab.label }}
            </button>
          </div>

          <!-- Filters -->
          <div class="flex flex-wrap items-center gap-2">
            <!-- Severity filter -->
            <div class="flex rounded-lg border border-gray-200 dark:border-slate-700 overflow-hidden text-xs">
              <button
                v-for="s in ['all', 'critical', 'warning', 'info'] as SeverityFilter[]"
                :key="s"
                @click="severityFilter = s"
                :class="[
                  'px-3 py-1.5 font-medium transition capitalize',
                  severityFilter === s
                    ? 'bg-blue-600 text-white'
                    : 'text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700',
                ]"
              >
                {{ s }}
              </button>
            </div>

            <!-- Read filter (active tab only) -->
            <div v-if="viewTab === 'active'" class="flex rounded-lg border border-gray-200 dark:border-slate-700 overflow-hidden text-xs">
              <button
                v-for="r in ['all', 'unread'] as ReadFilter[]"
                :key="r"
                @click="readFilter = r"
                :class="[
                  'px-3 py-1.5 font-medium transition capitalize',
                  readFilter === r
                    ? 'bg-blue-600 text-white'
                    : 'text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700',
                ]"
              >
                {{ r }}
              </button>
            </div>
          </div>
        </div>

        <!-- Loading state -->
        <div v-if="loading" class="py-14 text-center text-sm text-gray-500 dark:text-slate-400">
          Loading alerts...
        </div>

        <!-- Empty state -->
        <div v-else-if="loaded && alerts.length === 0" class="py-14 text-center space-y-2">
          <BellIcon class="w-10 h-10 mx-auto text-gray-300 dark:text-slate-600" />
          <p class="text-sm text-gray-500 dark:text-slate-400">No alerts found.</p>
        </div>

        <!-- Alert list -->
        <ul v-else class="divide-y divide-gray-100 dark:divide-slate-700">
          <li
            v-for="alert in alerts"
            :key="alert.id"
            :class="[
              'px-5 py-4 transition',
              severityRowClass(alert.severity),
              !alert.acknowledged_at && alert.is_active ? 'bg-blue-50/30 dark:bg-blue-900/5' : '',
              'hover:bg-gray-50 dark:hover:bg-slate-700/40',
            ]"
          >
            <div class="flex items-start gap-4">

              <!-- Severity icon -->
              <div class="shrink-0 mt-0.5">
                <ExclamationTriangleIcon
                  v-if="alert.severity === 'critical' || alert.severity === 'warning'"
                  :class="[
                    'w-5 h-5',
                    alert.severity === 'critical' ? 'text-red-500' : 'text-amber-500',
                  ]"
                />
                <InformationCircleIcon v-else class="w-5 h-5 text-blue-500" />
              </div>

              <!-- Content -->
              <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                  <!-- Severity badge -->
                  <span :class="['px-2 py-0.5 rounded-full text-xs font-medium capitalize', severityBadgeClass(alert.severity)]">
                    {{ alert.severity }}
                  </span>
                  <!-- Alert type -->
                  <span class="text-xs font-medium text-gray-600 dark:text-slate-400 uppercase tracking-wide">
                    {{ alert.alert_type.replace(/_/g, ' ') }}
                  </span>
                  <!-- Source module badge -->
                  <span
                    v-if="alert.source_module"
                    class="px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-400"
                  >
                    {{ alert.source_module }}
                  </span>
                  <!-- Unread dot -->
                  <span v-if="!alert.acknowledged_at && alert.is_active" class="w-2 h-2 rounded-full bg-blue-500 inline-block" title="Unread" />
                </div>

                <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ alert.title }}</p>
                <p class="text-sm text-gray-600 dark:text-slate-400 mt-0.5">{{ alert.message }}</p>

                <!-- Participant + time -->
                <div class="flex flex-wrap items-center gap-3 mt-2 text-xs text-gray-500 dark:text-slate-400">
                  <span v-if="alert.participant_name">
                    Participant: <span class="font-medium text-gray-700 dark:text-slate-300">{{ alert.participant_name }}</span>
                  </span>
                  <span>{{ timeAgo(alert.created_at) }}</span>
                  <!-- Link -->
                  <a
                    v-if="alertHref(alert)"
                    :href="alertHref(alert)!"
                    class="inline-flex items-center gap-0.5 text-blue-600 dark:text-blue-400 hover:underline font-medium"
                    @click.stop
                  >
                    View <ChevronRightIcon class="w-3 h-3" />
                  </a>
                </div>
              </div>

              <!-- Actions -->
              <div class="shrink-0 flex items-center gap-1.5">
                <!-- Acknowledge -->
                <button
                  v-if="alert.is_active && !alert.acknowledged_at"
                  @click="acknowledge(alert)"
                  class="p-1.5 rounded-lg text-gray-400 dark:text-slate-500 hover:text-green-600 dark:hover:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20 transition"
                  title="Mark as read"
                >
                  <CheckIcon class="w-4 h-4" />
                </button>
                <!-- Acknowledged indicator -->
                <span
                  v-else-if="alert.acknowledged_at && alert.is_active"
                  class="p-1.5 rounded-lg text-green-500 dark:text-green-400"
                  title="Acknowledged"
                >
                  <CheckIcon class="w-4 h-4" />
                </span>
                <!-- Dismiss -->
                <button
                  v-if="alert.is_active"
                  @click="dismiss(alert)"
                  class="p-1.5 rounded-lg text-gray-400 dark:text-slate-500 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                  title="Dismiss"
                >
                  <XMarkIcon class="w-4 h-4" />
                </button>
              </div>

            </div>
          </li>
        </ul>

      </div>
    </div>
  </AppShell>
</template>
