<script setup lang="ts">
// ─── CarePlanTab.vue ──────────────────────────────────────────────────────────
// Active care plan for participant. Lazy-loads via API on mount. All 12 PACE
// domains always shown in a 2-col grid. Inline goal edit form per domain.
// Approve + New Version buttons for IDT/Primary Care admins.
// Review due date shown with amber countdown if ≤30 days.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'

interface CarePlanGoal {
  id: number
  domain: string
  goal_description: string | null
  measurable_outcomes: string | null
  interventions: string | null
  target_date: string | null
  status: string
}

interface CarePlan {
  id: number
  status: string
  version: number
  effective_date: string | null
  review_due_date: string | null
  overall_goals_text: string | null
  goals: CarePlanGoal[]
  approved_by: { first_name: string; last_name: string } | null
}

interface Participant { id: number }

const props = defineProps<{ participant: Participant }>()

const page = usePage<any>()
const auth = computed(() => page.props.auth)
const canApprove = computed(() =>
  auth.value?.user?.role === 'admin' &&
  ['idt', 'primary_care'].includes(auth.value?.user?.department ?? '')
)

const PLAN_STATUS_BADGE: Record<string, string> = {
  draft:        'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
  active:       'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
  under_review: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
  archived:     'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400',
}

const GOAL_STATUS_BADGE: Record<string, string> = {
  active:       'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 ring-blue-600/20',
  met:          'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 ring-green-600/20',
  modified:     'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 ring-amber-600/20',
  discontinued: 'bg-gray-50 dark:bg-slate-800 text-gray-700 dark:text-slate-400 ring-gray-600/20',
}

const ALL_CARE_DOMAINS = [
  { id: 'medical',        label: 'Medical' },
  { id: 'nursing',        label: 'Nursing' },
  { id: 'social',         label: 'Social Work' },
  { id: 'behavioral',     label: 'Behavioral Health' },
  { id: 'therapy_pt',     label: 'Physical Therapy' },
  { id: 'therapy_ot',     label: 'Occupational Therapy' },
  { id: 'therapy_st',     label: 'Speech Therapy' },
  { id: 'dietary',        label: 'Dietary / Nutrition' },
  { id: 'activities',     label: 'Activities' },
  { id: 'home_care',      label: 'Home Care' },
  { id: 'transportation', label: 'Transportation' },
  { id: 'pharmacy',       label: 'Pharmacy' },
]

// ── State ─────────────────────────────────────────────────────────────────────
const plan      = ref<CarePlan | null>(null)
const loading   = ref(true)
const creating  = ref(false)
const approving = ref(false)
const versioning = ref(false)

// Inline goal editing
const editDomain = ref<string | null>(null)
const editForm   = ref<Record<string, string>>({})
const saving     = ref(false)
const saveError  = ref<string | null>(null)

// ── Computed ──────────────────────────────────────────────────────────────────
const goals = computed(() => plan.value?.goals ?? [])

const daysUntilReview = computed(() => {
  if (!plan.value?.review_due_date) return null
  return Math.ceil((new Date(plan.value.review_due_date).getTime() - Date.now()) / 86_400_000)
})

const isEditable = computed(() =>
  plan.value?.status === 'draft' || plan.value?.status === 'under_review'
)

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const r = await axios.get(`/participants/${props.participant.id}/careplan`)
    plan.value = r.data
  } catch {
    // plan stays null — show create button
  } finally {
    loading.value = false
  }
})

// ── Actions ───────────────────────────────────────────────────────────────────
async function createPlan() {
  creating.value = true
  try {
    const r = await axios.post(`/participants/${props.participant.id}/careplan`)
    plan.value = r.data
  } catch {
    // noop
  } finally {
    creating.value = false
  }
}

function openEdit(goal: CarePlanGoal) {
  editDomain.value = goal.domain
  editForm.value = {
    goal_description:    goal.goal_description    ?? '',
    measurable_outcomes: goal.measurable_outcomes ?? '',
    interventions:       goal.interventions       ?? '',
    target_date:         goal.target_date?.split('T')[0] ?? '',
    status:              goal.status ?? 'active',
  }
  saveError.value = null
}

function openNewGoal(domainId: string) {
  editDomain.value = domainId
  editForm.value = {
    goal_description: '', measurable_outcomes: '',
    interventions: '', target_date: '', status: 'active',
  }
  saveError.value = null
}

