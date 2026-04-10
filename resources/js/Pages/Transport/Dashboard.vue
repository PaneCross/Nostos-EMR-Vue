<script setup lang="ts">
// Transport/Dashboard.vue
// Shows all active participants with their transport-relevant flags (wheelchair,
// stretcher, oxygen, behavioral) and home address. Click any row to navigate
// to that participant's profile. Transport integration with Nostos transport app
// is pending deployment - an amber notice is shown in the header.
// Route: GET /transport → Inertia::render('Transport/Dashboard')

import { ref, computed } from 'vue'
import { Head, usePage, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/solid'

// ── Types ──────────────────────────────────────────────────────────────────────

interface TransportFlag {
    flag_type: 'wheelchair' | 'stretcher' | 'oxygen' | 'behavioral'
    severity: 'low' | 'medium' | 'high' | 'critical'
    description: string | null
}

interface HomeAddress {
    line: string
    city: string
    state: string
    zip: string
}

interface ParticipantRow {
    id: number
    mrn: string
    first_name: string
    last_name: string
    flags: TransportFlag[]
    address: HomeAddress | null
}

interface TransportStats {
    total_active: number
    needs_wheelchair: number
    needs_stretcher: number
    needs_oxygen: number
    has_behavioral: number
    no_flags: number
}

// ── Props from Inertia ─────────────────────────────────────────────────────────

const props = defineProps<{
    participants: ParticipantRow[]
    stats: TransportStats
}>()

// ── Flag config ────────────────────────────────────────────────────────────────

const FLAG_CONFIG: Record<string, { label: string; classes: string; dotColor: string }> = {
    wheelchair: {
        label:    'Wheelchair',
        classes:  'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300 ring-blue-600/20',
        dotColor: 'bg-blue-500',
    },
    stretcher: {
        label:    'Stretcher',
        classes:  'bg-orange-100 text-orange-800 ring-orange-600/20',
        dotColor: 'bg-orange-500',
    },
    oxygen: {
        label:    'Oxygen',
        classes:  'bg-teal-100 text-teal-800 dark:text-teal-300 ring-teal-600/20',
        dotColor: 'bg-teal-500',
    },
    behavioral: {
        label:    'Behavioral',
        classes:  'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 ring-red-600/20',
        dotColor: 'bg-red-500',
    },
}

const SEVERITY_CLASSES: Record<string, string> = {
    low:      'bg-blue-400',
    medium:   'bg-yellow-400',
    high:     'bg-orange-500',
    critical: 'bg-red-600',
}

// ── State ──────────────────────────────────────────────────────────────────────

const flagFilter = ref('')
const search     = ref('')

// ── Computed ───────────────────────────────────────────────────────────────────

const filtered = computed(() => {
    return props.participants.filter(p => {
        if (flagFilter.value === 'none' && p.flags.length > 0) return false
        if (flagFilter.value && flagFilter.value !== 'none' && !p.flags.some(f => f.flag_type === flagFilter.value)) return false
        if (search.value) {
            const q = search.value.toLowerCase()
            return (
                p.first_name.toLowerCase().includes(q) ||
                p.last_name.toLowerCase().includes(q)  ||
                p.mrn.toLowerCase().includes(q)
            )
        }
        return true
    })
})

const behavioralParticipants = computed(() =>
    props.participants.filter(p => p.flags.some(f => f.flag_type === 'behavioral'))
)

// ── Stat chips ─────────────────────────────────────────────────────────────────

const statChips = computed(() => [
    { label: 'Active Census',  count: props.stats.total_active,     color: 'bg-slate-50 dark:bg-slate-900 border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300',     filter: '' },
    { label: 'Wheelchair',     count: props.stats.needs_wheelchair, color: 'bg-blue-50 dark:bg-blue-950/60 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300',         filter: 'wheelchair' },
    { label: 'Stretcher',      count: props.stats.needs_stretcher,  color: 'bg-orange-50 border-orange-200 text-orange-800',   filter: 'stretcher' },
    { label: 'Oxygen',         count: props.stats.needs_oxygen,     color: 'bg-teal-50 dark:bg-teal-950/60 border-teal-200 text-teal-800 dark:text-teal-300',         filter: 'oxygen' },
    { label: 'Behavioral',     count: props.stats.has_behavioral,   color: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300',            filter: 'behavioral' },
    { label: 'No Flags',       count: props.stats.no_flags,         color: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300',      filter: 'none' },
])

// ── Helpers ────────────────────────────────────────────────────────────────────

function toggleFilter(chip: typeof statChips.value[0]) {
    flagFilter.value = flagFilter.value === chip.filter ? '' : chip.filter
}

function rowBorderClass(p: ParticipantRow): string {
    const hasBehavioral    = p.flags.some(f => f.flag_type === 'behavioral')
    const hasHighPriority  = p.flags.some(f => f.severity === 'critical' || f.severity === 'high')
    if (hasBehavioral)   return 'border-l-4 border-l-red-500 bg-red-50 dark:bg-red-950/20'
    if (hasHighPriority) return 'border-l-4 border-l-orange-400'
    if (p.flags.length > 0) return 'border-l-4 border-l-blue-400'
    return 'border-l-4 border-l-transparent'
}

function clearFilters() {
    flagFilter.value = ''
    search.value = ''
}
</script>

<template>
    <AppShell :breadcrumbs="[{ label: 'Transportation' }, { label: 'Dashboard' }]">
        <Head title="Transport Dashboard" />

        <!-- Amber integration-pending notice -->
        <div class="mb-5 flex items-start gap-3 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-4 py-3">
            <ExclamationTriangleIcon class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" aria-hidden="true" />
            <p class="text-sm text-amber-800 dark:text-amber-300">
                <span class="font-semibold">Nostos Integration Pending.</span>
                Transport integration is pending the Nostos transport deployment.
                Trip scheduling, dispatch map, and route management will be available once the live bridge is connected.
            </p>
        </div>

        <!-- Header -->
        <div class="flex items-center justify-between mb-5">
            <div>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Transport Dashboard</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Active participant transport needs, mobility equipment, behavioral flags. Click a row to view profile.
                </p>
            </div>
        </div>

        <!-- Stat chips -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
            <button
                v-for="chip in statChips"
                :key="chip.label"
                @click="toggleFilter(chip)"
                :class="[
                    'border rounded-xl px-4 py-3 text-left transition-all hover:shadow-sm',
                    chip.color,
                    flagFilter === chip.filter && chip.filter !== '' ? 'ring-2 ring-offset-1 ring-current' : ''
                ]"
            >
                <p class="text-2xl font-bold">{{ chip.count }}</p>
                <p class="text-xs font-medium mt-0.5">{{ chip.label }}</p>
            </button>
        </div>

        <!-- Behavioral warning banner -->
        <div
            v-if="behavioralParticipants.length > 0"
            class="mb-4 flex items-start gap-3 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3"
        >
            <ExclamationTriangleIcon class="w-5 h-5 text-red-500 shrink-0 mt-0.5" aria-hidden="true" />
            <div>
                <p class="text-sm font-semibold text-red-800 dark:text-red-300">
                    {{ behavioralParticipants.length }} participant{{ behavioralParticipants.length !== 1 ? 's' : '' }}
                    with active behavioral flag{{ behavioralParticipants.length !== 1 ? 's' : '' }}
                </p>
                <p class="text-xs text-red-700 dark:text-red-300 mt-0.5">
                    {{ behavioralParticipants.map(p => `${p.first_name} ${p.last_name}`).join(', ') }}
                </p>
            </div>
        </div>

        <!-- Filters + search -->
        <div class="flex items-center gap-3 mb-4 flex-wrap">
            <input
                v-model="search"
                type="text"
                placeholder="Search by name or MRN..."
                class="rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm py-1.5 px-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-56"
                aria-label="Search participants"
            />
            <select name="flagFilter"
                v-model="flagFilter"
                class="rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm py-1.5 px-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                aria-label="Filter by flag type"
            >
                <option value="">All participants</option>
                <option value="wheelchair">Wheelchair</option>
                <option value="stretcher">Stretcher</option>
                <option value="oxygen">Oxygen</option>
                <option value="behavioral">Behavioral flag</option>
                <option value="none">No transport flags</option>
            </select>
            <button
                v-if="flagFilter || search"
                @click="clearFilters"
                class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-700"
            >
                Clear
            </button>
            <span class="ml-auto text-sm text-slate-500 dark:text-slate-400">
                {{ filtered.length }} participant{{ filtered.length !== 1 ? 's' : '' }}
            </span>
        </div>

        <!-- Table -->
        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div v-if="filtered.length === 0" class="px-6 py-12 text-center text-sm text-slate-500 dark:text-slate-400">
                No participants match the current filters.
            </div>
            <table v-else class="min-w-full divide-y divide-slate-100 dark:divide-slate-700 text-sm">
                <thead class="bg-slate-50 dark:bg-slate-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Participant</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Transport Flags</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Home Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    <tr
                        v-for="p in filtered"
                        :key="p.id"
                        @click="router.visit(`/participants/${p.id}?tab=flags`)"
                        :class="['cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors', rowBorderClass(p)]"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-800 dark:text-slate-200">{{ p.first_name }} {{ p.last_name }}</p>
                            <p class="text-xs text-slate-400">{{ p.mrn }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="p.flags.length === 0" class="text-slate-300 text-xs">None</span>
                            <div v-else class="flex flex-wrap gap-1">
                                <span
                                    v-for="(flag, i) in p.flags"
                                    :key="i"
                                    :class="['inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset', FLAG_CONFIG[flag.flag_type]?.classes ?? '']"
                                    :title="flag.description ?? FLAG_CONFIG[flag.flag_type]?.label ?? flag.flag_type"
                                >
                                    <span
                                        :class="['w-1.5 h-1.5 rounded-full', SEVERITY_CLASSES[flag.severity] ?? 'bg-slate-400']"
                                    />
                                    {{ FLAG_CONFIG[flag.flag_type]?.label ?? flag.flag_type }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-400">
                            <div v-if="p.address">
                                <p class="text-sm">{{ p.address.line }}</p>
                                <p class="text-xs text-slate-400">{{ p.address.city }}, {{ p.address.state }} {{ p.address.zip }}</p>
                            </div>
                            <span v-else class="text-slate-300 text-xs">No address on file</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-slate-400">
            Transport flags are synced from participant profiles. Trip scheduling, dispatch map, and route management are coming in a future phase.
        </p>
    </AppShell>
</template>
