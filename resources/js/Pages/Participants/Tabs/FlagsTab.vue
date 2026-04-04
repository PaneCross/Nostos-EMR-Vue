<script setup lang="ts">
// ─── FlagsTab.vue ─────────────────────────────────────────────────────────────
// Participant flag management. Displays active flags as colored pills grouped
// by category. Add flag form with severity selector. Remove flag with confirm.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
import { PlusIcon, XMarkIcon } from '@heroicons/vue/24/outline'

interface Flag {
  id: number; flag_type: string; severity: string
  description: string | null; is_active: boolean
  added_by: { id: number; first_name: string; last_name: string } | null
  created_at: string
}

interface Participant { id: number }

const props = defineProps<{
  participant: Participant
  flags: Flag[]
}>()

const FLAG_LABELS: Record<string, string> = {
  fall_risk: 'Fall Risk', elopement_risk: 'Elopement Risk',
  dnr: 'DNR', polst: 'POLST', isolation: 'Isolation',
  hospice: 'Hospice', weight_bearing: 'Weight Bearing',
  wound_care: 'Wound Care', oxygen: 'Oxygen Dependent',
  wheelchair: 'Wheelchair', behavioral: 'Behavioral',
  language: 'Language Barrier', diet: 'Diet Restriction',
  transportation: 'Transport Flag', legal: 'Legal Hold',
}

const SEVERITY_COLORS: Record<string, string> = {
  critical: 'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 border border-red-300 dark:border-red-700',
  high:     'bg-orange-100 dark:bg-orange-900/60 text-orange-800 dark:text-orange-300 border border-orange-300 dark:border-orange-700',
  medium:   'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-700',
  low:      'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300 border border-blue-200 dark:border-blue-800',
  info:     'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300 border border-gray-200 dark:border-slate-600',
}

const flags = ref<Flag[]>(props.flags)
const showAddForm = ref(false)
const saving = ref(false)
const error = ref('')
const removingId = ref<number | null>(null)

const form = ref({ flag_type: 'fall_risk', severity: 'high', description: '' })

const activeFlags = computed(() => flags.value.filter(f => f.is_active))
const inactiveFlags = computed(() => flags.value.filter(f => !f.is_active))

async function submit() {
  saving.value = true; error.value = ''
  try {
    const res = await axios.post(`/participants/${props.participant.id}/flags`, form.value)
    flags.value.unshift(res.data)
    showAddForm.value = false
    form.value = { flag_type: 'fall_risk', severity: 'high', description: '' }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save flag.'
    saving.value = false
  }
}

async function removeFlag(flag: Flag) {
  if (!confirm(`Remove "${FLAG_LABELS[flag.flag_type] ?? flag.flag_type}" flag?`)) return
  removingId.value = flag.id
  try {
    await axios.delete(`/participants/${props.participant.id}/flags/${flag.id}`)
    const idx = flags.value.findIndex(f => f.id === flag.id)
    if (idx !== -1) flags.value[idx].is_active = false
  } catch {
    alert('Failed to remove flag.')
  } finally {
    removingId.value = null
  }
}
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Flags</h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon class="w-3 h-3" />
        Add Flag
      </button>
    </div>

    <!-- Add flag form -->
    <div v-if="showAddForm" class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Add Flag</h3>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Flag Type</label>
          <select v-model="form.flag_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option v-for="(label, key) in FLAG_LABELS" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Severity</label>
          <select v-model="form.severity" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option value="critical">Critical</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
            <option value="info">Info</option>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Description (optional)</label>
        <input v-model="form.description" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
      </div>
      <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
      <div class="flex gap-2">
        <button :disabled="saving" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="submit">
          {{ saving ? 'Saving...' : 'Save' }}
        </button>
        <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors" @click="showAddForm = false">Cancel</button>
      </div>
    </div>

    <!-- Active flags -->
    <div v-if="activeFlags.length === 0" class="py-8 text-center text-gray-400 dark:text-slate-500 text-sm">No active flags.</div>
    <div v-else class="flex flex-wrap gap-2 items-start mb-6">
      <div
        v-for="flag in activeFlags"
        :key="flag.id"
        :class="['inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-full', SEVERITY_COLORS[flag.severity] ?? SEVERITY_COLORS.info]"
      >
        <span>{{ FLAG_LABELS[flag.flag_type] ?? flag.flag_type }}</span>
        <span v-if="flag.description" class="opacity-70">— {{ flag.description }}</span>
        <button
          :disabled="removingId === flag.id"
          class="ml-1 rounded-full hover:bg-black/10 dark:hover:bg-white/10 p-0.5 transition-colors disabled:opacity-50"
          @click="removeFlag(flag)"
        >
          <XMarkIcon class="w-3 h-3" />
        </button>
      </div>
    </div>

    <!-- Inactive flags -->
    <div v-if="inactiveFlags.length > 0">
      <h3 class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-2">Removed Flags</h3>
      <div class="flex flex-wrap gap-2 items-start">
        <div
          v-for="flag in inactiveFlags"
          :key="flag.id"
          class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500 line-through"
        >
          {{ FLAG_LABELS[flag.flag_type] ?? flag.flag_type }}
        </div>
      </div>
    </div>
  </div>
</template>
