<script setup lang="ts">
// ─── BehavioralHealthDashboard.vue ───────────────────────────────────────────
// Behavioral Health department live dashboard.
// Endpoints:
//   GET /dashboards/behavioral-health/caseload     → { participants[] }
//   GET /dashboards/behavioral-health/alerts       → { alerts[] }
//   GET /dashboards/behavioral-health/docs         → { unsigned_notes[] }
//   GET /dashboards/behavioral-health/assessments  → { overdue_assessments[] }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const participants = ref<any[]>([])
const alerts = ref<any[]>([])
const unsignedNotes = ref<any[]>([])
const overdueAssessments = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/behavioral-health/caseload'),
        axios.get('/dashboards/behavioral-health/alerts'),
        axios.get('/dashboards/behavioral-health/docs'),
        axios.get('/dashboards/behavioral-health/assessments'),
    ]).then(([r1, r2, r3, r4]) => {
        participants.value = r1.data.participants ?? r1.data ?? []
        alerts.value = r2.data.alerts ?? r2.data ?? []
        unsignedNotes.value = r3.data.unsigned_notes ?? []
        overdueAssessments.value = r4.data.overdue_assessments ?? []
    }).finally(() => loading.value = false)
})

const caseloadItems = computed<ActionItem[]>(() =>
    participants.value.map(p => ({
        label: p.name ?? '-',
        sublabel: [`MRN: ${p.mrn ?? '-'}`, p.site].filter(Boolean).join(' | ') || undefined,
        badge: p.enrollment_status === 'enrolled' ? 'Enrolled' : (p.enrollment_status ?? '-'),
        badgeColor: p.enrollment_status === 'enrolled'
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
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

const assessmentItems = computed<ActionItem[]>(() =>
    overdueAssessments.value.map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label ?? '-'}`,
        sublabel: a.next_due_date ? `Due ${a.next_due_date}` : undefined,
        badge: `${a.days_overdue ?? 0}d overdue`,
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Active Caseload"
            description="Participants currently assigned to Behavioral Health."
            :items="caseloadItems"
            emptyMessage="No participants in active caseload."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="Alerts requiring behavioral health attention."
            :items="alertItems"
            emptyMessage="No active alerts."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Unsigned Notes"
            description="Behavioral health notes pending provider signature."
            :items="noteItems"
            emptyMessage="No unsigned notes."
            viewAllHref="/clinical/notes"
            :loading="loading"
        />

        <ActionWidget
            title="Overdue Assessments"
            description="Assessments past their due date."
            :items="assessmentItems"
            emptyMessage="No overdue assessments."
            viewAllHref="/clinical/assessments"
            :loading="loading"
        />
    </div>
</template>
