<template>
    <AppShell>
        <Head title="Risk Adjustment" />

        <div class="p-6 space-y-6">
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Risk Adjustment</h1>
                <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200"
                >
                    {{ year }}
                </span>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-1">
                    <p
                        class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"
                    >
                        Total Participants
                    </p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ gapSummary.total_participants }}
                    </p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-1">
                    <p
                        class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"
                    >
                        With Gaps
                    </p>
                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">
                        {{ gapSummary.participants_with_gaps }}
                    </p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-1">
                    <p
                        class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"
                    >
                        Total Gaps
                    </p>
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400">
                        {{ gapSummary.total_gaps }}
                    </p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-1">
                    <p
                        class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"
                    >
                        Est. Revenue Impact
                    </p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">
                        ${{ gapSummary.estimated_revenue_impact.toLocaleString() }}
                    </p>
                </div>
            </div>

            <!-- Search -->
            <div class="relative max-w-sm">
                <MagnifyingGlassIcon
                    class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"
                />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by participant..."
                    class="w-full pl-9 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                <table
                    v-if="filtered.length > 0"
                    class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                >
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Participant
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Risk Score
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Source
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Year
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"
                            >
                                Calculated At
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700"
                    >
                        <tr
                            v-for="score in filtered"
                            :key="score.id"
                            class="hover:bg-gray-50 dark:hover:bg-gray-700"
                        >
                            <td class="px-6 py-4 text-sm">
                                <a
                                    v-if="score.participant"
                                    :href="`/participants/${score.participant.id}`"
                                    class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium"
                                >
                                    {{ score.participant.first_name }}
                                    {{ score.participant.last_name }}
                                    <span class="text-gray-400 font-normal ml-1"
                                        >({{ score.participant.mrn }})</span
                                    >
                                </a>
                                <span v-else class="text-gray-400">-</span>
                            </td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-900 dark:text-white">
                                {{ score.risk_score.toFixed(2) }}
                            </td>
                            <td class="px-6 py-4">
                                <span
                                    :class="sourceClass(score.score_source)"
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                >
                                    {{ sourceLabel(score.score_source) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                {{ score.payment_year }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                {{ score.calculated_at ?? '-' }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div
                    v-else
                    class="flex flex-col items-center justify-center py-16 text-gray-500 dark:text-gray-400"
                >
                    <ChartBarIcon class="w-10 h-10 mb-3 text-gray-300 dark:text-gray-600" />
                    <p class="text-sm">No risk scores found.</p>
                </div>
            </div>
        </div>
    </AppShell>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import { MagnifyingGlassIcon, ChartBarIcon } from '@heroicons/vue/24/outline'

interface GapSummary {
    total_participants: number
    participants_with_gaps: number
    total_gaps: number
    estimated_revenue_impact: number
}

interface RiskScore {
    id: number
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null
    payment_year: number
    risk_score: number
    score_source: 'cms_import' | 'calculated' | 'manual'
    calculated_at: string | null
}

const props = defineProps<{
    gapSummary: GapSummary
    riskScores: RiskScore[]
    year: number
}>()

const search = ref('')

const filtered = computed(() => {
    const q = search.value.toLowerCase().trim()
    if (!q) return props.riskScores
    return props.riskScores.filter((s) => {
        if (!s.participant) return false
        const name = `${s.participant.first_name} ${s.participant.last_name}`.toLowerCase()
        return name.includes(q) || s.participant.mrn.toLowerCase().includes(q)
    })
})

function sourceClass(source: string): string {
    if (source === 'cms_import')
        return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'
    if (source === 'calculated')
        return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
    return 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'
}

function sourceLabel(source: string): string {
    if (source === 'cms_import') return 'CMS Import'
    if (source === 'calculated') return 'Calculated'
    return 'Manual'
}
</script>
