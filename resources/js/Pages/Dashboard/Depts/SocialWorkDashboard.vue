<script setup lang="ts">
// ─── SocialWorkDashboard.vue ──────────────────────────────────────────────────
// Social Work department live dashboard.
// Endpoints:
//   GET /dashboards/social-work/caseload     → { participants[] }
//   GET /dashboards/social-work/alerts       → { alerts[] }
//   GET /dashboards/social-work/sdrs         → { sdrs[] }
//   GET /dashboards/social-work/assessments  → { overdue_assessments[] }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const participants = ref<any[]>([])
const alerts = ref<any[]>([])
const sdrs = ref<any[]>([])
const overdueAssessments = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/social-work/caseload'),
        axios.get('/dashboards/social-work/alerts'),
        axios.get('/dashboards/social-work/sdrs'),
        axios.get('/dashboards/social-work/assessments'),
    ])
        .then(([r1, r2, r3, r4]) => {
            participants.value = r1.data.participants ?? r1.data ?? []
            alerts.value = r2.data.alerts ?? r2.data ?? []
            sdrs.value = r3.data.sdrs ?? []
            overdueAssessments.value = r4.data.overdue_assessments ?? []
        })
        .finally(() => (loading.value = false))
})

const caseloadItems = computed<ActionItem[]>(() =>
    participants.value.map((p) => ({
        label: p.name ?? '-',
        sublabel: [`MRN: ${p.mrn ?? '-'}`, p.site].filter(Boolean).join(' | ') || undefined,
        badge: p.enrollment_status === 'enrolled' ? 'Enrolled' : (p.enrollment_status ?? '-'),
        badgeColor:
            p.enrollment_status === 'enrolled'
                ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
                : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
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

const sdrItems = computed<ActionItem[]>(() =>
    sdrs.value.map((s) => {
        const isOverdue = s.hours_overdue != null && s.hours_overdue > 0
        return {
            label: `${s.participant?.name ?? '-'} — ${s.type_label ?? '-'}`,
            sublabel: s.due_at ?? undefined,
            badge: isOverdue ? `${s.hours_overdue}h overdue` : (s.status ?? '-'),
            badgeColor: isOverdue
                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        }
    }),
)

const assessmentItems = computed<ActionItem[]>(() =>
    overdueAssessments.value.map((a) => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label ?? '-'}`,
        sublabel: a.next_due_date ? `Due ${a.next_due_date}` : undefined,
        badge: `${a.days_overdue ?? 0}d overdue`,
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    })),
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Active Caseload"
            description="Participants currently assigned to Social Work."
            :items="caseloadItems"
            empty-message="No participants in active caseload."
            view-all-href="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="Alerts requiring social work attention."
            :items="alertItems"
            empty-message="No active alerts."
            view-all-href="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Open SDRs"
            description="Significant departure reports requiring action."
            :items="sdrItems"
            empty-message="No open SDRs."
            view-all-href="/sdrs"
            :loading="loading"
        />

        <ActionWidget
            title="Overdue Assessments"
            description="Assessments past their due date."
            :items="assessmentItems"
            empty-message="No overdue assessments."
            view-all-href="/clinical/assessments"
            :loading="loading"
        />
    </div>
</template>
