<script setup lang="ts">
// ─── Tabs/FlagsTab.vue ────────────────────────────────────────────────────────
// Participant clinical/safety flags with severity color coding. Active flags
// show prominently at top. Inactive flags are listed below. Supports adding new
// flags via a modal. Flag types include fall risk, DNR, hospice, behavioral, etc.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { ExclamationTriangleIcon, PlusIcon } from '@heroicons/vue/24/outline'

interface Flag { id: number; flag_type: string; severity: string; notes: string | null; is_active: boolean }

const props = defineProps<{
  participantId: number
  initialFlags: Flag[]
}>()

const flags = ref<Flag[]>(props.initialFlags)
watch(() => props.initialFlags, v => { flags.value = v })

const active   = computed(() => flags.value.filter(f => f.is_active))
const inactive = computed(() => flags.value.filter(f => !f.is_active))

const FLAG_SEVERITY_COLORS: Record<string, string> = {
  low:      'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 border border-blue-200',
  medium:   'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-700 dark:text-yellow-300 border border-yellow-200',
  high:     'bg-orange-100 dark:bg-orange-950/60 text-orange-700 dark:text-orange-300 border border-orange-200',
  critical: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border border-red-300',
}

const FLAG_LABELS: Record<string, string> = {
  wheelchair: 'Wheelchair', fall_risk: 'Fall Risk', dnr: 'DNR', hospice: 'Hospice',
  dementia: 'Dementia', behavior: 'Behavior', isolation: 'Isolation',
  elopement_risk: 'Elopement Risk', oxygen: 'Oxygen', dietary: 'Dietary',
  wound_care: 'Wound Care', pain_management: 'Pain Management', other: 'Other',
}

const SEVERITY_LABELS: Record<string, string> = {
  low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical',
}

const FLAG_TYPE_OPTIONS = Object.entries(FLAG_LABELS).map(([k, v]) => ({ key: k, label: v }))

const showModal  = ref(false)
const submitting = ref(false)
const formError  = ref<string | null>(null)

const form = ref({ flag_type: 'fall_risk', severity: 'medium', notes: '' })

function resetForm() {
  form.value = { flag_type: 'fall_risk', severity: 'medium', notes: '' }
  formError.value = null
}

function submitFlag() {
  submitting.value = true
  formError.value = null
  router.post(`/participants/${props.participantId}/flags`, form.value, {
    preserveScroll: true,
    onSuccess: () => { showModal.value = false; resetForm() },
    onError: (e: Record<string, string>) => {
      formError.value = Object.values(e)[0] ?? 'Failed to save flag.'
    },
    onFinish: () => { submitting.value = false },
  })
}

function deactivateFlag(flagId: number) {
  router.patch(`/participants/${props.participantId}/flags/${flagId}`, { is_active: false }, {
    preserveScroll: true,
  })
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">
        <ExclamationTriangleIcon class="w-4 h-4 inline mr-1 text-amber-500" />
        Flags ({{ active.length }} active)
      </h3>
      <button
        class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-1.5"
        aria-label="Add new flag"
        @click="showModal = true"
      >
        <PlusIcon class="w-4 h-4" />
        Add Flag
      </button>
    </div>

    <p v-if="active.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-4 text-center">No active flags.</p>

    <div v-if="active.length > 0" class="space-y-2 mb-4">
      <div
        v-for="f in active"
        :key="f.id"
        :class="`rounded-lg px-4 py-3 flex items-start justify-between gap-3 ${FLAG_SEVERITY_COLORS[f.severity] ?? 'bg-gray-100 border border-gray-200 text-gray-600'}`"
      >
        <div>
          <p class="text-sm font-semibold">{{ FLAG_LABELS[f.flag_type] ?? f.flag_type }}</p>
          <p class="text-xs mt-0.5">{{ SEVERITY_LABELS[f.severity] ?? f.severity }} severity</p>
          <p v-if="f.notes" class="text-xs mt-1 opacity-80">{{ f.notes }}</p>
        </div>
        <button
          class="text-xs underline opacity-60 hover:opacity-100 flex-shrink-0"
          aria-label="Deactivate this flag"
          @click="deactivateFlag(f.id)"
        >Deactivate</button>
      </div>
    </div>

    <!-- Inactive flags -->
    <div v-if="inactive.length > 0" class="mt-2">
      <h4 class="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wide mb-2">Resolved / Inactive ({{ inactive.length }})</h4>
      <div class="space-y-1 opacity-60">
        <div v-for="f in inactive" :key="f.id" class="text-sm text-gray-600 dark:text-slate-400 border border-gray-200 dark:border-slate-700 rounded px-3 py-1.5">
          {{ FLAG_LABELS[f.flag_type] ?? f.flag_type }} - {{ SEVERITY_LABELS[f.severity] ?? f.severity }}
        </div>
      </div>
    </div>

    <!-- Add Flag modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md p-6">
        <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-4">Add Flag</h3>
        <p v-if="formError" class="text-sm text-red-600 dark:text-red-400 mb-3">{{ formError }}</p>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Flag Type</label>
            <select v-model="form.flag_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100">
              <option v-for="opt in FLAG_TYPE_OPTIONS" :key="opt.key" :value="opt.key">{{ opt.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Severity</label>
            <select v-model="form.severity" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100">
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Notes (optional)</label>
            <textarea v-model="form.notes" rows="3" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 resize-none" />
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-4">
          <button class="text-sm px-4 py-2 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-200 rounded-lg hover:bg-gray-200 dark:hover:bg-slate-600 transition-colors" @click="showModal = false; resetForm()">Cancel</button>
          <button
            :disabled="submitting"
            class="text-sm px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg transition-colors"
            @click="submitFlag"
          >{{ submitting ? 'Saving...' : 'Save Flag' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