async function saveGoal() {
  if (!plan.value) return
  saving.value = true
  saveError.value = null
  try {
    const r = await axios.put(
      `/participants/${props.participant.id}/careplan/${plan.value.id}/goals/${editDomain.value}`,
      editForm.value,
    )
    const exists = goals.value.some(g => g.domain === editDomain.value)
    plan.value = {
      ...plan.value,
      goals: exists
        ? goals.value.map(g => g.domain === editDomain.value ? r.data : g)
        : [...goals.value, r.data],
    }
    editDomain.value = null
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string; error?: string } } }
    saveError.value = e.response?.data?.message ?? e.response?.data?.error ?? 'Save failed.'
  } finally {
    saving.value = false
  }
}

async function approvePlan() {
  if (!plan.value || !window.confirm('Approve and activate this care plan? It will replace the current active plan.')) return
  approving.value = true
  saveError.value = null
  try {
    const r = await axios.post(`/participants/${props.participant.id}/careplan/${plan.value.id}/approve`)
    plan.value = r.data
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    saveError.value = e.response?.data?.message ?? 'Approval failed.'
  } finally {
    approving.value = false
  }
}

async function startNewVersion() {
  if (!plan.value || !window.confirm('Start a new revision? The current active plan will move to Under Review and a new draft will be created for editing.')) return
  versioning.value = true
  try {
    const r = await axios.post(`/participants/${props.participant.id}/careplan/${plan.value.id}/new-version`)
    plan.value = r.data
  } catch {
    // noop
  } finally {
    versioning.value = false
  }
}
</script>

