<!--
  Grievance Queue: lists all grievances in three tabs: Open, Resolved, and CMS Reportable.
  QA admins can file new grievances via a modal with participant search and full form fields.
  Rows are clickable to navigate to the grievance detail view.
-->
<script setup lang="ts">
// ─── Grievances/Index ───────────────────────────────────────────────────────
// PACE grievance queue. Grievances are general complaints from a participant
// or family member (quality of service, staff conduct, food, transport, etc.)
//: distinct from Appeals (which contest a specific service denial decision).
//
// Audience: QA Compliance department primarily; intake from any user.
//
// Notable rules:
//   - 42 CFR §460.120: grievance system; written notice + 30-day resolution
//     target. The "CMS Reportable" tab surfaces ones requiring HPMS upload.
//   - Day-25 aging alert + amber row color when nearing the 30-day deadline.
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, usePage, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
  PlusIcon,
  XMarkIcon,
  ExclamationTriangleIcon,
  MagnifyingGlassIcon,
  ChevronRightIcon,
  FlagIcon,
} from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────

interface GrievanceRow {
  id: number
  reference_number: string
  participant_id: number
  participant_name: string
  category: string
  filed_by_name: string
  filed_at: string
  priority: string
  assigned_to_name: string | null
  status: string
  cms_reportable: boolean
}

interface ParticipantResult {
  id: number
  name: string
  mrn: string
}

// ── Props ──────────────────────────────────────────────────────────────────

const props = defineProps<{
  openGrievances: GrievanceRow[]
  resolvedGrievances: GrievanceRow[]
  cmsGrievances: GrievanceRow[]
  categories: Record<string, string>
  statuses: Record<string, string>
  priorities: Record<string, string>
  isQaAdmin: boolean
}>()

// ── Auth ───────────────────────────────────────────────────────────────────

const page = usePage()
const user = computed(() => (page.props.auth as any)?.user)

// ── Tabs ───────────────────────────────────────────────────────────────────

type TabKey = 'open' | 'resolved' | 'cms'
const activeTab = ref<TabKey>('open')

const tabConfig = [
  { key: 'open' as TabKey,     label: 'Open',          countFn: () => props.openGrievances.length },
  { key: 'resolved' as TabKey, label: 'Resolved',      countFn: () => props.resolvedGrievances.length },
  { key: 'cms' as TabKey,      label: 'CMS Reportable', countFn: () => props.cmsGrievances.length },
]

const currentRows = computed<GrievanceRow[]>(() => {
  if (activeTab.value === 'open')     return props.openGrievances
  if (activeTab.value === 'resolved') return props.resolvedGrievances
  return props.cmsGrievances
})

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

function statusLabel(status: string): string {
  return props.statuses[status] ?? status.replace(/_/g, ' ')
}

// ── New Grievance modal ────────────────────────────────────────────────────

const showModal = ref(false)
const participantQuery = ref('')
const participantResults = ref<ParticipantResult[]>([])
const participantSearchTimeout = ref<ReturnType<typeof setTimeout> | null>(null)
const selectedParticipant = ref<ParticipantResult | null>(null)

const form = ref({
  participant_id: null as number | null,
  filed_by_name: '',
  filed_by_type: 'participant',
  category: '',
  priority: 'standard',
  filed_at: new Date().toISOString().slice(0, 10),
  description: '',
  cms_reportable: false,
})

const formErrors = ref<Record<string, string>>({})
const formLoading = ref(false)

function openModal() {
  form.value = {
    participant_id: null,
    filed_by_name: '',
    filed_by_type: 'participant',
    category: '',
    priority: 'standard',
    filed_at: new Date().toISOString().slice(0, 10),
    description: '',
    cms_reportable: false,
  }
  participantQuery.value = ''
  participantResults.value = []
  selectedParticipant.value = null
  formErrors.value = {}
  showModal.value = true
}

function closeModal() {
  showModal.value = false
}

function onParticipantInput() {
  if (participantSearchTimeout.value) clearTimeout(participantSearchTimeout.value)
  if (participantQuery.value.length < 2) {
    participantResults.value = []
    return
  }
  participantSearchTimeout.value = setTimeout(async () => {
    const res = await axios.get(`/participants/search?q=${encodeURIComponent(participantQuery.value)}`)
    participantResults.value = res.data.data ?? res.data
  }, 280)
}

