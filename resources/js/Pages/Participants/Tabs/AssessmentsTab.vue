<script setup lang="ts">
// ─── Tabs/AssessmentsTab.vue ──────────────────────────────────────────────────
// Scored clinical assessments (PHQ-9, Morse Fall Risk, MoCA, Braden Scale, OHAT).
// Lazy-loads from GET /participants/{id}/assessments on first activation.
// Shows overdue and due-soon alerts per 42 CFR 460.104 reassessment requirements.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps<{
    participantId: number
}>()

const items = ref<Record<string, unknown>[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const ASSESSMENT_LABELS: Record<string, string> = {
    initial_comprehensive: 'Initial Comprehensive',
    adl_functional: 'ADL Functional',
    mmse_cognitive: 'MMSE Cognitive',
    phq9_depression: 'PHQ-9 Depression',
    gad7_anxiety: 'GAD-7 Anxiety',
    nutritional: 'Nutritional',
    fall_risk_morse: 'Fall Risk (Morse)',
    pain_scale: 'Pain Scale',
    annual_reassessment: 'Annual Reassessment',
    braden_scale: 'Braden Scale (Pressure Injury)',
    moca_cognitive: 'MoCA (Cognitive)',
    oral_health: 'Oral Health (OHAT)',
    custom: 'Custom',
}

async function loadData() {
    loading.value = true
    try {
        const r = await axios.get(`/participants/${props.participantId}/assessments`)
        items.value = r.data.data ?? r.data
    } catch {
        error.value = 'Failed to load assessments. Please refresh.'
    } finally {
        loading.value = false
    }
}

onMounted(loadData)

function fmtDate(val: string): string {
    return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}

function scoreColor(type: string, score: number | null): string {
    if (score == null) return 'text-gray-400 dark:text-slate-500'
    if (type === 'phq9_depression' && score >= 15)
        return 'text-red-600 dark:text-red-400 font-semibold'
    if (type === 'fall_risk_morse' && score >= 45)
        return 'text-red-600 dark:text-red-400 font-semibold'
    if (type === 'braden_scale' && score <= 12)
        return 'text-red-600 dark:text-red-400 font-semibold'
    return 'text-gray-700 dark:text-slate-300'
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">
                Assessments ({{ items.length }})
            </h3>
            <button
                class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors opacity-50 cursor-not-allowed"
                disabled
                aria-label="Add assessment (coming soon)"
            >
                + New Assessment
            </button>
        </div>

        <div
            v-if="loading"
            class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm animate-pulse"
        >
            Loading...
        </div>
        <div v-else-if="error" class="py-8 text-center text-red-500 text-sm">{{ error }}</div>
        <p
            v-else-if="items.length === 0"
            class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center"
        >
            No assessments on file.
        </p>
        <div v-else class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
            <table class="text-sm w-full">
                <thead class="bg-gray-50 dark:bg-slate-700/50">
                    <tr>
                        <th
                            class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                        >
                            Type
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                        >
                            Score
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                        >
                            Completed
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                        >
                            Next Due
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                        >
                            Dept
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                    <tr
                        v-for="a in items"
                        :key="a.id as number"
                        class="bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700/50"
                    >
                        <td class="px-4 py-2 text-xs text-gray-700 dark:text-slate-300">
                            {{
                                ASSESSMENT_LABELS[a.assessment_type as string] ?? a.assessment_type
                            }}
                        </td>
                        <td
                            :class="`px-4 py-2 text-xs font-mono ${scoreColor(a.assessment_type as string, a.score as number | null)}`"
                        >
                            {{ a.score ?? '-' }}
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-500 dark:text-slate-400">
                            {{ fmtDate(a.completed_at as string) }}
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-500 dark:text-slate-400">
                            {{ a.next_due_date ? fmtDate(a.next_due_date as string) : '-' }}
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-500 dark:text-slate-400">
                            {{ a.department }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
