<!-- ItAdmin/BreakGlass.vue -->
<!-- HIPAA break-the-glass emergency access log for IT Admins and supervisors. Lists all
     emr_break_glass_events for the tenant, most recent first. Supervisors acknowledge
     they have reviewed each event. Unacknowledged events older than 24 hours are
     highlighted in amber per 45 CFR 164.312(a)(2)(ii). -->

<script setup lang="ts">
// ─── ItAdmin/BreakGlass ─────────────────────────────────────────────────────
// "Break-the-glass" emergency access review: when a clinician needs to read
// a chart they normally couldn't (e.g. cross-site emergency), the system
// allows the read but records a break-glass event for after-the-fact review.
//
// Audience: IT Admin + supervisors review/ack each event.
//
// Notable rules:
//   - 45 CFR §164.312(a)(2)(ii) — emergency-access procedure must exist and
//     be reviewed. Events older than 24h without supervisor ack render amber.
//   - Append-only — events cannot be deleted; ack is the only mutation.
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    ShieldExclamationIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

interface BreakGlassUser {
    id: number
    name: string
    department: string
}

interface BreakGlassParticipant {
    id: number
    name: string
    mrn: string
}

interface BreakGlassEventItem {
    id: number
    user: BreakGlassUser | null
    participant: BreakGlassParticipant | null
    justification: string
    access_granted_at: string
    access_expires_at: string
    is_active: boolean
    is_acknowledged: boolean
    acknowledged_by: string | null
    acknowledged_at: string | null
    ip_address: string | null
    created_at: string
}

interface PageProps {
    events: BreakGlassEventItem[]
    unacknowledged_count: number
    [key: string]: unknown
}

const page = usePage<PageProps>()
const events = ref<BreakGlassEventItem[]>(page.props.events)
const unacknowledged_count = page.props.unacknowledged_count

const acknowledging = ref<number | null>(null)
const filter = ref<'all' | 'unacknowledged'>('all')

const filtered = computed(() =>
    filter.value === 'unacknowledged' ? events.value.filter(e => !e.is_acknowledged) : events.value
)

// Amber highlight: unacknowledged and granted > 24 hours ago
const isOverdue = (e: BreakGlassEventItem): boolean => {
    if (e.is_acknowledged) return false
    const grantedAt = new Date(e.access_granted_at)
    const hoursSince = (Date.now() - grantedAt.getTime()) / 3_600_000
    return hoursSince > 24
}

const handleAcknowledge = async (eventId: number) => {
    acknowledging.value = eventId
    try {
        await axios.post(`/it-admin/break-glass/${eventId}/acknowledge`)
        events.value = events.value.map(e =>
            e.id === eventId ? { ...e, is_acknowledged: true, acknowledged_at: new Date().toISOString() } : e
        )
    } catch {
        // silently handle — user can retry
    } finally {
        acknowledging.value = null
    }
}

const formatDate = (iso: string) =>
    new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })
</script>

<template>
    <AppShell>
        <Head title="Emergency Access Log" />

        <div class="max-w-6xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <ShieldExclamationIcon class="w-7 h-7 text-red-600 dark:text-red-400" />
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Emergency Access Log</h1>
                        <p class="text-sm text-gray-500 dark:text-slate-400">HIPAA break-the-glass events require supervisor review</p>
                    </div>
                </div>
                <span v-if="unacknowledged_count > 0"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300">
                    <ExclamationTriangleIcon class="w-4 h-4" />
                    {{ unacknowledged_count }} unreviewed
                </span>
            </div>

            <!-- Filter tabs -->
            <div class="flex gap-2 mb-5">
                <button
                    v-for="f in (['all', 'unacknowledged'] as const)"
                    :key="f"
                    @click="filter = f"
                    :class="filter === f
                        ? 'bg-blue-600 text-white'
                        : 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600'"
                    class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors"
                >
                    {{ f === 'all' ? 'All Events' : 'Unreviewed' }}
                </button>
            </div>

            <!-- Table -->
            <div v-if="filtered.length === 0" class="text-center py-16 text-gray-500 dark:text-slate-400">
                No break-the-glass events found.
            </div>
            <div v-else class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">User</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Participant</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Justification</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Granted</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Expires</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Status</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr
                            v-for="event in filtered"
                            :key="event.id"
                            :class="isOverdue(event)
                                ? 'bg-amber-50 dark:bg-amber-900/10'
                                : 'hover:bg-gray-50 dark:hover:bg-slate-700/50'"
                        >
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-slate-100">{{ event.user?.name ?? '-' }}</div>
                                <div class="text-xs text-gray-500 dark:text-slate-400 capitalize">
                                    {{ event.user?.department?.replace('_', ' ') ?? '' }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <a v-if="event.participant"
                                    :href="`/participants/${event.participant.id}`"
                                    class="font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                    {{ event.participant.name }}
                                </a>
                                <span v-else class="text-gray-400 dark:text-slate-500">-</span>
                                <div class="text-xs text-gray-500 dark:text-slate-400">{{ event.participant?.mrn }}</div>
                            </td>
                            <td class="px-4 py-3 max-w-xs">
                                <p class="text-gray-700 dark:text-slate-300 line-clamp-2">{{ event.justification }}</p>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-slate-400">
                                {{ formatDate(event.access_granted_at) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span :class="event.is_active ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-slate-500'"
                                    class="text-sm">
                                    {{ formatDate(event.access_expires_at) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="event.is_acknowledged"
                                    class="inline-flex items-center gap-1 text-green-600 dark:text-green-400 text-xs">
                                    <CheckCircleIcon class="w-4 h-4" />
                                    Reviewed
                                </span>
                                <span v-else-if="isOverdue(event)"
                                    class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400 text-xs font-medium">
                                    <ExclamationTriangleIcon class="w-4 h-4" />
                                    Overdue
                                </span>
                                <span v-else
                                    class="inline-flex items-center gap-1 text-gray-500 dark:text-slate-400 text-xs">
                                    <ClockIcon class="w-4 h-4" />
                                    Pending
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <button
                                    v-if="!event.is_acknowledged"
                                    @click="handleAcknowledge(event.id)"
                                    :disabled="acknowledging === event.id"
                                    class="px-3 py-1 text-xs rounded-lg bg-blue-600 hover:bg-blue-700 text-white disabled:opacity-50 transition-colors"
                                    :aria-label="`Acknowledge break-glass event for ${event.participant?.name ?? 'participant'}`"
                                >
                                    {{ acknowledging === event.id ? 'Saving...' : 'Acknowledge' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
