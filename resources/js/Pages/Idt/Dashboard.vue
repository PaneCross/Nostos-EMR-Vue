<!--
  IDT Dashboard — Interdisciplinary Team meeting overview page.

  Shows today's meetings (in-progress and scheduled), upcoming meetings, and
  recently completed meetings. Provides a Schedule Meeting modal that POSTs to
  /idt/meetings and refreshes the two meeting lists on save.

  Route:  GET /idt/meetings -> Inertia::render('Idt/Dashboard')
  Props:  todayMeetings, upcomingMeetings, recentMeetings (arrays of IdtMeeting)
-->
<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { PlusIcon, CalendarIcon, UsersIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'

// ── Types ─────────────────────────────────────────────────────────────────────

interface Facilitator {
    id: number
    first_name: string
    last_name: string
}

interface ParticipantReview {
    id: number
    queue_order: number
    reviewed_at: string | null
    participant: {
        id: number
        mrn: string
        first_name: string
        last_name: string
    }
}

interface IdtMeeting {
    id: number
    meeting_date: string
    meeting_time: string | null
    meeting_type: string
    status: 'scheduled' | 'in_progress' | 'completed'
    facilitator: Facilitator | null
    participant_reviews?: ParticipantReview[]
}

const props = defineProps<{
    todayMeetings: IdtMeeting[]
    upcomingMeetings: IdtMeeting[]
    recentMeetings: IdtMeeting[]
}>()

// ── Constants ─────────────────────────────────────────────────────────────────

const TYPE_LABELS: Record<string, string> = {
    daily: 'Daily Huddle',
    weekly: 'Weekly IDT Review',
    care_plan_review: 'Care Plan Review',
    urgent: 'Urgent IDT',
}

const STATUS_BADGE: Record<string, string> = {
    scheduled: 'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 ring-blue-600/20',
    in_progress:
        'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 ring-amber-600/20',
    completed:
        'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 ring-green-600/20',
}

// ── Schedule modal state ───────────────────────────────────────────────────────

const showSchedule = ref(false)
const today = new Date().toISOString().split('T')[0]
const scheduleForm = ref({ meeting_date: today, meeting_time: '10:00', meeting_type: 'weekly' })
const scheduleSaving = ref(false)
const scheduleError = ref('')

async function saveSchedule() {
    scheduleSaving.value = true
    scheduleError.value = ''
    try {
        await axios.post('/idt/meetings', scheduleForm.value)
        showSchedule.value = false
        router.reload({ only: ['todayMeetings', 'upcomingMeetings'] })
    } catch (e: any) {
        scheduleError.value = e.response?.data?.message ?? 'Failed to schedule meeting.'
    } finally {
        scheduleSaving.value = false
    }
}

function closeSchedule() {
    showSchedule.value = false
    scheduleError.value = ''
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmt12h(time: string | null): string {
    if (!time) return ''
    const [h, m] = time.split(':').map(Number)
    const ampm = h >= 12 ? 'PM' : 'AM'
    const hour = h % 12 || 12
    return `${hour}:${String(m).padStart(2, '0')} ${ampm}`
}

function reviewedCount(meeting: IdtMeeting): number {
    return meeting.participant_reviews?.filter((r) => r.reviewed_at).length ?? 0
}

function totalReviews(meeting: IdtMeeting): number {
    return meeting.participant_reviews?.length ?? 0
}

async function startMeeting(id: number) {
    try {
        await axios.post(`/idt/meetings/${id}/start`)
        router.reload({ only: ['todayMeetings'] })
    } catch {
        // Non-blocking
    }
}
</script>

<template>
    <AppShell>
        <Head title="IDT Dashboard" />

        <div class="px-6 py-6 space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-slate-100">
                        IDT Dashboard
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                        Interdisciplinary Team meetings and participant review queue
                    </p>
                </div>
                <button
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-sm"
                    @click="showSchedule = true"
                >
                    <PlusIcon class="w-4 h-4" aria-hidden="true" />
                    Schedule Meeting
                </button>
            </div>

            <!-- Today's Meetings -->
            <section>
                <h2
                    class="text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider mb-3"
                >
                    Today's Meetings
                </h2>

                <div
                    v-if="props.todayMeetings.length === 0"
                    class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-8 text-center"
                >
                    <CalendarIcon
                        class="w-8 h-8 mx-auto text-gray-300 dark:text-slate-600 mb-2"
                        aria-hidden="true"
                    />
                    <p class="text-sm text-gray-500 dark:text-slate-400">
                        No meetings scheduled for today.
                    </p>
                    <button
                        class="mt-2 text-sm text-blue-600 dark:text-blue-400 hover:underline"
                        @click="showSchedule = true"
                    >
                        Schedule one now
                    </button>
                </div>

                <div v-else class="space-y-3">
                    <div
                        v-for="meeting in props.todayMeetings"
                        :key="meeting.id"
                        :class="[
                            'rounded-xl border p-4',
                            meeting.status === 'in_progress'
                                ? 'border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40'
                                : 'border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800',
                        ]"
                    >
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="font-semibold text-sm text-gray-800 dark:text-slate-200"
                                    >
                                        {{
                                            TYPE_LABELS[meeting.meeting_type] ??
                                            meeting.meeting_type
                                        }}
                                    </span>
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset',
                                            STATUS_BADGE[meeting.status] ?? '',
                                        ]"
                                    >
                                        {{ meeting.status.replace('_', ' ').toUpperCase() }}
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                                    {{ meeting.meeting_date }}
                                    <template v-if="meeting.meeting_time">
                                        · {{ fmt12h(meeting.meeting_time) }}</template
                                    >
                                    <template v-if="meeting.facilitator">
                                        · {{ meeting.facilitator.first_name }}
                                        {{ meeting.facilitator.last_name }}
                                    </template>
                                </p>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <button
                                    v-if="meeting.status === 'scheduled'"
                                    class="px-3 py-1.5 text-xs font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                                    @click="startMeeting(meeting.id)"
                                >
                                    Start
                                </button>
                                <a
                                    :href="`/idt/meetings/${meeting.id}`"
                                    class="px-3 py-1.5 text-xs font-medium bg-amber-600 text-white rounded-lg hover:bg-amber-700"
                                >
                                    {{ meeting.status === 'in_progress' ? 'Resume' : 'View' }}
                                </a>
                            </div>
                        </div>

                        <!-- Review progress bar -->
                        <div v-if="totalReviews(meeting) > 0" class="mt-2">
                            <div
                                class="flex items-center gap-2 text-xs text-gray-600 dark:text-slate-400 mb-1"
                            >
                                <UsersIcon class="w-3.5 h-3.5" aria-hidden="true" />
                                {{ reviewedCount(meeting) }} /
                                {{ totalReviews(meeting) }} participants reviewed
                            </div>
                            <div
                                class="h-1.5 rounded-full bg-gray-200 dark:bg-slate-700 overflow-hidden"
                            >
                                <div
                                    class="h-full bg-green-500 rounded-full transition-all"
                                    :style="{
                                        width: `${totalReviews(meeting) > 0 ? (reviewedCount(meeting) / totalReviews(meeting)) * 100 : 0}%`,
                                    }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Two-column: Upcoming + Recent -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Upcoming -->
                <section>
                    <h2
                        class="text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider mb-3"
                    >
                        Upcoming Meetings
                    </h2>
                    <div
                        v-if="props.upcomingMeetings.length === 0"
                        class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-6 text-center text-sm text-gray-500 dark:text-slate-400"
                    >
                        No upcoming meetings scheduled.
                    </div>
                    <div v-else class="space-y-2">
                        <div
                            v-for="meeting in props.upcomingMeetings"
                            :key="meeting.id"
                            class="flex items-center justify-between rounded-lg border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-3"
                        >
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-slate-200">
                                    {{ TYPE_LABELS[meeting.meeting_type] ?? meeting.meeting_type }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-slate-400">
                                    {{ meeting.meeting_date
                                    }}<template v-if="meeting.meeting_time">
                                        · {{ fmt12h(meeting.meeting_time) }}</template
                                    >
                                </p>
                            </div>
                            <a
                                :href="`/idt/meetings/${meeting.id}`"
                                class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium"
                            >
                                View
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Recent -->
                <section>
                    <h2
                        class="text-sm font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wider mb-3"
                    >
                        Recent Meetings
                    </h2>
                    <div
                        v-if="props.recentMeetings.length === 0"
                        class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-6 text-center text-sm text-gray-500 dark:text-slate-400"
                    >
                        No completed meetings yet.
                    </div>
                    <div v-else class="space-y-2">
                        <div
                            v-for="meeting in props.recentMeetings"
                            :key="meeting.id"
                            class="flex items-center justify-between rounded-lg border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-3"
                        >
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-slate-200">
                                    {{ TYPE_LABELS[meeting.meeting_type] ?? meeting.meeting_type }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-slate-400">
                                    {{ meeting.meeting_date }}
                                    <template v-if="meeting.facilitator">
                                        · {{ meeting.facilitator.first_name }}
                                        {{ meeting.facilitator.last_name }}
                                    </template>
                                </p>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <CheckCircleIcon
                                    class="w-3.5 h-3.5 text-green-500"
                                    aria-hidden="true"
                                />
                                <a
                                    :href="`/idt/meetings/${meeting.id}`"
                                    class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium"
                                >
                                    Minutes
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- Schedule Meeting Modal -->
        <div
            v-if="showSchedule"
            class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
            @click.self="closeSchedule"
        >
            <div
                class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md"
                role="dialog"
                aria-modal="true"
            >
                <div
                    class="px-6 py-4 border-b border-gray-100 dark:border-slate-700 flex items-center justify-between"
                >
                    <h2 class="font-semibold text-gray-800 dark:text-slate-200">
                        Schedule IDT Meeting
                    </h2>
                    <button
                        class="text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300"
                        aria-label="Close"
                        @click="closeSchedule"
                    >
                        &#x2715;
                    </button>
                </div>

                <div class="px-6 py-5 space-y-4">
                    <p
                        v-if="scheduleError"
                        class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/60 rounded-lg px-3 py-2"
                    >
                        {{ scheduleError }}
                    </p>

                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Meeting Type</label
                        >
                        <select
                            v-model="scheduleForm.meeting_type"
                            class="block w-full rounded-lg border border-gray-300 dark:border-slate-600 text-sm py-2 px-3 dark:bg-slate-700"
                        >
                            <option
                                v-for="(label, value) in TYPE_LABELS"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                                >Date</label
                            >
                            <input
                                v-model="scheduleForm.meeting_date"
                                type="date"
                                :min="today"
                                class="block w-full rounded-lg border border-gray-300 dark:border-slate-600 text-sm py-2 px-3 dark:bg-slate-700"
                            />
                        </div>
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                                >Time</label
                            >
                            <input
                                v-model="scheduleForm.meeting_time"
                                type="time"
                                class="block w-full rounded-lg border border-gray-300 dark:border-slate-600 text-sm py-2 px-3 dark:bg-slate-700"
                            />
                        </div>
                    </div>
                </div>

                <div
                    class="px-6 py-4 border-t border-gray-100 dark:border-slate-700 flex justify-end gap-3"
                >
                    <button
                        class="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700 rounded-lg border border-gray-200 dark:border-slate-600"
                        @click="closeSchedule"
                    >
                        Cancel
                    </button>
                    <button
                        :disabled="scheduleSaving"
                        class="px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                        @click="saveSchedule"
                    >
                        {{ scheduleSaving ? 'Scheduling...' : 'Schedule Meeting' }}
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
