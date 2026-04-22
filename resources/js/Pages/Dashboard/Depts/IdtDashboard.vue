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
const slaSdrs = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/idt/meetings'),
        axios.get('/dashboards/idt/overdue-sdrs'),
        axios.get('/dashboards/idt/care-plans'),
        axios.get('/dashboards/idt/alerts'),
        axios.get('/dashboards/idt/idt-review-overdue'),
        axios.get('/dashboards/idt/significant-changes'),
        axios.get('/dashboards/idt/sdr-sla'),
    ]).then(([r1, r2, r3, r4, r5, r6, r7]) => {
        meetings.value = r1.data.meetings ?? []
        sdrDepartments.value = r2.data.departments ?? []
        carePlans.value = r3.data.care_plans ?? []
        alerts.value = r4.data.alerts ?? r4.data ?? []
        overdueParticipants.value = r5.data.participants ?? []
        significantEvents.value = r6.data.events ?? []
        slaSdrs.value = r7.data.sdrs ?? []
    }).finally(() => loading.value = false)
})

const meetingItems = computed<ActionItem[]>(() =>
    meetings.value.map(m => {
        const sublabelParts = [m.meeting_time, m.site, m.facilitator].filter(Boolean)
        return {
            label: m.type_label ?? '-',
            sublabel: sublabelParts.join(' | ') || undefined,
            badge: m.status === 'in_progress' ? 'In Progress' : 'Scheduled',
            badgeColor: m.status === 'in_progress'
                ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
                : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
            href: m.href ?? (m.id ? `/idt/meetings/${m.id}` : '/idt/meetings'),
        }
    })
)

const escalatedSdrItems = computed<ActionItem[]>(() => {
    const items: ActionItem[] = []
    sdrDepartments.value.forEach(dept => {
        const sdrs: any[] = dept.sdrs ?? []
        sdrs.forEach(s => {
            items.push({
                label: `${s.participant?.name ?? '-'} : ${s.type_label ?? '-'}`,
                sublabel: dept.department?.replace(/_/g, ' ') ?? undefined,
                badge: `${s.hours_overdue ?? 0}h overdue`,
                badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
                href: s.href ?? (s.participant?.id ? `/participants/${s.participant.id}` : '/sdrs'),
            })
        })
    })
    return items
})

const carePlanItems = computed<ActionItem[]>(() =>
    carePlans.value.map(cp => {
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
            href: cp.href ?? (cp.participant?.id ? `/participants/${cp.participant.id}` : '/clinical/care-plans'),
        }
    })
)

const alertItems = computed<ActionItem[]>(() =>
    alerts.value.map(a => ({
        label: a.title ?? '-',
        sublabel: [a.participant?.name ?? 'System', a.created_at].filter(Boolean).join(' | ') || undefined,
        badge: a.severity ?? '-',
        badgeColor: a.severity === 'critical'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : a.severity === 'warning'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/qa/dashboard'),
    }))
)

const idtReviewItems = computed<ActionItem[]>(() =>
    overdueParticipants.value.map(p => {
        const sublabelParts = [`MRN: ${p.mrn ?? '-'}`, p.site, `Last: ${p.last_reviewed_at ?? 'Never'}`].filter(Boolean)
        return {
            label: p.name ?? '-',
            sublabel: sublabelParts.join(' | ') || undefined,
            badge: p.days_overdue != null ? `${p.days_overdue}d overdue` : 'No review on record',
            badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
            href: p.href ?? (p.id ? `/participants/${p.id}` : '/participants'),
        }
    })
)

const significantChangeItems = computed<ActionItem[]>(() =>
    significantEvents.value.map(e => {
        const days = e.days ?? 0
        const absDays = Math.abs(days)
        const sublabelParts = [e.trigger_type_label, e.trigger_date ? `Trigger: ${e.trigger_date}` : null, e.site].filter(Boolean)
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
            href: e.href ?? (e.participant?.id ? `/participants/${e.participant.id}` : '/idt'),
        }
    })
)

// Phase 2 (MVP roadmap): SDR SLA (dual clock) §460.121
const sdrSlaItems = computed<ActionItem[]>(() =>
    slaSdrs.value.map((s: any) => {
        const isExp  = s.sdr_type === 'expedited'
        const remain = s.hours_remaining ?? 0
        const badge  = s.overdue
            ? 'overdue'
            : remain < 1
                ? '<1h'
                : `${remain}h left`
        const badgeColor =
            s.overdue        ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' :
            (s.window_pct ?? 0) >= 75 ? 'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300' :
            (s.window_pct ?? 0) >= 50 ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' :
                                         'bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300'
        return {
            label: s.participant?.name ?? '—',
            sublabel: `${(s.request_type ?? '').replace(/_/g, ' ')} · ${(s.assigned_department ?? '').replace(/_/g, ' ')}${isExp ? ' · EXPEDITED' : ''}`,
            badge,
            badgeColor,
            href: s.href ?? '/sdrs',
        }
    })
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">
        <ActionWidget
            title="Today's IDT Meetings"
            description="Interdisciplinary team meetings scheduled today."
            :items="meetingItems"
            emptyMessage="No IDT meetings scheduled today."
            viewAllHref="/idt/meetings"
            :loading="loading"
        />

        <ActionWidget
            title="Escalated SDRs"
            description="Significant departure reports past due across departments."
            :items="escalatedSdrItems"
            emptyMessage="No escalated SDRs."
            viewAllHref="/sdrs"
            :loading="loading"
        />

        <ActionWidget
            title="Care Plans Due Within 30 Days"
            description="Care plans approaching or past their review deadline."
            :items="carePlanItems"
            emptyMessage="No care plans due within 30 days."
            viewAllHref="/clinical/care-plans"
            :loading="loading"
        />

        <ActionWidget
            title="Alerts: Last 24 Hours"
            description="System and clinical alerts from the past 24 hours."
            :items="alertItems"
            emptyMessage="No alerts in the past 24 hours."
            viewAllHref="/qa/dashboard"
            :loading="loading"
        />

        <ActionWidget
            title="IDT Reassessment Overdue (42 CFR §460.104(c))"
            description="Participants overdue for their IDT reassessment."
            :items="idtReviewItems"
            emptyMessage="No reassessments overdue."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Significant Change Reviews Due (42 CFR §460.104(b))"
            description="Significant change events requiring IDT review."
            :items="significantChangeItems"
            emptyMessage="No significant change reviews pending."
            viewAllHref="/idt"
            :loading="loading"
        />

        <!-- Phase 2 (MVP roadmap): dual-clock SDR SLA §460.121 -->
        <ActionWidget
            title="SDR SLA (72h standard / 24h expedited)"
            description="Open SDRs ranked by clock consumption. Expedited requests surface first."
            :items="sdrSlaItems"
            emptyMessage="No open SDRs."
            viewAllHref="/sdrs"
            :loading="loading"
        />
    </div>
</template>
