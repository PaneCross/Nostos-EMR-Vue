<script setup lang="ts">
// ─── ActivitiesDashboard.vue ──────────────────────────────────────────────────
// Activities department live dashboard.
// Endpoints:
//   GET /dashboards/activities/schedule    → { appointments[] }
//   GET /dashboards/activities/attendance  → { attendance_records[] }
//   GET /dashboards/activities/alerts      → { alerts[] }
//   GET /dashboards/activities/docs        → { unsigned_notes[] }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const appointments = ref<any[]>([])
const attendanceRecords = ref<any[]>([])
const alerts = ref<any[]>([])
const unsignedNotes = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/activities/schedule'),
        axios.get('/dashboards/activities/attendance'),
        axios.get('/dashboards/activities/alerts'),
        axios.get('/dashboards/activities/docs'),
    ]).then(([r1, r2, r3, r4]) => {
        appointments.value = r1.data.appointments ?? r1.data ?? []
        attendanceRecords.value = r2.data.attendance_records ?? []
        alerts.value = r3.data.alerts ?? r3.data ?? []
        unsignedNotes.value = r4.data.unsigned_notes ?? []
    }).finally(() => loading.value = false)
})

const scheduleItems = computed<ActionItem[]>(() =>
    appointments.value.map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label ?? '-'}`,
        sublabel: a.scheduled_start ?? undefined,
        badge: a.status === 'confirmed' ? 'Confirmed'
            : a.status === 'scheduled' ? 'Scheduled'
            : (a.status ?? '-'),
        badgeColor: a.status === 'confirmed'
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    }))
)

const attendanceItems = computed<ActionItem[]>(() =>
    attendanceRecords.value.map(r => ({
        label: r.participant?.name ?? '-',
        sublabel: [r.attended_date, r.reason].filter(Boolean).join(' | ') || undefined,
        badge: r.status === 'present' ? 'Present'
            : r.status === 'absent' ? 'Absent'
            : r.status === 'excused' ? 'Excused'
            : (r.status ?? '-'),
        badgeColor: r.status === 'present'
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : r.status === 'absent'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    }))
)

const alertItems = computed<ActionItem[]>(() =>
    alerts.value.map(a => ({
        label: `${a.participant?.name ?? 'System'} — ${a.type_label ?? '-'}`,
        sublabel: a.created_at ?? undefined,
        badge: a.severity ?? '-',
        badgeColor: a.severity === 'critical'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : a.severity === 'warning'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    }))
)

const noteItems = computed<ActionItem[]>(() =>
    unsignedNotes.value.map(n => ({
        label: `${n.participant?.name ?? '-'} — ${n.type_label ?? '-'}`,
        sublabel: n.visit_date ?? n.created_at ?? undefined,
        badge: n.author ? undefined : 'Unassigned',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Today's Activity Schedule"
            description="Activity appointments scheduled for today."
            :items="scheduleItems"
            emptyMessage="No activity appointments today."
            viewAllHref="/schedule"
            :loading="loading"
        />

        <ActionWidget
            title="Recent Attendance Records"
            description="Day center attendance recorded recently."
            :items="attendanceItems"
            emptyMessage="No recent attendance records."
            viewAllHref="/scheduling/day-center"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="Alerts requiring activities attention."
            :items="alertItems"
            emptyMessage="No active alerts."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Unsigned Notes"
            description="Activity notes pending provider signature."
            :items="noteItems"
            emptyMessage="No unsigned notes."
            viewAllHref="/clinical/notes"
            :loading="loading"
        />
    </div>
</template>
