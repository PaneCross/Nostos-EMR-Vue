<script setup lang="ts">
// ─── SocialWorkDashboard.vue ──────────────────────────────────────────────────
// Social Work department live dashboard. Used by PACE social workers for the
// day's schedule, psychosocial alerts, open SDRs (Significant Decline Reports),
// and incidents requiring SW follow-up (e.g. APS reports, caregiver issues).
// Endpoints:
//   GET /dashboards/social-work/schedule   → { appointments[] }
//   GET /dashboards/social-work/alerts     → { alerts[], unacknowledged_count }
//   GET /dashboards/social-work/sdrs       → { sdrs[], overdue_count, open_count }
//   GET /dashboards/social-work/incidents  → { incidents[], open_count }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const appointments = ref<any[]>([])
const alerts = ref<any[]>([])
const sdrs = ref<any[]>([])
const incidents = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/social-work/schedule'),
        axios.get('/dashboards/social-work/alerts'),
        axios.get('/dashboards/social-work/sdrs'),
        axios.get('/dashboards/social-work/incidents'),
    ]).then(([r1, r2, r3, r4]) => {
        appointments.value = r1.data.appointments ?? []
        alerts.value = r2.data.alerts ?? []
        sdrs.value = r3.data.sdrs ?? []
        incidents.value = r4.data.incidents ?? []
    }).finally(() => loading.value = false)
})

const scheduleItems = computed<ActionItem[]>(() =>
    appointments.value.map(a => ({
        label: `${a.participant?.name ?? '-'} - ${a.type_label ?? '-'}`,
        sublabel: a.scheduled_start ?? undefined,
        badge: a.status === 'confirmed' ? 'Confirmed'
            : a.status === 'scheduled' ? 'Scheduled'
            : (a.status ?? '-'),
        badgeColor: a.status === 'confirmed'
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/schedule'),
    }))
)

const alertItems = computed<ActionItem[]>(() =>
    alerts.value.map(a => ({
        label: `${a.participant?.name ?? 'System'} - ${a.type_label ?? '-'}`,
        sublabel: a.created_at ?? undefined,
        badge: a.severity ?? '-',
        badgeColor: a.severity === 'critical'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : a.severity === 'warning'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/participants'),
    }))
)

const sdrItems = computed<ActionItem[]>(() =>
    sdrs.value.map(s => ({
        label: `${s.participant?.name ?? '-'} - ${s.type_label ?? '-'}`,
        sublabel: `Priority: ${s.priority ?? '-'}`,
        badge: s.is_overdue ? 'Overdue' : `${s.hours_remaining}h left`,
        badgeColor: s.is_overdue
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: s.href ?? (s.participant?.id ? `/participants/${s.participant.id}` : '/sdrs'),
    }))
)

const incidentItems = computed<ActionItem[]>(() =>
    incidents.value.map(i => ({
        label: `${i.participant?.name ?? 'No participant'} - ${i.type_label ?? '-'}`,
        sublabel: i.occurred_at ?? undefined,
        badge: i.rca_required ? 'RCA Required' : (i.status_label ?? i.status ?? '-'),
        badgeColor: i.rca_required
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: i.href ?? (i.participant?.id ? `/participants/${i.participant.id}` : '/qa/dashboard'),
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">
        <ActionWidget
            title="Today's Schedule"
            description="Social work and home visit appointments scheduled today."
            :items="scheduleItems"
            emptyMessage="No social work appointments today."
            viewAllHref="/schedule"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="Alerts requiring social work attention, including HL7 ADT events."
            :items="alertItems"
            emptyMessage="No active alerts."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Overdue SDRs"
            description="SDRs assigned to social work past their 72-hour deadline."
            :items="sdrItems"
            emptyMessage="No open SDRs."
            viewAllHref="/sdrs"
            :loading="loading"
        />

        <ActionWidget
            title="Open Incidents"
            description="Recent open incidents involving participants assigned to social work."
            :items="incidentItems"
            emptyMessage="No open incidents."
            viewAllHref="/qa/dashboard"
            :loading="loading"
        />
    </div>
</template>