<template>
  <div class="space-y-6 p-6">

    <!-- Loading -->
    <div v-if="loading" class="py-12 text-center text-sm text-gray-500 dark:text-slate-400">
      Loading care plan...
    </div>

    <!-- No plan -->
    <div v-else-if="!plan" class="py-12 text-center">
      <p class="text-gray-500 dark:text-slate-400 text-sm mb-3">No care plan found for this participant.</p>
      <button
        :disabled="creating"
        class="px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
        @click="createPlan"
      >
        {{ creating ? 'Creating...' : 'Create Care Plan' }}
      </button>
    </div>

    <template v-else>
      <!-- Plan meta header -->
      <div class="flex items-start justify-between gap-4 p-4 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
        <div>
          <div class="flex items-center gap-2">
            <h3 class="font-semibold text-slate-800 dark:text-slate-200">Care Plan v{{ plan.version }}</h3>
            <span :class="['inline-flex items-center rounded px-2 py-0.5 text-xs font-medium', PLAN_STATUS_BADGE[plan.status] ?? '']">
              {{ plan.status?.replace('_', ' ').toUpperCase() }}
            </span>
          </div>
          <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
            {{ plan.effective_date ? `Effective: ${plan.effective_date}` : 'Draft: not yet effective' }}
            <template v-if="plan.review_due_date"> · Review due: {{ plan.review_due_date }}</template>
            <span
              v-if="daysUntilReview !== null && daysUntilReview <= 30"
              class="ml-1.5 text-amber-600 dark:text-amber-400 font-medium"
            >({{ daysUntilReview }}d)</span>
          </p>
          <p v-if="plan.approved_by" class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
            Approved by {{ plan.approved_by.first_name }} {{ plan.approved_by.last_name }}
          </p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <button
            v-if="plan.status === 'active'"
            :disabled="versioning"
            class="px-3 py-1.5 text-xs font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
            @click="startNewVersion"
          >
            {{ versioning ? 'Creating...' : 'Start New Version' }}
          </button>
          <button
            v-if="canApprove && (plan.status === 'draft' || plan.status === 'under_review')"
            :disabled="approving"
            class="px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
            @click="approvePlan"
          >
            {{ approving ? 'Approving...' : 'Approve Plan' }}
          </button>
        </div>
      </div>

      <!-- Error banner -->
      <div
        v-if="saveError"
        class="bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm px-4 py-3 rounded-lg"
      >
        {{ saveError }}
      </div>

      <!-- Overall goals text -->
      <div
        v-if="plan.overall_goals_text"
        class="px-4 py-3 bg-blue-50 dark:bg-blue-950/60 border border-blue-100 dark:border-blue-800 rounded-xl"
      >
        <p class="text-xs font-semibold text-blue-800 dark:text-blue-300 mb-1">Overall Care Goals</p>
        <p class="text-sm text-blue-900 dark:text-blue-200">{{ plan.overall_goals_text }}</p>
      </div>

      <!-- Domain goals grid -->
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div
          v-for="{ id: domainId, label: domainLabel } in ALL_CARE_DOMAINS"
          :key="domainId"
          class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 space-y-2"
        >
          <!-- Domain header -->
          <div class="flex items-center justify-between">
            <h4 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ domainLabel }}</h4>
            <div class="flex items-center gap-1.5">
              <template v-if="goals.find(g => g.domain === domainId) as CarePlanGoal | undefined">
                <span :class="['inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset', GOAL_STATUS_BADGE[goals.find(g => g.domain === domainId)!.status] ?? '']">
                  {{ goals.find(g => g.domain === domainId)!.status?.toUpperCase() }}
                </span>
                <button
                  v-if="isEditable && editDomain !== domainId"
                  class="text-[11px] text-blue-600 dark:text-blue-400 hover:underline"
                  @click="openEdit(goals.find(g => g.domain === domainId)!)"
                >
                  Edit
                </button>
                <span v-else-if="plan.status === 'active' && editDomain !== domainId" class="text-[11px] text-gray-400 dark:text-slate-600">
                  Approved - read only
                </span>
              </template>
              <template v-else>
                <button
                  v-if="isEditable && editDomain !== domainId"
                  class="text-[11px] text-blue-600 dark:text-blue-400 hover:underline"
                  @click="openNewGoal(domainId)"
                >
                  + Add Goal
                </button>
              </template>
            </div>
          </div>

          <!-- Inline edit form -->
          <div v-if="editDomain === domainId" class="space-y-2 pt-1">
            <div>
              <label class="block text-[10px] font-medium text-slate-600 dark:text-slate-400 mb-0.5">Goal</label>
              <textarea
                v-model="editForm.goal_description"
                rows="2"
                class="w-full text-xs border border-slate-300 dark:border-slate-600 rounded px-2 py-1 resize-none bg-white dark:bg-slate-700 dark:text-slate-100"
              />
            </div>
            <div>
              <label class="block text-[10px] font-medium text-slate-600 dark:text-slate-400 mb-0.5">Outcomes</label>
              <textarea
                v-model="editForm.measurable_outcomes"
                rows="2"
                class="w-full text-xs border border-slate-300 dark:border-slate-600 rounded px-2 py-1 resize-none bg-white dark:bg-slate-700 dark:text-slate-100"
              />
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-[10px] font-medium text-slate-600 dark:text-slate-400 mb-0.5">Status</label>
                <select
                  v-model="editForm.status"
                  class="w-full text-xs border border-slate-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-700 dark:text-slate-100"
                >
                  <option v-for="s in ['active','met','modified','discontinued']" :key="s" :value="s">{{ s }}</option>
                </select>
              </div>
              <div>
                <label class="block text-[10px] font-medium text-slate-600 dark:text-slate-400 mb-0.5">Target Date</label>
                <input
                  v-model="editForm.target_date"
                  type="date"
                  class="w-full text-xs border border-slate-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-700 dark:text-slate-100"
                />
              </div>
            </div>
            <div class="flex gap-2 pt-1">
              <button
                :disabled="saving"
                class="px-3 py-1 text-[11px] font-medium bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
                @click="saveGoal"
              >
                {{ saving ? 'Saving...' : 'Save' }}
              </button>
              <button
                class="px-3 py-1 text-[11px] text-slate-600 dark:text-slate-400 border border-slate-300 dark:border-slate-600 rounded hover:bg-slate-50 dark:hover:bg-slate-700"
                @click="editDomain = null; saveError = null"
              >
                Cancel
              </button>
            </div>
          </div>

          <!-- Goal content (read mode) -->
          <template v-else-if="goals.find(g => g.domain === domainId)">
            <div class="space-y-1.5">
              <p class="text-xs text-slate-700 dark:text-slate-300">
                {{ goals.find(g => g.domain === domainId)!.goal_description }}
              </p>
              <div v-if="goals.find(g => g.domain === domainId)!.measurable_outcomes">
                <p class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Outcomes</p>
                <p class="text-[11px] text-slate-600 dark:text-slate-400">
                  {{ goals.find(g => g.domain === domainId)!.measurable_outcomes }}
                </p>
              </div>
              <p v-if="goals.find(g => g.domain === domainId)!.target_date" class="text-[10px] text-slate-400 dark:text-slate-500">
                Target: {{ goals.find(g => g.domain === domainId)!.target_date?.split('T')[0] }}
              </p>
            </div>
          </template>

          <!-- No goal placeholder -->
          <template v-else>
            <p class="text-[11px] text-slate-400 dark:text-slate-500 italic">
              {{ isEditable
                ? 'No goal recorded. Use Add Goal to create one.'
                : 'No goal recorded for this domain.' }}
            </p>
          </template>
        </div>
      </div>
    </template>
  </div>
</template>
