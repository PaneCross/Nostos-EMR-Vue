<script setup lang="ts">
// ─── IdtDashboard.vue ─────────────────────────────────────────────────────────
// IDT (Interdisciplinary Team) department live dashboard.
// Endpoints:
//   GET /dashboards/idt/meetings              → { meetings[] }
//   GET /dashboards/idt/overdue-sdrs          → { departments[] } (each: { department, sdrs[] })
//   GET /dashboards/idt/care-plans            → { care_plans[] }
//   GET /dashboards/idt/alerts                → { alerts[] }
//   GET /dashboards/idt/idt-review-overdue    → { participants[] }
//   GET /dashboards/idt/significant-changes   → { events[] }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const meetings = ref<any[]>([])
const sdrDepartments = ref<any[]>([])
const carePlans = ref<any[]>([])
const alerts = ref<any[]>([])
const overdueParticipants = ref<any[]>([])
const significantEvents = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/idt/meetings'),
        axios.get('/dashboards/idt/overdue-sdrs'),
        axios.get('/dashboards/idt/care-plans'),
        axios.get('/dashboards/idt/alerts'),
        axios.get('/dashboards/idt/idt-review-overdue'),
        axios.get('/dashboards/idt/significant-changes'),
    ])
        .then(([r1, r2, r3, r4, r5, r6]) => {
            meetings.value = r1.data.meetings ?? []
            sdrDepartments.value = r2.data.departments ?? []
            carePlans.value = r3.data.care_plans ?? []
            alerts.value = r4.data.alerts ?? r4.data ?? []
            overdueParticipants.value = r5.data.participants ?? []
            significantEvents.value = r6.data.events ?? []
        })
        .finally(() => (loading.value = false))
})

const meetingItems = computed<ActionItem[]>(() =>
    meetings.value.map((m) => {
        const sublabelParts = [m.meeting_time, m.site, m.facilitator].filter(Boolean)
        return {
            label: m.type_label ?? '-',
            sublabel: sublabelParts.join(' | ') || undefined,
            badge: m.status === 'in_progress' ? 'In Progress' : 'Scheduled',
            badgeColor:
                m.status === 'in_progress'
                    ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
                    : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        }
    }),
)

const escalatedSdrItems = computed<ActionItem[]>(() => {
    const items: ActionItem[] = []
    sdrDepartments.value.forEach((dept) => {
        const sdrs: any[] = dept.sdrs ?? []
        sdrs.forEach((s) => {
            items.push({
                label: `${s.participant?.name ?? '-'} : ${s.type_label ?? '-'}`,
                sublabel: dept.department?.replace(/_/g, ' ') ?? undefined,
                badge: `${s.hours_overdue ?? 0}h overdue`,
                badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
            })
        })
    })
    return items
})

const carePlanItems = computed<ActionItem[]>(() =>
    carePlans.value.map((cp) => {
        const days = cp.days ?? 0
        const isOverdue = cp.is_overdue === true
        const absDays = Math.abs(days)
        return {
            label: cp.participant?.name ?? '-',
            sublabel: cp.status?.replace(/_/g, ' ') ?? undefined,
            badge: isOverdue ? `${absDays}d overdue` : `${days}d`,
            badgeColor: isOverdue
                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        }
    }),
)

const alertItems = computed<ActionItem[]>(() =>
    alerts.value.map((a) => ({
        label: a.title ?? '-',
        sublabel:
            [a.participant?.name ?? 'System', a.created_at].filter(Boolean).join(' | ') ||
            undefined,
        badge: a.severity ?? '-',
        badgeColor:
            a.severity === 'critical'
                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                : a.severity === 'warning'
                  ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
                  : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    })),
)

const idtReviewItems = computed<ActionItem[]>(() =>
    overdueParticipants.value.map((p) => {
        const sublabelParts = [
            `MRN: ${p.mrn ?? '-'}`,
            p.site,
            `Last: ${p.last_reviewed_at ?? 'Never'}`,
        ].filter(Boolean)
        return {
            label: p.name ?? '-',
            sublabel: sublabelParts.join(' | ') || undefined,
            badge: p.days_overdue != null ? `${p.days_overdue}d overdue` : 'No review on record',
            badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        }
    }),
)

const significantChangeItems = computed<ActionItem[]>(() =>
    significantEvents.value.map((e) => {
        const days = e.days ?? 0
        const absDays = Math.abs(days)
        const sublabelParts = [
            e.trigger_type_label,
            e.trigger_date ? `Trigger: ${e.trigger_date}` : null,
            e.site,
        ].filter(Boolean)
        let badge: string
        let badgeColor: string
        if (e.urgency === 'overdue') {
            badge = `${absDays}d overdue`
            badgeColor = 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
        } else if (e.urgency === 'soon') {
            badge = `${days}d remaining`
            badgeColor = 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
        } else {
            badge = `${days}d`
            badgeColor = 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
        }
        return {
            label: e.participant?.name ?? '-',
            sublabel: sublabelParts.join(' | ') || undefined,
            badge,
            badgeColor,
        }
    }),
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Today's IDT Meetings"
            description="Interdisciplinary team meetings scheduled today."
            :items="meetingItems"
            empty-message="No IDT meetings scheduled today."
            view-all-href="/idt/meetings"
            :loading="loading"
        />

        <ActionWidget
            title="Escalated SDRs"
            description="Significant departure reports past due across departments."
            :items="escalatedSdrItems"
            empty-message="No escalated SDRs."
            view-all-href="/sdrs"
            :loading="loading"
        />

        <ActionWidget
            title="Care Plans Due Within 30 Days"
            description="Care plans approaching or past their review deadline."
            :items="carePlanItems"
            empty-message="No care plans due within 30 days."
            view-all-href="/clinical/care-plans"
            :loading="loading"
        />

        <ActionWidget
            title="Alerts: Last 24 Hours"
            description="System and clinical alerts from the past 24 hours."
            :items="alertItems"
            empty-message="No alerts in the past 24 hours."
            view-all-href="/qa/dashboard"
            :loading="loading"
        />

        <ActionWidget
            title="IDT Reassessment Overdue (42 CFR §460.104(c))"
            description="Participants overdue for their IDT reassessment."
            :items="idtReviewItems"
            empty-message="No reassessments overdue."
            view-all-href="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Significant Change Reviews Due (42 CFR §460.104(b))"
            description="Significant change events requiring IDT review."
            :items="significantChangeItems"
            empty-message="No significant change reviews pending."
            view-all-href="/idt"
            :loading="loading"
        />
    </div>
</template>
