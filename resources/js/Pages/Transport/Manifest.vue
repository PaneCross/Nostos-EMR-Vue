<script setup lang="ts">
// Transport/Manifest.vue
// Daily run-sheet for the transportation team. Shows all scheduled transport
// requests for a selected date/site with real-time status updates via Reverb.
// Transport integration with Nostos transport app is pending deployment - a
// ComingSoonBanner is shown inline across the manifest content area.
// Route: GET /transport/manifest → Inertia::render('Transport/Manifest')

import { ref, computed, onMounted } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import {
    ExclamationTriangleIcon,
    TruckIcon,
    CalendarDaysIcon,
    ClipboardDocumentListIcon,
} from '@heroicons/vue/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Site {
    id: number
    name: string
}

interface ManifestFlag {
    flag_type: string
    severity: string
    description: string | null
}

interface ManifestRun {
    id: number
    participant_id: number
    participant_name: string
    mrn: string
    trip_type: string
    requested_pickup_time: string
    scheduled_pickup_time: string | null
    actual_pickup_time: string | null
    actual_dropoff_time: string | null
    pickup_location: string
    dropoff_location: string
    status: string
    special_instructions: string | null
    driver_notes: string | null
    mobility_flags: ManifestFlag[]
    transport_trip_id: number | null
}

// ── Props from Inertia ─────────────────────────────────────────────────────────

const props = defineProps<{
    sites: Site[]
}>()

// ── Status display config ──────────────────────────────────────────────────────

const STATUS_CONFIG: Record<string, { label: string; badgeClass: string }> = {
    requested: {
        label: 'Requested',
        badgeClass: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
    },
    scheduled: {
        label: 'Scheduled',
        badgeClass: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    },
    dispatched: {
        label: 'Dispatched',
        badgeClass: 'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300',
    },
    en_route: {
        label: 'En Route',
        badgeClass: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    },
    arrived: { label: 'Arrived', badgeClass: 'bg-teal-100 text-teal-700 dark:text-teal-300' },
    completed: {
        label: 'Completed',
        badgeClass: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    },
    no_show: {
        label: 'No Show',
        badgeClass: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    },
    cancelled: { label: 'Cancelled', badgeClass: 'bg-slate-100 dark:bg-slate-800 text-slate-400' },
}

// ── State ──────────────────────────────────────────────────────────────────────

const today = new Date().toISOString().split('T')[0]
const manifestDate = ref(today)
const selectedSiteId = ref<number | ''>(props.sites[0]?.id ?? '')
const activeTab = ref<'runsheet' | 'addon'>('runsheet')
const runs = ref<ManifestRun[]>([])
const loading = ref(false)

// ── Computed ───────────────────────────────────────────────────────────────────

const formattedDate = computed(() => {
    if (!manifestDate.value) return ''
    return new Date(manifestDate.value).toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    })
})

const activeRuns = computed(() => runs.value.filter((r) => r.status !== 'cancelled'))

// Note: transport integration is pending, so runs will always be empty until
// the Nostos transport bridge is connected.
const showComingSoon = computed(() => true)
</script>

<template>
    <AppShell :breadcrumbs="[{ label: 'Transportation' }, { label: 'Manifest' }]">
        <Head title="Transport Manifest" />

        <!-- Page header -->
        <div class="flex items-center justify-between mb-5">
            <div>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">
                    Transport Manifest
                </h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Daily run sheet for transport team scheduling and dispatch.
                </p>
            </div>
        </div>

        <!-- Date + Site controls -->
        <div class="flex items-center gap-3 mb-5 flex-wrap">
            <div class="flex items-center gap-2">
                <CalendarDaysIcon class="w-4 h-4 text-slate-400" aria-hidden="true" />
                <input
                    v-model="manifestDate"
                    type="date"
                    class="rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm py-1.5 px-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    aria-label="Manifest date"
                />
            </div>
            <select
                v-model="selectedSiteId"
                class="rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm py-1.5 px-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                aria-label="Select site"
            >
                <option v-for="site in sites" :key="site.id" :value="site.id">
                    {{ site.name }}
                </option>
            </select>
            <span v-if="manifestDate" class="text-sm text-slate-500 dark:text-slate-400">
                {{ formattedDate }}
            </span>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 mb-4 border-b border-slate-200 dark:border-slate-700">
            <button
                :class="[
                    'px-4 py-2 text-sm font-medium border-b-2 transition-colors',
                    activeTab === 'runsheet'
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200',
                ]"
                @click="activeTab = 'runsheet'"
            >
                <span class="flex items-center gap-1.5">
                    <ClipboardDocumentListIcon class="w-4 h-4" aria-hidden="true" />
                    Run Sheet
                </span>
            </button>
            <button
                :class="[
                    'px-4 py-2 text-sm font-medium border-b-2 transition-colors',
                    activeTab === 'addon'
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200',
                ]"
                @click="activeTab = 'addon'"
            >
                <span class="flex items-center gap-1.5">
                    <TruckIcon class="w-4 h-4" aria-hidden="true" />
                    Add-On Queue
                </span>
            </button>
        </div>

        <!-- Amber "Nostos Integration Pending" banner across main content area -->
        <div
            class="flex items-start gap-3 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-5 py-4 mb-4"
        >
            <ExclamationTriangleIcon
                class="w-5 h-5 text-amber-500 shrink-0 mt-0.5"
                aria-hidden="true"
            />
            <div>
                <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">
                    Nostos Integration Pending
                </p>
                <p class="text-sm text-amber-700 dark:text-amber-300 mt-0.5">
                    Transport integration is pending the Nostos transport deployment. The run sheet
                    will populate automatically once the live bridge is connected. EMR-side
                    transport requests are stored and ready for sync.
                </p>
            </div>
        </div>

        <!-- Run Sheet tab content -->
        <template v-if="activeTab === 'runsheet'">
            <div
                class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden"
            >
                <div class="px-6 py-12 text-center">
                    <TruckIcon
                        class="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-4"
                        aria-hidden="true"
                    />
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">
                        No runs available for {{ formattedDate || 'the selected date' }}
                    </p>
                    <p class="text-xs text-slate-400 dark:text-slate-500 max-w-md mx-auto">
                        Trip data will appear here once the Nostos transport integration is
                        connected. Transport requests submitted via the EMR are queued and ready.
                    </p>
                </div>
            </div>
        </template>

        <!-- Add-On Queue tab content -->
        <template v-if="activeTab === 'addon'">
            <div
                class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden"
            >
                <div class="px-6 py-12 text-center">
                    <ClipboardDocumentListIcon
                        class="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-4"
                        aria-hidden="true"
                    />
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">
                        No pending add-on requests
                    </p>
                    <p class="text-xs text-slate-400 dark:text-slate-500 max-w-md mx-auto">
                        Add-on trip requests from clinical staff will queue here for transport team
                        approval once the Nostos transport integration is live.
                    </p>
                </div>
            </div>
        </template>

        <p class="mt-3 text-xs text-slate-400">
            Export PDF and real-time status updates will be available after transport integration is
            connected.
        </p>
    </AppShell>
</template>
