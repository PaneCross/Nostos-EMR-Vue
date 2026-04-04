<script setup lang="ts">
// ─── AllergiesTab.vue ─────────────────────────────────────────────────────────
// Displays participant allergies grouped by type. Life-threatening allergies are
// highlighted with a red border. Add allergy form sends POST to the allergies
// endpoint. Active/inactive filter toggle.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
import { PlusIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface Allergy {
  id: number; allergy_type: string; allergen_name: string
  reaction_description: string | null; severity: string; is_active: boolean
  notes: string | null; verified_by: { id: number; first_name: string; last_name: string } | null
}

interface Participant { id: number }

const props = defineProps<{
  participant: Participant
  allergies: Record<string, Allergy[]>
}>()

const ALLERGY_TYPE_LABELS: Record<string, string> = {
  drug: 'Drug', food: 'Food', environmental: 'Environmental',
  dietary_restriction: 'Dietary Restriction', latex: 'Latex', contrast: 'Contrast',
}

const SEVERITY_COLORS: Record<string, string> = {
  life_threatening: 'border-red-400 dark:border-red-600 bg-red-50 dark:bg-red-950/30',
  severe:           'border-orange-300 dark:border-orange-700 bg-orange-50 dark:bg-orange-950/20',
  moderate:         'border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/20',
  mild:             'border-yellow-200 dark:border-yellow-800 bg-yellow-50 dark:bg-yellow-950/20',
  intolerance:      'border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/20',
}

const SEVERITY_TEXT: Record<string, string> = {
  life_threatening: 'text-red-700 dark:text-red-300',
  severe:           'text-orange-700 dark:text-orange-300',
  moderate:         'text-amber-700 dark:text-amber-300',
  mild:             'text-yellow-700 dark:text-yellow-300',
  intolerance:      'text-blue-700 dark:text-blue-300',
}

const showAddForm = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({
  allergy_type: 'drug', allergen_name: '', reaction_description: '',
  severity: 'moderate', notes: '',
})

const allGroups = computed(() => Object.entries(props.allergies))

async function submit() {
  if (!form.value.allergen_name.trim()) return
  saving.value = true
  error.value = ''
  try {
    await axios.post(`/participants/${props.participant.id}/allergies`, form.value)
    // Reload the page to get fresh allergy data from server
    window.location.reload()
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save allergy.'
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Allergies</h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon class="w-3 h-3" />
        Add Allergy
      </button>
    </div>

    <!-- Add allergy form -->
    <div v-if="showAddForm" class="bg-gray-50 dark:bg-slate-700/50 rounded-lg border border-gray-200 dark:border-slate-600 p-4 mb-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Add Allergy</h3>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Type</label>
          <select v-model="form.allergy_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option v-for="(label, key) in ALLERGY_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Severity</label>
          <select v-model="form.severity" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
            <option value="life_threatening">Life-Threatening</option>
            <option value="severe">Severe</option>
            <option value="moderate">Moderate</option>
            <option value="mild">Mild</option>
            <option value="intolerance">Intolerance</option>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Allergen Name *</label>
        <input v-model="form.allergen_name" type="text" placeholder="e.g. Penicillin" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
      </div>
      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Reaction Description</label>
        <input v-model="form.reaction_description" type="text" placeholder="Optional" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
      </div>
      <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
      <div class="flex gap-2">
        <button :disabled="saving" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="submit">
          {{ saving ? 'Saving...' : 'Save' }}
        </button>
        <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors" @click="showAddForm = false">Cancel</button>
      </div>
    </div>

    <!-- No allergies -->
    <div v-if="allGroups.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No allergies on file.</div>

    <!-- Grouped allergy lists -->
    <div v-else class="space-y-6">
      <div v-for="[type, items] in allGroups" :key="type">
        <h3 class="text-xs font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-2">
          {{ ALLERGY_TYPE_LABELS[type] ?? type }}
        </h3>
        <div class="space-y-2">
          <div
            v-for="allergy in items"
            :key="allergy.id"
            :class="['border rounded-lg p-3', SEVERITY_COLORS[allergy.severity] ?? 'border-gray-200 dark:border-slate-700']"
          >
            <div class="flex items-center gap-2 flex-wrap">
              <ExclamationTriangleIcon
                v-if="allergy.severity === 'life_threatening'"
                class="w-4 h-4 text-red-600 dark:text-red-400 shrink-0"
              />
              <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ allergy.allergen_name }}</span>
              <span :class="['text-xs font-medium capitalize', SEVERITY_TEXT[allergy.severity] ?? 'text-gray-600 dark:text-slate-400']">
                {{ allergy.severity.replace('_', ' ') }}
              </span>
              <span v-if="!allergy.is_active" class="text-xs bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 px-1.5 py-0.5 rounded">Inactive</span>
            </div>
            <p v-if="allergy.reaction_description" class="text-xs text-gray-600 dark:text-slate-400 mt-1">
              Reaction: {{ allergy.reaction_description }}
            </p>
            <p v-if="allergy.notes" class="text-xs text-gray-500 dark:text-slate-500 mt-0.5">{{ allergy.notes }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
