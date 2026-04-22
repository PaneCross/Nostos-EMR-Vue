<script setup lang="ts">
// ─── Appointments/Show.vue ───────────────────────────────────────────────────
// Phase 14.2 (MVP roadmap). Standalone appointment detail page. Reachable
// from dashboard widgets, global search, and the scheduler. All state-change
// actions (confirm / complete / cancel / no-show) call the existing
// participant-scoped endpoints so validation + conflict detection stays in
// one place.
// ─────────────────────────────────────────────────────────────────────────────
import { ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

interface PersonRef { id: number; first_name: string; last_name: string }
interface Appointment {
    id: number
    participant_id: number
    participant: { id: number; first_name: string; last_name: string; mrn: string; dob: string }
    provider: PersonRef | null
    location: { id: number; name: string } | null
    createdBy: PersonRef | null
    site: { id: number; name: string } | null
    appointment_type: string
    status: string
    scheduled_start: string
    scheduled_end: string
    transport_required: boolean
    notes: string | null
    cancellation_reason: string | null
}

const props = defineProps<{
    appointment: Appointment
    canEdit: boolean
}>()

const appointment = ref<Appointment>({ ...props.appointment })
const busy = ref(false)
const error = ref('')

const STATUS_CLASS: Record<string, string> = {
    scheduled: 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300',
    confirmed: 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300',
    completed: 'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300',
    cancelled: 'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    no_show:   'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
}

function fmt(ts: string | null): string {
    if (!ts) return '—'
    const d = new Date(ts)
    return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

function typeLabel(t: string): string {
    return t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

async function transition(action: 'confirm' | 'complete' | 'cancel' | 'no-show') {
    if (! props.canEdit) return
    const confirmMsg: Record<string, string> = {
        confirm: 'Mark this appointment as confirmed?',
        complete: 'Mark this appointment as completed?',
        cancel: 'Cancel this appointment?',
        'no-show': 'Mark participant as no-show?',
    }
    if (!window.confirm(confirmMsg[action])) return
    busy.value = true
    error.value = ''
    try {
        let payload: any = {}
        if (action === 'cancel') {
            const reason = window.prompt('Cancellation reason:') ?? ''
            if (!reason) { busy.value = false; return }
            payload = { cancellation_reason: reason }
        }
        const r = await axios.patch(
            `/participants/${appointment.value.participant_id}/appointments/${appointment.value.id}/${action}`,
            payload
        )
        appointment.value = { ...appointment.value, ...r.data }
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? 'Action failed.'
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <AppShell :title="`Appointment · ${fmt(appointment.scheduled_start)}`">
        <Head :title="`Appointment #${appointment.id}`" />

        <div class="max-w-4xl mx-auto p-6 space-y-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">
                        {{ typeLabel(appointment.appointment_type) }}
                    </h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Appointment #{{ appointment.id }} &middot;
                        <Link :href="`/participants/${appointment.participant_id}`" class="text-blue-600 dark:text-blue-400 hover:underline">
                            {{ appointment.participant?.first_name }} {{ appointment.participant?.last_name }} ({{ appointment.participant?.mrn }})
                        </Link>
                    </p>
                </div>
                <span :class="['inline-flex px-3 py-1 rounded-full text-xs font-medium', STATUS_CLASS[appointment.status] ?? 'bg-slate-100']">
                    {{ typeLabel(appointment.status) }}
                </span>
            </div>

            <div v-if="error" class="bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-lg px-4 py-2 text-sm">
                {{ error }}
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-3">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Schedule</h2>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-slate-500 dark:text-slate-400">Start</dt><dd class="text-slate-900 dark:text-slate-100">{{ fmt(appointment.scheduled_start) }}</dd></div>
                    <div><dt class="text-slate-500 dark:text-slate-400">End</dt><dd class="text-slate-900 dark:text-slate-100">{{ fmt(appointment.scheduled_end) }}</dd></div>
                    <div><dt class="text-slate-500 dark:text-slate-400">Location</dt><dd class="text-slate-900 dark:text-slate-100">{{ appointment.location?.name ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500 dark:text-slate-400">Provider</dt><dd class="text-slate-900 dark:text-slate-100">
                        <template v-if="appointment.provider">{{ appointment.provider.first_name }} {{ appointment.provider.last_name }}</template>
                        <template v-else>—</template>
                    </dd></div>
                    <div><dt class="text-slate-500 dark:text-slate-400">Site</dt><dd class="text-slate-900 dark:text-slate-100">{{ appointment.site?.name ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500 dark:text-slate-400">Transport required</dt><dd class="text-slate-900 dark:text-slate-100">{{ appointment.transport_required ? 'Yes' : 'No' }}</dd></div>
                </dl>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 space-y-3">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Notes</h2>
                <p v-if="appointment.notes" class="text-sm text-slate-800 dark:text-slate-200 whitespace-pre-wrap">{{ appointment.notes }}</p>
                <p v-else class="text-sm italic text-slate-400">No notes.</p>
                <template v-if="appointment.cancellation_reason">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100 mt-4">Cancellation reason</h2>
                    <p class="text-sm text-slate-800 dark:text-slate-200">{{ appointment.cancellation_reason }}</p>
                </template>
            </div>

            <div v-if="canEdit && ['scheduled','confirmed'].includes(appointment.status)" class="flex flex-wrap gap-2">
                <button v-if="appointment.status === 'scheduled'"
                    @click="transition('confirm')" :disabled="busy"
                    class="px-3 py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm disabled:opacity-50">
                    Confirm
                </button>
                <button @click="transition('complete')" :disabled="busy"
                    class="px-3 py-1.5 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 text-sm disabled:opacity-50">
                    Mark Completed
                </button>
                <button @click="transition('cancel')" :disabled="busy"
                    class="px-3 py-1.5 bg-amber-600 text-white rounded-md hover:bg-amber-700 text-sm disabled:opacity-50">
                    Cancel
                </button>
                <button @click="transition('no-show')" :disabled="busy"
                    class="px-3 py-1.5 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm disabled:opacity-50">
                    No-show
                </button>
            </div>
        </div>
    </AppShell>
</template>
