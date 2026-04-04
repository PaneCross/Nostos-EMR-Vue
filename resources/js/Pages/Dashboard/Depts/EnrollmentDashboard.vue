<script setup lang="ts">
// ─── EnrollmentDashboard.vue ──────────────────────────────────────────────────
// Enrollment department live dashboard.
// Endpoints:
//   GET /dashboards/enrollment/pipeline             → { referrals[] }
//   GET /dashboards/enrollment/alerts               → { alerts[] }
//   GET /dashboards/enrollment/new-referrals        → { referrals[] } (as new_referrals)
//   GET /dashboards/enrollment/pending-assessments  → { assessments[] }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const referrals = ref<any[]>([])
const alerts = ref<any[]>([])
const newReferrals = ref<any[]>([])
const pendingAssessments = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/enrollment/pipeline'),
        axios.get('/dashboards/enrollment/alerts'),
        axios.get('/dashboards/enrollment/new-referrals'),
        axios.get('/dashboards/enrollment/pending-assessments'),
    ]).then(([r1, r2, r3, r4]) => {
        referrals.value = r1.data.referrals ?? []
        alerts.value = r2.data.alerts ?? r2.data ?? []
        newReferrals.value = r3.data.referrals ?? []
        pendingAssessments.value = r4.data.assessments ?? []
    }).finally(() => loading.value = false)
})

function enrollmentBadgeColor(status: string): string {
    if (status === 'enrolled') return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
    if (status === 'pending_enrollment') return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
    if (status === 'intake_in_progress') return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
    return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
}

const pipelineItems = computed<ActionItem[]>(() =>
    referrals.value.map(r => ({
        label: r.participant_name ?? r.filed_by_name ?? '-',
        sublabel: [r.source, r.created_at].filter(Boolean).join(' | ') || undefined,
        badge: r.status ?? '-',
        badgeColor: enrollmentBadgeColor(r.status ?? ''),
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

const newReferralItems = computed<ActionItem[]>(() =>
    newReferrals.value.map(r => ({
        label: r.participant_name ?? r.filed_by_name ?? '-',
        sublabel: [r.source, r.created_at].filter(Boolean).join(' | ') || undefined,
        badge: 'New',
        badgeColor: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    }))
)

const assessmentItems = computed<ActionItem[]>(() =>
    pendingAssessments.value.map(a => {
        const isOverdue = a.days_overdue != null && a.days_overdue > 0
        return {
            label: `${a.participant?.name ?? '-'} — ${a.type_label ?? '-'}`,
            sublabel: a.next_due_date ?? undefined,
            badge: isOverdue ? `${a.days_overdue}d overdue` : (a.next_due_date ? `Due ${a.next_due_date}` : '-'),
            badgeColor: isOverdue
                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        }
    })
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Enrollment Pipeline"
            description="Referrals and participants moving through the enrollment process."
            :items="pipelineItems"
            emptyMessage="No referrals in the pipeline."
            viewAllHref="/enrollment"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="Alerts requiring enrollment attention."
            :items="alertItems"
            emptyMessage="No active alerts."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="New Referrals"
            description="Referrals received recently."
            :items="newReferralItems"
            emptyMessage="No new referrals."
            viewAllHref="/enrollment"
            :loading="loading"
        />

        <ActionWidget
            title="Pending Intake Assessments"
            description="Intake assessments awaiting completion."
            :items="assessmentItems"
            emptyMessage="No pending intake assessments."
            viewAllHref="/clinical/assessments"
            :loading="loading"
        />
    </div>
</template>
