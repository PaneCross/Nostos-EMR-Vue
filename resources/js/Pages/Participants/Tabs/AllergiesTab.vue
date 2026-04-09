<script setup lang="ts">
// ─── AllergiesTab.vue ─────────────────────────────────────────────────────────
// Displays participant allergies grouped by type. Life-threatening allergies get
// a red highlighted block at the top. Add allergy form uses blue background.
// After save, uses router.reload for partial prop refresh.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
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

// Row card background + border color by severity
const SEVERITY_ROW_COLORS: Record<string, string> = {
  life_threatening: 'text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-950/60 border-red-300 dark:border-red-800',
  severe:           'text-orange-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-950/60 border-orange-200 dark:border-orange-800',
  moderate:         'text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800',
  mild:             'text-yellow-700 dark:text-yellow-300 bg-yellow-50 dark:bg-yellow-950/60 border-yellow-200 dark:border-yellow-800',
  intolerance:      'text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-950/60 border-blue-200 dark:border-blue-800',
}

// Severity badge pill color
const SEVERITY_BADGE_COLORS: Record<string, string> = {
  life_threatening: 'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 border border-red-300 dark:border-red-700',
  severe:           'bg-orange-100 dark:bg-orange-950/60 text-orange-800 dark:text-orange-300 border border-orange-200 dark:border-orange-800',
  moderate:         'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-800',
  mild:             'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400 border border-gray-200 dark:border-slate-700',
  intolerance:      'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400 border border-gray-200 dark:border-slate-700',
}

const showAddForm = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({
  allergy_type: 'drug', allergen_name: '', reaction_description: '',
  severity: 'moderate', notes: '',
})

// Flatten all grouped allergies into one array for life-threatening check
const allAllergies = computed((): Allergy[] =>
  Object.values(props.allergies).flat()
)

const lifeThreats = computed(() =>
  allAllergies.value.filter(a => a.severity === 'life_threatening' && a.is_active)
)

const allGroups = computed(() => Object.entries(props.allergies))

async function submit() {
  if (!form.value.allergen_name.trim()) return
  saving.value = true
  error.value = ''
  try {
    await axios.post(`/participants/${props.participant.id}/allergies`, {
      ...form.value,
      is_active: true,
    })
    showAddForm.value = false
    form.value = { allergy_type: 'drug', allergen_name: '', reaction_description: '', severity: 'moderate', notes: '' }
    router.reload({ only: ['allergies'] })
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
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">
        Allergies &amp; Restrictions ({{ allAllergies.length }})
      </h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="showAddForm = !showAddForm"
      >
        <PlusIcon class="w-3 h-3" />
        {{ showAddForm ? 'Cancel' : 'Add Allergy' }}
      </button>
    </div>

    <!-- Life-threatening allergies block -->
    <div
      v-if="lifeThreats.length > 0"
      class="bg-red-50 dark:bg-red-950/60 border border-red-300 dark:border-red-800 rounded-lg px-4 py-3 mb-4"
    >
      <h4 class="text-sm font-semibold text-red-700 dark:text-red-300 mb-2 flex items-center gap-1">
        <ExclamationTriangleIcon class="w-4 h-4" />
        Life-Threatening Allergies
      </h4>
      <div class="space-y-1">
        <div
          v-for="a in lifeThreats"
          :key="a.id"
          class="flex items-center gap-2 text-sm text-red-800 dark:text-red-300"
        >
          <span class="font-semibold">{{ a.allergen_name }}</span>
          <span v-if="a.reaction_description" class="text-red-600 dark:text-red-400">
            {{ a.reaction_description }}
          </span>
        </div>
      </div>
    </div>

    <!-- Add allergy form -->
    <div
      v-if="showAddForm"
      class="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4 grid grid-cols-2 gap-3"
    >
      <div>
        <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Type</label>
        <select v-model="form.allergy_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800">
          <option v-for="(label, key) in ALLERGY_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Allergen / Item *</label>
        <input
          v-model="form.allergen_name"
          type="text"
          placeholder="e.g. Penicillin, Shellfish, Low sodium diet"
          class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Severity</label>
        <select v-model="form.severity" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800">
          <option value="life_threatening">Life-Threatening</option>
          <option value="severe">Severe</option>
          <option value="moderate">Moderate</option>
          <option value="mild">Mild</option>
          <option value="intolerance">Intolerance</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">Reaction</label>
        <input
          v-model="form.reaction_description"
          type="text"
          placeholder="e.g. Anaphylaxis, Rash, GI upset"
          class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
        />
      </div>
      <div class="col-span-2">
        <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
        <div class="flex gap-2">
          <button
            :disabled="saving"
            class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
            @click="submit"
          >
            {{ saving ? 'Saving...' : 'Save' }}
          </button>
          <button
            class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors"
            @click="showAddForm = false"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>

    <!-- No allergies -->
    <div v-if="allGroups.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">
      No allergies on file.
    </div>

    <!-- Grouped allergy lists -->
    <div v-else class="space-y-6">
      <div v-for="[type, items] in allGroups" :key="type">
        <h3 class="text-xs font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-2">
          {{ ALLERGY_TYPE_LABELS[type] ?? type }} ({{ items.length }})
        </h3>
        <div class="space-y-2">
          <div
            v-for="allergy in items"
            :key="allergy.id"
            :class="['border rounded-lg px-4 py-2.5 flex items-center gap-3', SEVERITY_ROW_COLORS[allergy.severity] ?? 'border-gray-200 dark:border-slate-700']"
          >
            <span class="font-medium text-sm flex-1">{{ allergy.allergen_name }}</span>
            <span v-if="allergy.reaction_description" class="text-xs opacity-75">
              {{ allergy.reaction_description }}
            </span>
            <span
              :class="['text-xs px-1.5 py-0.5 rounded-full font-medium flex-shrink-0', SEVERITY_BADGE_COLORS[allergy.severity] ?? 'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400 border border-gray-200 dark:border-slate-700']"
            >
              {{ allergy.severity.replace('_', ' ') }}
            </span>
            <span v-if="!allergy.is_active" class="text-xs bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 px-1.5 py-0.5 rounded">
              Inactive
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
