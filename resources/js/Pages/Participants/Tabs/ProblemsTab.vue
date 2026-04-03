<script setup lang="ts">
// ─── Tabs/ProblemsTab.vue ─────────────────────────────────────────────────────
// Active and resolved ICD-10 diagnosis list (problem list). Loaded from the
// Inertia prop on initial page load. Shows primary diagnosis badge and
// status (active/chronic/resolved) with color coding.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, watch } from 'vue'

interface Problem {
    id: number
    icd10_code: string
    icd10_description: string
    category: string | null
    status: string
    onset_date: string | null
    is_primary_diagnosis: boolean
}

const props = defineProps<{
    participantId: number
    initialProblems: Problem[]
    icd10Codes: { code: string; description: string }[]
}>()

const problems = ref<Problem[]>(props.initialProblems)
watch(
    () => props.initialProblems,
    (v) => {
        problems.value = v
    },
)

const STATUS_COLORS: Record<string, string> = {
    active: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    chronic: 'bg-orange-100 dark:bg-orange-950/60 text-orange-700 dark:text-orange-300',
    resolved: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    ruled_out: 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
}

function fmtDate(val: string | null): string {
    if (!val) return '-'
    return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">
                Diagnoses ({{ problems.length }})
            </h3>
            <button
                class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors opacity-50 cursor-not-allowed"
                disabled
                aria-label="Add diagnosis (coming soon)"
            >
                + Add Diagnosis
            </button>
        </div>
        <p
            v-if="problems.length === 0"
            class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center"
        >
            No diagnoses on file.
        </p>
        <div v-else class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
            <table class="text-sm w-full">
                <thead class="bg-gray-50 dark:bg-slate-700/50">
                    <tr>
                        <th
                            class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                        >
                            ICD-10
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                        >
                            Description
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                        >
                            Status
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                        >
                            Primary
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                        >
                            Onset
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                    <tr
                        v-for="p in problems"
                        :key="p.id"
                        class="bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700/50"
                    >
                        <td
                            class="px-4 py-2 font-mono text-xs text-slate-700 dark:text-slate-300 whitespace-nowrap"
                        >
                            {{ p.icd10_code }}
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-700 dark:text-slate-300">
                            {{ p.icd10_description }}
                        </td>
                        <td class="px-4 py-2">
                            <span
                                :class="`text-xs px-1.5 py-0.5 rounded-full font-medium ${STATUS_COLORS[p.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-500'}`"
                                >{{ p.status }}</span
                            >
                        </td>
                        <td class="px-4 py-2 text-xs text-center">
                            <span
                                v-if="p.is_primary_diagnosis"
                                class="text-xs bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded"
                                >Primary</span
                            >
                            <span v-else class="text-gray-300 dark:text-slate-600">-</span>
                        </td>
                        <td
                            class="px-4 py-2 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap"
                        >
                            {{ fmtDate(p.onset_date) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
