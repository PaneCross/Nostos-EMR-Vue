<script setup lang="ts">
// ─── Tabs/AllergiesTab.vue ────────────────────────────────────────────────────
// Allergy and intolerance list with severity color coding. Life-threatening
// allergies are surfaced at the top in a red alert box. Loaded from the
// Inertia prop on initial page load; also accepts new allergies via Add form.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, watch } from 'vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface Allergy {
    id: number
    allergen_name: string
    allergy_type: string
    severity: string
    reaction_description: string | null
    is_active: boolean
}

const props = defineProps<{
    participantId: number
    initialAllergies: Allergy[]
}>()

const allergies = ref<Allergy[]>(props.initialAllergies)
watch(
    () => props.initialAllergies,
    (v) => {
        allergies.value = v
    },
)

const active = computed(() => allergies.value.filter((a) => a.is_active))
const inactive = computed(() => allergies.value.filter((a) => !a.is_active))
const lifeThreatening = computed(() =>
    active.value.filter((a) => a.severity === 'life_threatening'),
)

const SEVERITY_COLORS: Record<string, string> = {
    life_threatening:
        'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 border border-red-300',
    severe: 'bg-orange-100 dark:bg-orange-950/60 text-orange-800 dark:text-orange-300 border border-orange-200',
    moderate:
        'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300 border border-amber-200',
    mild: 'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300 border border-yellow-200',
    intolerance:
        'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300 border border-blue-200',
}

const TYPE_LABELS: Record<string, string> = {
    drug: 'Drug',
    food: 'Food',
    environmental: 'Environmental',
    dietary_restriction: 'Dietary',
    latex: 'Latex',
    contrast: 'Contrast',
}

function severityLabel(sev: string): string {
    return sev === 'life_threatening'
        ? 'Life Threatening'
        : sev.charAt(0).toUpperCase() + sev.slice(1).replace('_', ' ')
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">
                Allergies ({{ active.length }} active)
            </h3>
            <button
                class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors opacity-50 cursor-not-allowed"
                disabled
                aria-label="Add allergy (coming soon)"
            >
                + Add Allergy
            </button>
        </div>

        <!-- Life-threatening alert section -->
        <div
            v-if="lifeThreatening.length > 0"
            class="mb-4 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded-lg p-4"
        >
            <p
                class="text-xs font-bold text-red-700 dark:text-red-300 uppercase tracking-wide mb-2 flex items-center gap-1"
            >
                <ExclamationTriangleIcon class="w-4 h-4" /> Life-Threatening Allergies
            </p>
            <div
                v-for="a in lifeThreatening"
                :key="a.id"
                class="text-sm font-semibold text-red-800 dark:text-red-300"
            >
                {{ a.allergen_name }}
                <span class="font-normal text-red-600 dark:text-red-400 ml-1"
                    >({{ TYPE_LABELS[a.allergy_type] ?? a.allergy_type }})</span
                >
                <span
                    v-if="a.reaction_description"
                    class="font-normal text-red-600 dark:text-red-400"
                    >: {{ a.reaction_description }}</span
                >
            </div>
        </div>

        <!-- NKDA notice -->
        <p
            v-if="active.length === 0"
            class="text-sm font-bold text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-950/60 border border-green-200 dark:border-green-800 rounded-lg px-4 py-3"
        >
            NKDA: No Known Drug Allergies
        </p>

        <!-- Other active allergies -->
        <div
            v-if="active.filter((a) => a.severity !== 'life_threatening').length > 0"
            class="space-y-2"
        >
            <div
                v-for="a in active.filter((a) => a.severity !== 'life_threatening')"
                :key="a.id"
                class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3 flex items-start gap-3"
            >
                <span
                    :class="`text-xs px-1.5 py-0.5 rounded font-medium ${SEVERITY_COLORS[a.severity] ?? 'bg-gray-100 border-gray-200 text-gray-600'}`"
                    >{{ severityLabel(a.severity) }}</span
                >
                <div>
                    <span class="text-sm font-medium text-gray-900 dark:text-slate-100">{{
                        a.allergen_name
                    }}</span>
                    <span class="text-xs text-gray-500 dark:text-slate-400 ml-1"
                        >({{ TYPE_LABELS[a.allergy_type] ?? a.allergy_type }})</span
                    >
                    <span
                        v-if="a.reaction_description"
                        class="text-xs text-gray-500 dark:text-slate-400 ml-1"
                        >: {{ a.reaction_description }}</span
                    >
                </div>
            </div>
        </div>

        <!-- Inactive allergies -->
        <div v-if="inactive.length > 0" class="mt-4">
            <h4
                class="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wide mb-2"
            >
                Resolved / Inactive ({{ inactive.length }})
            </h4>
            <div class="space-y-1 opacity-60">
                <div
                    v-for="a in inactive"
                    :key="a.id"
                    class="text-sm text-gray-600 dark:text-slate-400 border border-gray-200 dark:border-slate-700 rounded px-3 py-1.5"
                >
                    {{ a.allergen_name }} ({{ TYPE_LABELS[a.allergy_type] ?? a.allergy_type }})
                </div>
            </div>
        </div>
    </div>
</template>
