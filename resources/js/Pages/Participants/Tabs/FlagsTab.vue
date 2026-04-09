<script setup lang="ts">
// ─── FlagsTab.vue ─────────────────────────────────────────────────────────────
// Participant flag management. Active flags shown as colored card rows with a
// "Resolve" button. Add flag form uses orange background. Resolved flags shown
// in a dimmed history list.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Flag {
  id: number; flag_type: string; severity: string
  description: string | null; is_active: boolean
  created_by?: { id: number; first_name: string; last_name: string } | null
  added_by?: { id: number; first_name: string; last_name: string } | null
  created_at: string
  resolved_at?: string | null
}

interface Participant { id: number }

const props = defineProps<{
  participant: Participant
  flags: Flag[]
}>()

const FLAG_LABELS: Record<string, string> = {
  wheelchair:              'Wheelchair',
  stretcher:               'Stretcher',
  oxygen:                  'Oxygen Dependent',
  behavioral:              'Behavioral',
  fall_risk:               'Fall Risk',
  wandering_risk:          'Wandering Risk',
  isolation:               'Isolation',
  dnr:                     'DNR',
  weight_bearing_restriction: 'Weight Bearing',
  dietary_restriction:     'Dietary Restriction',
  elopement_risk:          'Elopement Risk',
  hospice:                 'Hospice',
  other:                   'Other',
}

const FLAG_SEVERITY_COLORS: Record<string, string> = {
  low:      'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
  medium:   'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300 border-yellow-200 dark:border-yellow-800',
  high:     'bg-orange-100 dark:bg-orange-950/60 text-orange-800 dark:text-orange-300 border-orange-200 dark:border-orange-800',
  critical: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800',
}

const flags = ref<Flag[]>(props.flags)
const showAddForm = ref(false)
const saving = ref(false)
const resolvingId = ref<number | null>(null)

const form = ref({ flag_type: 'fall_risk', severity: 'medium', description: '' })

const activeFlags   = computed(() => flags.value.filter(f => f.is_active))
const resolvedFlags = computed(() => flags.value.filter(f => !f.is_active))

async function submit() {
  saving.value = true
  try {
    const res = await axios.post(`/participants/${props.participant.id}/flags`, form.value)
    flags.value.unshift(res.data)
    showAddForm.value = false
    form.value = { flag_type: 'fall_risk', severity: 'medium', description: '' }
    router.reload({ only: ['flags'] })
  } catch {
    // form stays open
  } finally {
    saving.value = false
  }
}

async function handleResolve(flagId: number) {
  resolvingId.value = flagId
  try {
    await axios.post(`/participants/${props.participant.id}/flags/${flagId}/resolve`)
    flags.value = flags.value.map(f => f.id === flagId ? { ...f, is_active: false } : f)
    router.reload({ only: ['flags'] })
  } catch {
    // flag stays active; user can retry
  } finally {
    resolvingId.value = null
  }
}

function fmtDate(val: string | null | undefined): string {
  if (!val) return ''
  return new Date(val).toLocaleDateString('en-US')
}
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">
        Active Flags ({{ activeFlags.length }})
      </h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon class="w-3 h-3" />
        {{ showAddForm ? 'Cancel' : 'Add Flag' }}
      </button>
    </div>

    <!-- Add flag form -->
    <div
      v-if="showAddForm"
      class="bg-orange-50 dark:bg-orange-950/40 border border-orange-200 dark:border-orange-800 rounded-lg p-4 mb-4 grid grid-cols-3 gap-3"
    >
      <div>
        <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Flag Type</label>
        <select v-model="form.flag_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800">
          <option v-for="(label, key) in FLAG_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Severity</label>
        <select v-model="form.severity" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800">
          <option value="low">low</option>
          <option value="medium">medium</option>
          <option value="high">high</option>
          <option value="critical">critical</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Description</label>
        <input
          v-model="form.description"
          type="text"
          placeholder="Optional detail"
          class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
        />
      </div>
      <div class="col-span-3 flex justify-end gap-2">
        <button
          type="button"
          class="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
          @click="showAddForm = false"
        >
          Cancel
        </button>
        <button
          :disabled="saving"
          class="text-xs px-4 py-1.5 bg-orange-500 text-white rounded-lg hover:bg-orange-600 disabled:opacity-50 transition-colors"
          @click="submit"
        >
          {{ saving ? 'Saving...' : 'Add Flag' }}
        </button>
      </div>
    </div>

    <!-- Active flags -->
    <div class="space-y-2 mb-6">
      <p v-if="activeFlags.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-4 text-center">No active flags.</p>
      <div
        v-for="f in activeFlags"
        :key="f.id"
        :class="['border rounded-lg px-4 py-3 flex items-start justify-between gap-3', FLAG_SEVERITY_COLORS[f.severity] ?? FLAG_SEVERITY_COLORS.medium]"
      >
        <div>
          <div class="flex items-center gap-2">
            <span class="font-semibold text-sm">{{ FLAG_LABELS[f.flag_type] ?? f.flag_type }}</span>
            <span class="text-xs opacity-70 uppercase">{{ f.severity }}</span>
          </div>
          <p v-if="f.description" class="text-xs mt-0.5 opacity-80">{{ f.description }}</p>
          <p class="text-xs opacity-60 mt-0.5">
            Added {{ fmtDate(f.created_at) }}
            <template v-if="f.created_by || f.added_by">
              by {{ (f.created_by ?? f.added_by)!.first_name }} {{ (f.created_by ?? f.added_by)!.last_name }}
            </template>
          </p>
        </div>
        <button
          :disabled="resolvingId === f.id"
          class="flex-shrink-0 text-xs px-2.5 py-1 border border-current rounded-lg hover:bg-white dark:hover:bg-slate-700/50 transition-colors disabled:opacity-50"
          @click="handleResolve(f.id)"
        >
          {{ resolvingId === f.id ? 'Resolving...' : 'Resolve' }}
        </button>
      </div>
    </div>

    <!-- Resolved flags -->
    <template v-if="resolvedFlags.length > 0">
      <h4 class="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wide mb-2">
        Resolved ({{ resolvedFlags.length }})
      </h4>
      <div class="space-y-1">
        <div
          v-for="f in resolvedFlags"
          :key="f.id"
          class="bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-slate-700 rounded px-4 py-2 flex items-center justify-between opacity-60"
        >
          <span class="text-sm dark:text-slate-300">
            {{ FLAG_LABELS[f.flag_type] ?? f.flag_type }}
            <span class="text-xs text-gray-400 dark:text-slate-500">({{ f.severity }})</span>
          </span>
          <span v-if="f.resolved_at" class="text-xs text-gray-400 dark:text-slate-500">
            {{ fmtDate(f.resolved_at) }}
          </span>
        </div>
      </div>
    </template>
  </div>
</template>
