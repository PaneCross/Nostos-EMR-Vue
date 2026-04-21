<!--
  QAPI Projects Board — Quality Assurance and Performance Improvement.
  Displays a Kanban board with 5 status columns per 42 CFR §460.136-§460.140.
  QA admins can create, edit, advance status, or suspend projects.
  A compliance banner shows whether the minimum active project count is met.
-->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, usePage, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
  PlusIcon,
  XMarkIcon,
  PencilSquareIcon,
  ChevronRightIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  InformationCircleIcon,
} from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────

interface Project {
  id: number
  title: string
  domain: string
  status: string
  aim_statement: string
  start_date: string | null
  target_date: string | null
  lead_name: string
  current_metric: string | null
  baseline_metric: string | null
  target_metric: string | null
  notes: string | null
}

// ── Props ──────────────────────────────────────────────────────────────────

const props = defineProps<{
  projects: Project[]
  active_count: number
  meets_minimum: boolean
  min_required: number
  statuses: Record<string, string>
  domains: Record<string, string>
}>()

// ── Auth ───────────────────────────────────────────────────────────────────

const page = usePage()
const user = computed(() => (page.props.auth as any)?.user)
const isQaAdmin = computed(() =>
  user.value?.department === 'qa_compliance' ||
  user.value?.department === 'it_admin' ||
  !!user.value?.is_super_admin
)

// ── Status rows (ladder layout) ───────────────────────────────────────────
// Displayed as a vertical stack — one row per status. Cards within a row
// wrap responsively to fit the available width. This avoids the horizontal
// scrollbar the old Kanban-columns layout produced on standard displays.

const COLUMNS = [
  { key: 'planning',     label: 'Planning',    accent: 'border-l-blue-400 dark:border-l-blue-500' },
  { key: 'active',       label: 'Active',      accent: 'border-l-green-500 dark:border-l-green-400' },
  { key: 'remeasuring',  label: 'Remeasuring', accent: 'border-l-amber-500 dark:border-l-amber-400' },
  { key: 'completed',    label: 'Completed',   accent: 'border-l-slate-400 dark:border-l-slate-500' },
  { key: 'suspended',    label: 'Suspended',   accent: 'border-l-red-500 dark:border-l-red-400' },
]

function projectsInColumn(colKey: string): Project[] {
  return props.projects.filter(p => p.status === colKey)
}

// ── Domain badge colors ───────────────────────────────────────────────────

const DOMAIN_COLORS: Record<string, string> = {
  clinical:      'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
  safety:        'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
  pharmacy:      'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
  administrative:'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300',
  care_planning: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
  enrollment:    'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
  finance:       'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
}

function domainClass(domain: string): string {
  return DOMAIN_COLORS[domain] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300'
}

// ── Status badge colors ───────────────────────────────────────────────────

const STATUS_BADGE: Record<string, string> = {
  planning:    'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
  active:      'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
  remeasuring: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
  completed:   'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300',
  suspended:   'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
}

// ── Detail modal ──────────────────────────────────────────────────────────

const detailProject = ref<Project | null>(null)
const actionLoading = ref(false)

function openDetail(p: Project) {
  detailProject.value = p
}

function closeDetail() {
  detailProject.value = null
}

async function advanceStatus(project: Project, newStatus: string, endpoint?: string) {
  actionLoading.value = true
  try {
    if (endpoint) {
      await axios.post(`/qapi/projects/${project.id}/${endpoint}`)
    } else {
      await axios.patch(`/qapi/projects/${project.id}`, { status: newStatus })
    }
    closeDetail()
    router.reload()
  } finally {
    actionLoading.value = false
  }
}

// ── Create/Edit modal ─────────────────────────────────────────────────────

const showFormModal = ref(false)
const editingProject = ref<Project | null>(null)

const form = ref({
  title: '',
  domain: '',
  aim_statement: '',
  start_date: '',
  target_date: '',
  lead_name: '',
  baseline_metric: '',
  target_metric: '',
  current_metric: '',
  notes: '',
})

