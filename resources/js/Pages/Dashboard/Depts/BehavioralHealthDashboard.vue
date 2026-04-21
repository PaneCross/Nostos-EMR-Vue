<script setup lang="ts">
// ─── BehavioralHealthDashboard.vue ───────────────────────────────────────────
// Behavioral Health department live dashboard.
// Endpoints:
//   GET /dashboards/behavioral-health/schedule     → { appointments[] }
//   GET /dashboards/behavioral-health/assessments  → { overdue[], due_soon[], overdue_count, due_soon_count }
//   GET /dashboards/behavioral-health/sdrs         → { sdrs[], overdue_count, open_count }
//   GET /dashboards/behavioral-health/goals        → { goals[] }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const appointments = ref<any[]>([])
const overdueAssessments = ref<any[]>([])
const dueSoonAssessments = ref<any[]>([])
const sdrs = ref<any[]>([])
const goals = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/behavioral-health/schedule'),
        axios.get('/dashboards/behavioral-health/assessments'),
        axios.get('/dashboards/behavioral-health/sdrs'),
        axios.get('/dashboards/behavioral-health/goals'),
    ]).then(([r1, r2, r3, r4]) => {
        appointments.value = r1.data.appointments ?? []
        overdueAssessments.value = r2.data.overdue ?? []
        dueSoonAssessments.value = r2.data.due_soon ?? []
        sdrs.value = r3.data.sdrs ?? []
        goals.value = r4.data.goals ?? []
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

const assessmentItems = computed<ActionItem[]>(() => [
    ...overdueAssessments.value.map((a: any) => ({
        label: `${a.participant?.name ?? '-'} - ${a.type_label ?? '-'}`,
        sublabel: a.next_due_date ? `Due ${a.next_due_date}` : undefined,
        badge: a.days_overdue != null ? `${a.days_overdue}d overdue` : 'Overdue',
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' as const,
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/clinical/assessments'),
    })),
    ...dueSoonAssessments.value.map((a: any) => ({
        label: `${a.participant?.name ?? '-'} - ${a.type_label ?? '-'}`,
        sublabel: undefined,
        badge: a.next_due_date ? `Due ${a.next_due_date}` : 'Due soon',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' as const,
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/clinical/assessments'),
    })),
])

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

const goalItems = computed<ActionItem[]>(() =>
    goals.value.map(g => ({
        label: `${g.participant?.name ?? '-'} - BH Goal`,
        sublabel: g.goal_description ?? undefined,
        badge: g.target_date ? `Due ${g.target_date}` : undefined,
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: g.href ?? (g.participant?.id ? `/participants/${g.participant.id}` : '/clinical/care-plans'),
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">
        <ActionWidget
            title="Today's Sessions"
            description="Behavioral health and counseling sessions scheduled today."
            :items="scheduleItems"
            emptyMessage="No BH sessions today."
            viewAllHref="/schedule"
            :loading="loading"
        />

        <ActionWidget
            title="Overdue Assessments"
            description="PHQ-9, GAD-7, and MMSE assessments that are past due. Required per 42 CFR 460.68."
            :items="assessmentItems"
            emptyMessage="No overdue or upcoming assessments."
            viewAllHref="/clinical/assessments"
            :loading="loading"
        />

        <ActionWidget
            title="Overdue SDRs"
            description="SDRs assigned to behavioral health past their 72-hour deadline."
            :items="sdrItems"
            emptyMessage="No open SDRs."
            viewAllHref="/sdrs"
            :loading="loading"
        />

        <ActionWidget
            title="Goals Due"
            description="Behavioral health care plan goals with target dates within 14 days or past due."
            :items="goalItems"
            emptyMessage="No active BH goals due soon."
            viewAllHref="/clinical/care-plans"
            :loading="loading"
        />
    </div>
</template>
