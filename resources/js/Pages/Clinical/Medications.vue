<script setup lang="ts">
// ─── Clinical/Medications ───────────────────────────────────────────────────
// Org-wide medication overview: 4 KPIs + searchable participant table with
// active / PRN / controlled-substance counts and any open drug-drug
// interaction alerts.
//
// Audience: Primary Care prescribers + Pharmacy.
//
// Notable rules:
//   - Beers Criteria + polypharmacy alerts are evaluated against the active
//     med list; rows with open alerts get an amber left border.
//   - Controlled-substance counts trip ePrescribing/EPCS workflows (DrFirst
//     Rcopia integration is paywall-deferred — current state surfaces
//     internal records only).
// ────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import {
    MagnifyingGlassIcon,
    BeakerIcon,
    ExclamationTriangleIcon,
    ShieldExclamationIcon,
    UserGroupIcon,
} from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface MedicationKPIs {
    total_active: number
    total_prn: number
    active_interaction_alerts: number
    participants_with_meds: number
}

interface MedParticipant {
    id: number
    name: string
    mrn: string
    active_count: number
    prn_count: number
    controlled_count: number
    open_alerts: number
}

const props = defineProps<{
    kpis: MedicationKPIs
    participants: MedParticipant[]
}>()

// ── Search ─────────────────────────────────────────────────────────────────────

const search = ref('')

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.participants
    return props.participants.filter(
        (p) =>
            p.name.toLowerCase().includes(q) ||
            p.mrn.toLowerCase().includes(q),
    )
})
</script>

<template>
    <Head title="Medications Overview" />

    <AppShell>
        <template #header>
            <div class="flex items-center gap-2">
                <BeakerIcon class="w-5 h-5 text-gray-500 dark:text-slate-400" aria-hidden="true" />
                <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">Medications Overview</h1>
            </div>
        </template>

        <div class="px-6 py-5">
            <!-- ── KPI cards ── -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <!-- Total Active -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 shadow-sm flex items-start gap-3">
                    <div class="p-2 bg-blue-50 dark:bg-blue-900/40 rounded-lg mt-0.5 flex-shrink-0">
                        <BeakerIcon class="w-4 h-4 text-blue-600 dark:text-blue-400" aria-hidden="true" />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">Total Active Meds</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-slate-100 mt-0.5">
                            {{ kpis.total_active.toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- PRN Meds -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 shadow-sm flex items-start gap-3">
                    <div class="p-2 bg-purple-50 dark:bg-purple-900/40 rounded-lg mt-0.5 flex-shrink-0">
                        <BeakerIcon class="w-4 h-4 text-purple-600 dark:text-purple-400" aria-hidden="true" />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">PRN Meds</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-slate-100 mt-0.5">
                            {{ kpis.total_prn.toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- Interaction Alerts -->
                <div
                    :class="[
                        'bg-white dark:bg-slate-800 rounded-xl border px-4 py-3 shadow-sm flex items-start gap-3',
                        kpis.active_interaction_alerts > 0
                            ? 'border-red-300 dark:border-red-700'
                            : 'border-gray-200 dark:border-slate-700',
                    ]"
                >
                    <div
                        :class="[
                            'p-2 rounded-lg mt-0.5 flex-shrink-0',
                            kpis.active_interaction_alerts > 0
                                ? 'bg-red-50 dark:bg-red-900/40'
                                : 'bg-gray-50 dark:bg-slate-700/50',
                        ]"
                    >
                        <ExclamationTriangleIcon
                            :class="[
                                'w-4 h-4',
                                kpis.active_interaction_alerts > 0
                                    ? 'text-red-600 dark:text-red-400'
                                    : 'text-gray-400 dark:text-slate-500',
                            ]"
                            aria-hidden="true"
                        />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">Interaction Alerts</p>
                        <p
                            :class="[
                                'text-2xl font-bold mt-0.5',
                                kpis.active_interaction_alerts > 0
                                    ? 'text-red-600 dark:text-red-400'
                                    : 'text-gray-900 dark:text-slate-100',
                            ]"
                        >
                            {{ kpis.active_interaction_alerts.toLocaleString() }}
                        </p>
                    </div>
                </div>

                <!-- Participants on Meds -->
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 shadow-sm flex items-start gap-3">
                    <div class="p-2 bg-green-50 dark:bg-green-900/40 rounded-lg mt-0.5 flex-shrink-0">
                        <UserGroupIcon class="w-4 h-4 text-green-600 dark:text-green-400" aria-hidden="true" />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">Participants on Meds</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-slate-100 mt-0.5">
                            {{ kpis.participants_with_meds.toLocaleString() }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── Search bar ── -->
            <div class="relative mb-4 max-w-sm">
                <MagnifyingGlassIcon
                    class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-slate-500"
                    aria-hidden="true"
                />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by name or MRN"
                    aria-label="Search participants"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400"
                />
            </div>

            <!-- ── Table ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="w-full text-sm" aria-label="Participant medication list">
                    <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
                        <tr>
                            <th
                                v-for="col in ['Participant', 'Active Meds', 'PRN', 'Controlled', 'Drug Alerts']"
                                :key="col"
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            >
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <!-- Empty state -->
                        <tr v-if="filtered.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-10 text-center text-gray-400 dark:text-slate-500"
                            >
                                No participants match your search.
                            </td>
                        </tr>

                        <!-- Rows -->
                        <tr
                            v-for="ppt in filtered"
                            :key="ppt.id"
                            :class="[
                                'hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors',
                                ppt.open_alerts > 0
                                    ? 'border-l-4 border-amber-400 dark:border-amber-500'
                                    : '',
                            ]"
                            tabindex="0"
                            :aria-label="`Open profile for ${ppt.name}`"
                            @click="router.visit(`/participants/${ppt.id}?tab=medications`)"
                            @keydown.enter="router.visit(`/participants/${ppt.id}?tab=medications`)"
                        >
                            <!-- Participant -->
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-slate-100">{{ ppt.name }}</div>
                                <div class="text-xs font-mono text-gray-400 dark:text-slate-500">{{ ppt.mrn }}</div>
                            </td>

                            <!-- Active count -->
                            <td class="px-4 py-3 text-gray-700 dark:text-slate-300 font-medium">
                                {{ ppt.active_count }}
                            </td>

                            <!-- PRN count -->
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                {{ ppt.prn_count }}
                            </td>

                            <!-- Controlled count -->
                            <td class="px-4 py-3">
                                <span
                                    v-if="ppt.controlled_count > 0"
                                    class="inline-flex items-center gap-1 text-amber-700 dark:text-amber-300 font-medium"
                                >
                                    <ShieldExclamationIcon class="w-3.5 h-3.5" aria-hidden="true" />
                                    {{ ppt.controlled_count }}
                                </span>
                                <span v-else class="text-gray-400 dark:text-slate-500">0</span>
                            </td>

                            <!-- Drug alerts badge -->
                            <td class="px-4 py-3">
                                <span
                                    v-if="ppt.open_alerts > 0"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300"
                                >
                                    <ExclamationTriangleIcon class="w-3 h-3" aria-hidden="true" />
                                    {{ ppt.open_alerts }}
                                </span>
                                <span
                                    v-else
                                    class="text-gray-400 dark:text-slate-500 text-xs"
                                >
                                    -
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Row count ── -->
            <p class="mt-3 text-xs text-gray-400 dark:text-slate-500">
                Showing {{ filtered.length }} of {{ participants.length }} participants
            </p>
        </div>
    </AppShell>
</template>