const formErrors = ref<Record<string, string>>({})
const formLoading = ref(false)

function openCreate() {
  editingProject.value = null
  form.value = {
    title: '',
    domain: '',
    aim_statement: '',
    start_date: '',
    target_date: '',
    lead_name: '',
    baseline_metric: '',
    target_metric: '',
    current_metric: '',
    notes: '',
  }
  formErrors.value = {}
  showFormModal.value = true
}

function openEdit(p: Project) {
  editingProject.value = p
  form.value = {
    title: p.title,
    domain: p.domain,
    aim_statement: p.aim_statement,
    start_date: p.start_date ?? '',
    target_date: p.target_date ?? '',
    lead_name: p.lead_name,
    baseline_metric: p.baseline_metric ?? '',
    target_metric: p.target_metric ?? '',
    current_metric: p.current_metric ?? '',
    notes: p.notes ?? '',
  }
  formErrors.value = {}
  showFormModal.value = true
  closeDetail()
}

function closeForm() {
  showFormModal.value = false
  editingProject.value = null
}

async function submitForm() {
  formLoading.value = true
  formErrors.value = {}
  try {
    if (editingProject.value) {
      await axios.patch(`/qapi/projects/${editingProject.value.id}`, form.value)
    } else {
      await axios.post('/qapi/projects', form.value)
    }
    closeForm()
    router.reload()
  } catch (err: any) {
    if (err.response?.status === 422) {
      formErrors.value = err.response.data.errors ?? {}
    }
  } finally {
    formLoading.value = false
  }
}

// ── Date format helper ────────────────────────────────────────────────────

function fmt(d: string | null): string {
  if (!d) return '-'
  return new Date(d).toLocaleDateString()
}
</script>