function selectParticipant(p: ParticipantResult) {
  selectedParticipant.value = p
  form.value.participant_id = p.id
  participantQuery.value = `${p.name} (${p.mrn})`
  participantResults.value = []
}

async function submitModal() {
  formLoading.value = true
  formErrors.value = {}
  try {
    await axios.post('/grievances', form.value)
    closeModal()
    router.reload({ only: ['openGrievances', 'resolvedGrievances', 'cmsGrievances'] })
  } catch (err: any) {
    if (err.response?.status === 422) {
      formErrors.value = err.response.data.errors ?? {}
    }
  } finally {
    formLoading.value = false
  }
}

// ── Navigation ────────────────────────────────────────────────────────────

function visitGrievance(id: number) {
  router.visit(`/grievances/${id}`)
}

// ── Date format ───────────────────────────────────────────────────────────

function fmt(d: string | null): string {
  if (!d) return '-'
  return new Date(d).toLocaleDateString()
}
</script>

<template>
  <AppShell>
    <Head title="Grievances" />

    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Grievances</h1>
          <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">42 CFR §460.118: Grievance and Appeals Process</p>
        </div>
        <button
          v-if="props.isQaAdmin"
          @click="openModal()"
          class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition"
        >
          <PlusIcon class="w-4 h-4" />
          New Grievance
        </button>
      </div>

      <!-- Tabs + Table card -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700">

        <!-- Tab bar -->
        <div class="flex border-b border-gray-200 dark:border-slate-700 overflow-x-auto">
          <button
            v-for="tab in tabConfig"
            :key="tab.key"
            @click="activeTab = tab.key"
            :class="[
              'px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition flex items-center gap-2',
              activeTab === tab.key
                ? 'border-blue-600 text-blue-600 dark:text-blue-400 dark:border-blue-400'
                : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200',
            ]"
          >
            {{ tab.label }}
            <span
              :class="[
                'px-1.5 py-0.5 text-xs rounded-full font-medium',
                activeTab === tab.key
                  ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                  : 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-400',
              ]"
            >{{ tab.countFn() }}</span>
          </button>
        </div>

        <!-- Table -->
        <div v-if="currentRows.length === 0" class="py-14 text-center text-sm text-gray-500 dark:text-slate-400">
          No grievances in this category.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-slate-700/50 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">
                <th class="px-4 py-3 text-left">Reference</th>
                <th class="px-4 py-3 text-left">Participant</th>
                <th class="px-4 py-3 text-left">Category</th>
                <th class="px-4 py-3 text-left">Filed By</th>
                <th class="px-4 py-3 text-left">Date Filed</th>
                <th class="px-4 py-3 text-left">Priority</th>
                <th class="px-4 py-3 text-left">Assigned</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
              <tr
                v-for="g in currentRows"
                :key="g.id"
                class="hover:bg-gray-50 dark:hover:bg-slate-700/40 cursor-pointer transition"
                @click="visitGrievance(g.id)"
              >
                <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-slate-300">
                  <div class="flex items-center gap-1">
                    {{ g.reference_number }}
                    <FlagIcon v-if="g.cms_reportable" class="w-3.5 h-3.5 text-red-500" title="CMS Reportable" />
                  </div>
                </td>
                <td class="px-4 py-3 font-medium text-gray-800 dark:text-slate-200">{{ g.participant_name }}</td>
                <td class="px-4 py-3 text-gray-600 dark:text-slate-300">{{ props.categories[g.category] ?? g.category }}</td>
                <td class="px-4 py-3 text-gray-600 dark:text-slate-300">{{ g.filed_by_name }}</td>
                <td class="px-4 py-3 text-gray-500 dark:text-slate-400">{{ fmt(g.filed_at) }}</td>
                <td class="px-4 py-3">
                  <!-- Priority badge -->
                  <span
                    v-if="g.priority === 'urgent'"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300"
                  >
                    <ExclamationTriangleIcon class="w-3 h-3" />
                    Urgent
                  </span>
                  <span
                    v-else
                    class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-300"
                  >
                    Standard
                  </span>
                </td>
                <td class="px-4 py-3 text-gray-500 dark:text-slate-400">{{ g.assigned_to_name || '-' }}</td>
                <td class="px-4 py-3">
                  <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', statusClass(g.status)]">
                    {{ statusLabel(g.status) }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <ChevronRightIcon class="w-4 h-4 text-gray-400 dark:text-slate-500" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>
    </div>

    <!-- New Grievance Modal -->
    <Teleport to="body">
      <div
        v-if="showModal"
        class="fixed inset-0 z-50 flex items-start justify-center pt-12 px-4 bg-black/50"
        @click.self="closeModal()"
      >
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700">
            <h2 class="font-semibold text-gray-900 dark:text-slate-100">File New Grievance</h2>
            <button @click="closeModal()" class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300">
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>

          <form @submit.prevent="submitModal()" class="px-6 py-5 space-y-4">

            <!-- Participant search -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Participant</label>
              <div class="relative">
                <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                <input
                  v-model="participantQuery"
                  @input="onParticipantInput()"
                  type="text"
                  placeholder="Search by name or MRN..."
                  class="w-full pl-9 pr-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                />
                <!-- Dropdown results -->
                <ul
                  v-if="participantResults.length > 0"
                  class="absolute z-20 left-0 right-0 mt-1 bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-lg shadow-lg max-h-40 overflow-y-auto"
                >
                  <li
                    v-for="p in participantResults"
                    :key="p.id"
                    @click="selectParticipant(p)"
                    class="px-4 py-2 text-sm cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-600 text-gray-800 dark:text-slate-200"
                  >
                    {{ p.name }} <span class="text-gray-400 dark:text-slate-400 text-xs ml-1">{{ p.mrn }}</span>
                  </li>
                </ul>
              </div>
              <p v-if="formErrors.participant_id" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formErrors.participant_id }}</p>
            </div>

            <!-- Filed By Name -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Filed By (Name)</label>
              <input
                v-model="form.filed_by_name"
                type="text"
                required
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              />
              <p v-if="formErrors.filed_by_name" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formErrors.filed_by_name }}</p>
            </div>

            <!-- Filed By Type -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Filed By (Type)</label>
              <select name="filed_by_type"
                v-model="form.filed_by_type"
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              >
                <option value="participant">Participant</option>
                <option value="family_member">Family Member</option>
                <option value="legal_representative">Legal Representative</option>
                <option value="staff">Staff</option>
                <option value="other">Other</option>
              </select>
            </div>

            <!-- Category -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Category</label>
              <select name="category"
                v-model="form.category"
                required
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              >
                <option value="">Select category...</option>
                <option v-for="(label, key) in props.categories" :key="key" :value="key">{{ label }}</option>
              </select>
              <p v-if="formErrors.category" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formErrors.category }}</p>
            </div>

            <!-- Priority -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Priority</label>
              <select name="priority"
                v-model="form.priority"
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              >
                <option v-for="(label, key) in props.priorities" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>

            <!-- Date Filed -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Date Filed</label>
              <input
                v-model="form.filed_at"
                type="date"
                required
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              />
            </div>

            <!-- Description -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Description</label>
              <textarea
                v-model="form.description"
                rows="4"
                required
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
              />
              <p v-if="formErrors.description" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formErrors.description }}</p>
            </div>

            <!-- CMS Reportable (QA admin only) -->
            <div v-if="props.isQaAdmin" class="flex items-center gap-3">
              <input
                id="cms_reportable"
                v-model="form.cms_reportable"
                type="checkbox"
                class="w-4 h-4 rounded border-gray-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500"
              />
              <label for="cms_reportable" class="text-sm font-medium text-gray-700 dark:text-slate-300 flex items-center gap-1">
                <FlagIcon class="w-4 h-4 text-red-500" />
                Flag as CMS Reportable
              </label>
            </div>

            <!-- Actions -->
            <div class="flex justify-end gap-3 pt-2">
              <button
                type="button"
                @click="closeModal()"
                class="px-4 py-2 text-sm rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition"
              >
                Cancel
              </button>
              <button
                type="submit"
                :disabled="formLoading"
                class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 transition"
              >
                {{ formLoading ? 'Filing...' : 'File Grievance' }}
              </button>
            </div>

          </form>
        </div>
      </div>
    </Teleport>

  </AppShell>
</template>
