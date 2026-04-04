<script setup lang="ts">
// ─── QaComplianceDashboard.vue ────────────────────────────────────────────────
// QA / Compliance department live dashboard.
// Endpoints:
//   GET /dashboards/qa-compliance/incidents     → { incidents[] }
//   GET /dashboards/qa-compliance/overdue-docs  → { unsigned_notes[], overdue_assessments[] }
//   GET /dashboards/qa-compliance/grievances    → { grievances[] }
//   GET /dashboards/qa-compliance/alerts        → { alerts[] }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const incidents = ref<any[]>([])
const unsignedNotes = ref<any[]>([])
const overdueAssessments = ref<any[]>([])
const grievances = ref<any[]>([])
const alerts = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/qa-compliance/incidents'),
        axios.get('/dashboards/qa-compliance/overdue-docs'),
        axios.get('/dashboards/qa-compliance/grievances'),
        axios.get('/dashboards/qa-compliance/alerts'),
    ])
        .then(([r1, r2, r3, r4]) => {
            incidents.value = r1.data.incidents ?? []
            unsignedNotes.value = r2.data.unsigned_notes ?? []
            overdueAssessments.value = r2.data.overdue_assessments ?? []
            grievances.value = r3.data.grievances ?? []
            alerts.value = r4.data.alerts ?? r4.data ?? []
        })
        .finally(() => (loading.value = false))
})

const incidentItems = computed<ActionItem[]>(() =>
    incidents.value.map((i) => {
        const rcaPending = i.rca_required && !i.rca_completed
        let badge: string
        let badgeColor: string
        if (rcaPending) {
            badge = 'RCA Pending'
            badgeColor = 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
        } else if (i.status === 'open') {
            badge = 'Open'
            badgeColor = 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
        } else if (i.status === 'under_review') {
            badge = 'Under Review'
            badgeColor = 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
        } else {
            badge = i.status ?? '-'
            badgeColor = 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
        }
        return {
            label: `${i.participant?.name ?? 'System'} — ${i.incident_type ?? '-'}`,
            sublabel: [i.occurred_at, i.severity ?? '-'].filter(Boolean).join(' | ') || undefined,
            badge,
            badgeColor,
        }
    }),
)

const unsignedNoteItems = computed<ActionItem[]>(() =>
    unsignedNotes.value.map((n) => ({
        label: `${n.participant?.name ?? '-'} — ${n.type_label ?? '-'}`,
        sublabel: n.visit_date ?? n.created_at ?? undefined,
        badge: 'Unsigned',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    })),
)

const overdueAssessmentItems = computed<ActionItem[]>(() =>
    overdueAssessments.value.map((a) => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label ?? '-'}`,
        sublabel: a.next_due_date ? `Due ${a.next_due_date}` : undefined,
        badge: `${a.days_overdue ?? 0}d overdue`,
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    })),
)

const grievanceItems = computed<ActionItem[]>(() =>
    grievances.value.map((g) => ({
        label: g.participant?.name ?? g.filed_by_name ?? '-',
        sublabel: [g.category, g.filed_at].filter(Boolean).join(' | ') || undefined,
        badge: g.priority === 'urgent' ? 'Urgent' : 'Standard',
        badgeColor:
            g.priority === 'urgent'
                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    })),
)

const alertItems = computed<ActionItem[]>(() =>
    alerts.value.map((a) => ({
        label: `${a.participant?.name ?? 'System'} — ${a.type_label ?? '-'}`,
        sublabel: a.created_at ?? undefined,
        badge: a.severity ?? '-',
        badgeColor:
            a.severity === 'critical'
                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                : a.severity === 'warning'
                  ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
                  : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    })),
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Open Incidents"
            description="Incidents requiring QA review or RCA completion."
            :items="incidentItems"
            empty-message="No open incidents."
            view-all-href="/qa/dashboard"
            :loading="loading"
        />

        <div class="flex flex-col gap-4">
            <ActionWidget
                title="Unsigned Notes"
                description="Clinical notes pending provider signature."
                :items="unsignedNoteItems"
                empty-message="No unsigned notes."
                view-all-href="/clinical/notes"
                :loading="loading"
            />
            <ActionWidget
                title="Overdue Assessments"
                description="Assessments past their due date."
                :items="overdueAssessmentItems"
                empty-message="No overdue assessments."
                view-all-href="/clinical/assessments"
                :loading="loading"
            />
        </div>

        <ActionWidget
            title="Open Grievances"
            description="Participant grievances requiring resolution."
            :items="grievanceItems"
            empty-message="No open grievances."
            view-all-href="/qa/dashboard"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="Compliance and clinical alerts requiring attention."
            :items="alertItems"
            empty-message="No active alerts."
            view-all-href="/qa/dashboard"
            :loading="loading"
        />
    </div>
</template>