<template>
  <AppShell>
    <Head title="QAPI Projects" />

    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">QAPI Projects</h1>
          <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">Quality Assurance and Performance Improvement — 42 CFR §460.136–§460.140</p>
        </div>
        <button
          v-if="isQaAdmin"
          @click="openCreate()"
          class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition"
        >
          <PlusIcon class="w-4 h-4" />
          New Project
        </button>
      </div>

      <!-- Compliance minimum banner -->
      <div
        :class="[
          'flex items-start gap-3 rounded-xl border px-5 py-4',
          props.meets_minimum
            ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300'
            : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300',
        ]"
      >
        <CheckCircleIcon v-if="props.meets_minimum" class="w-5 h-5 shrink-0 mt-0.5" />
        <ExclamationTriangleIcon v-else class="w-5 h-5 shrink-0 mt-0.5" />
        <div class="text-sm">
          <span class="font-semibold">
            {{ props.meets_minimum ? 'Compliance Minimum Met' : 'Below Compliance Minimum' }}
          </span>
          — {{ props.active_count }} active project{{ props.active_count !== 1 ? 's' : '' }}
          (minimum required: {{ props.min_required }})
        </div>
      </div>

      <!-- Status ladder: one row per status, cards wrap responsively inside -->
      <div class="space-y-5">
        <section
          v-for="col in COLUMNS"
          :key="col.key"
          :class="['bg-gray-50 dark:bg-slate-800/60 rounded-xl border border-gray-200 dark:border-slate-700 border-l-4', col.accent]"
        >
          <!-- Row header -->
          <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200 dark:border-slate-700">
            <div class="flex items-center gap-3">
              <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-200 uppercase tracking-wide">{{ col.label }}</h3>
              <span class="text-xs px-2 py-0.5 rounded-full bg-gray-200 dark:bg-slate-700 text-gray-600 dark:text-slate-400 font-semibold tabular-nums">
                {{ projectsInColumn(col.key).length }}
              </span>
            </div>
          </div>

          <!-- Cards grid — responsive: 1 col mobile, 2 md, 3 lg, 4 xl -->
          <div class="px-4 py-4">
            <div
              v-if="projectsInColumn(col.key).length === 0"
              class="py-4 text-center text-xs text-gray-400 dark:text-slate-500 italic"
            >
              No projects in this status
            </div>
            <div
              v-else
              class="grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
              <div
                v-for="project in projectsInColumn(col.key)"
                :key="project.id"
                class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-3 cursor-pointer hover:shadow-md hover:border-blue-300 dark:hover:border-blue-700 transition"
                @click="openDetail(project)"
              >
                <!-- Domain badge -->
                <span :class="['inline-block px-2 py-0.5 rounded-full text-xs font-medium mb-2', domainClass(project.domain)]">
                  {{ props.domains[project.domain] ?? project.domain }}
                </span>

                <h4 class="text-sm font-semibold text-gray-900 dark:text-slate-100 leading-snug line-clamp-2">{{ project.title }}</h4>

                <p v-if="project.aim_statement" class="mt-1 text-xs text-gray-500 dark:text-slate-400 line-clamp-2">
                  {{ project.aim_statement }}
                </p>

                <div class="mt-2 space-y-1 text-xs text-gray-500 dark:text-slate-400">
                  <div v-if="project.lead_name" class="truncate">
                    Lead: <span class="text-gray-700 dark:text-slate-300">{{ project.lead_name }}</span>
                  </div>
                  <div v-if="project.current_metric" class="truncate">
                    Current: <span class="font-medium text-gray-700 dark:text-slate-300">{{ project.current_metric }}</span>
                  </div>
                  <div v-if="project.target_date">
                    Target: {{ fmt(project.target_date) }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>

    <!-- Project Detail Modal -->
    <Teleport to="body">
      <div
        v-if="detailProject"
        class="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4 bg-black/50"
        @click.self="closeDetail()"
      >
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-xl max-h-[80vh] overflow-y-auto">
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700">
            <h2 class="font-semibold text-gray-900 dark:text-slate-100">Project Details</h2>
            <div class="flex items-center gap-2">
              <button
                v-if="isQaAdmin"
                @click="openEdit(detailProject!)"
                class="text-sm text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1"
              >
                <PencilSquareIcon class="w-4 h-4" />
                Edit
              </button>
              <button @click="closeDetail()" class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>
          </div>

          <div class="px-6 py-5 space-y-4">
            <!-- Title + badges -->
            <div>
              <div class="flex flex-wrap gap-2 mb-2">
                <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', domainClass(detailProject.domain)]">
                  {{ props.domains[detailProject.domain] ?? detailProject.domain }}
                </span>
                <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', STATUS_BADGE[detailProject.status] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300']">
                  {{ props.statuses[detailProject.status] ?? detailProject.status }}
                </span>
              </div>
              <h3 class="text-lg font-bold text-gray-900 dark:text-slate-100">{{ detailProject.title }}</h3>
            </div>

            <!-- Aim statement -->
            <div>
              <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase mb-1">Aim Statement</p>
              <p class="text-sm text-gray-700 dark:text-slate-300">{{ detailProject.aim_statement || '-' }}</p>
            </div>

            <!-- Meta grid -->
            <div class="grid grid-cols-2 gap-3 text-sm">
              <div>
                <p class="text-xs text-gray-500 dark:text-slate-400">Lead</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ detailProject.lead_name || '-' }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500 dark:text-slate-400">Start Date</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ fmt(detailProject.start_date) }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500 dark:text-slate-400">Target Date</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ fmt(detailProject.target_date) }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500 dark:text-slate-400">Baseline Metric</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ detailProject.baseline_metric || '-' }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500 dark:text-slate-400">Current Metric</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ detailProject.current_metric || '-' }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500 dark:text-slate-400">Target Metric</p>
                <p class="font-medium text-gray-800 dark:text-slate-200">{{ detailProject.target_metric || '-' }}</p>
              </div>
            </div>

            <!-- Notes -->
            <div v-if="detailProject.notes">
              <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase mb-1">Notes</p>
              <p class="text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap">{{ detailProject.notes }}</p>
            </div>

            <!-- Status advance actions (QA admin only) -->
            <div v-if="isQaAdmin" class="pt-2 border-t border-gray-200 dark:border-slate-700 space-y-2">
              <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase">Actions</p>
              <div class="flex flex-wrap gap-2">
                <button
                  v-if="detailProject.status === 'planning'"
                  @click="advanceStatus(detailProject!, 'active')"
                  :disabled="actionLoading"
                  class="px-3 py-1.5 text-sm rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 transition"
                >
                  Activate
                </button>
                <button
                  v-if="detailProject.status === 'active'"
                  @click="advanceStatus(detailProject!, 'remeasuring', 'remeasure')"
                  :disabled="actionLoading"
                  class="px-3 py-1.5 text-sm rounded-lg bg-amber-600 text-white hover:bg-amber-700 disabled:opacity-50 transition"
                >
                  Begin Remeasuring
                </button>
                <button
                  v-if="detailProject.status === 'remeasuring'"
                  @click="advanceStatus(detailProject!, 'completed')"
                  :disabled="actionLoading"
                  class="px-3 py-1.5 text-sm rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 transition"
                >
                  Mark Completed
                </button>
                <button
                  v-if="detailProject.status !== 'completed' && detailProject.status !== 'suspended'"
                  @click="advanceStatus(detailProject!, 'suspended')"
                  :disabled="actionLoading"
                  class="px-3 py-1.5 text-sm rounded-lg bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-900/40 disabled:opacity-50 transition"
                >
                  Suspend
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Create / Edit Form Modal -->
    <Teleport to="body">
      <div
        v-if="showFormModal"
        class="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4 bg-black/50"
        @click.self="closeForm()"
      >
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700">
            <h2 class="font-semibold text-gray-900 dark:text-slate-100">
              {{ editingProject ? 'Edit Project' : 'New QAPI Project' }}
            </h2>
            <button @click="closeForm()" class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300">
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>

          <form @submit.prevent="submitForm()" class="px-6 py-5 space-y-4">

            <!-- Title -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Project Title</label>
              <input
                v-model="form.title"
                type="text"
                required
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
              />
              <p v-if="formErrors.title" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formErrors.title }}</p>
            </div>

            <!-- Domain -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Domain</label>
              <select name="domain"
                v-model="form.domain"
                required
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              >
                <option value="">Select domain...</option>
                <option v-for="(label, key) in props.domains" :key="key" :value="key">{{ label }}</option>
              </select>
              <p v-if="formErrors.domain" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formErrors.domain }}</p>
            </div>

            <!-- Aim statement -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Aim Statement</label>
              <textarea
                v-model="form.aim_statement"
                rows="3"
                required
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              />
              <p v-if="formErrors.aim_statement" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formErrors.aim_statement }}</p>
            </div>

            <!-- Lead -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Project Lead</label>
              <input
                v-model="form.lead_name"
                type="text"
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              />
            </div>

            <!-- Dates -->
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Start Date</label>
                <input
                  v-model="form.start_date"
                  type="date"
                  class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Target Date</label>
                <input
                  v-model="form.target_date"
                  type="date"
                  class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                />
              </div>
            </div>

            <!-- Metrics -->
            <div class="grid grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Baseline</label>
                <input
                  v-model="form.baseline_metric"
                  type="text"
                  class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Current</label>
                <input
                  v-model="form.current_metric"
                  type="text"
                  class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Target</label>
                <input
                  v-model="form.target_metric"
                  type="text"
                  class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                />
              </div>
            </div>

            <!-- Notes -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Notes</label>
              <textarea
                v-model="form.notes"
                rows="3"
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              />
            </div>

            <!-- Submit -->
            <div class="flex justify-end gap-3 pt-2">
              <button
                type="button"
                @click="closeForm()"
                class="px-4 py-2 text-sm rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition"
              >
                Cancel
              </button>
              <button
                type="submit"
                :disabled="formLoading"
                class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 transition"
              >
                {{ formLoading ? 'Saving...' : editingProject ? 'Save Changes' : 'Create Project' }}
              </button>
            </div>

          </form>
        </div>
      </div>
    </Teleport>

  </AppShell>
</template>
