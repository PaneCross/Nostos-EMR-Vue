<script setup lang="ts">
// ─── PrimaryCareDashboard.vue ─────────────────────────────────────────────────
// Primary Care department live dashboard.
// Endpoints:
//   GET /dashboards/primary-care/schedule  → appointments[]
//   GET /dashboards/primary-care/alerts    → alerts[]
//   GET /dashboards/primary-care/docs      → { unsigned_notes[], overdue_assessments[] }
//   GET /dashboards/primary-care/vitals    → vitals[]
//   GET /dashboards/primary-care/orders    → { orders[], stat_count }
//   GET /dashboards/primary-care/wounds    → { wounds[], critical_count }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const appointments = ref<any[]>([])
const alerts = ref<any[]>([])
const unsignedNotes = ref<any[]>([])
const overdueAssessments = ref<any[]>([])
const vitals = ref<any[]>([])
const orders = ref<any[]>([])
const statCount = ref(0)
const wounds = ref<any[]>([])
const criticalCount = ref(0)

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/primary-care/schedule'),
        axios.get('/dashboards/primary-care/alerts'),
        axios.get('/dashboards/primary-care/docs'),
        axios.get('/dashboards/primary-care/vitals'),
        axios.get('/dashboards/primary-care/orders'),
        axios.get('/dashboards/primary-care/wounds'),
    ]).then(([r1, r2, r3, r4, r5, r6]) => {
        appointments.value = r1.data.appointments ?? r1.data ?? []
        alerts.value = r2.data.alerts ?? r2.data ?? []
        unsignedNotes.value = r3.data.unsigned_notes ?? []
        overdueAssessments.value = r3.data.overdue_assessments ?? []
        vitals.value = r4.data.vitals ?? r4.data ?? []
        orders.value = r5.data.orders ?? []
        statCount.value = r5.data.stat_count ?? 0
        wounds.value = r6.data.wounds ?? []
        criticalCount.value = r6.data.critical_count ?? 0
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
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/schedule'),
    }))
)

const alertItems = computed<ActionItem[]>(() =>
    alerts.value.map(a => ({
        label: `${a.participant?.name ?? 'No participant'} — ${a.type_label ?? '-'}`,
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

const unsignedNoteItems = computed<ActionItem[]>(() =>
    unsignedNotes.value.map(n => ({
        label: `${n.participant?.name ?? '-'} — ${n.type_label ?? '-'}`,
        sublabel: n.visit_date ?? n.created_at ?? undefined,
        badge: n.author ? undefined : 'Unassigned',
        badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        href: n.href ?? (n.participant?.id ? `/participants/${n.participant.id}` : '/clinical/notes'),
    }))
)

const overdueAssessmentItems = computed<ActionItem[]>(() =>
    overdueAssessments.value.map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label ?? '-'}`,
        sublabel: a.next_due_date ? `Due ${a.next_due_date}` : undefined,
        badge: `${a.days_overdue ?? 0}d overdue`,
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/clinical/assessments'),
    }))
)

const vitalItems = computed<ActionItem[]>(() =>
    vitals.value.map(v => {
        const parts: string[] = []
        if (v.blood_pressure) parts.push(v.blood_pressure)
        if (v.oxygen_saturation) parts.push(`O2: ${v.oxygen_saturation}%`)
        if (v.recorded_at) parts.push(v.recorded_at)
        return {
            label: `${v.participant?.name ?? '-'} — Vitals`,
            sublabel: parts.join(' | ') || undefined,
            badge: v.out_of_range ? 'Out of range' : undefined,
            badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
            href: v.href ?? (v.participant?.id ? `/participants/${v.participant.id}` : '/clinical/vitals'),
        }
    })
)

const orderItems = computed<ActionItem[]>(() =>
    orders.value.map(o => ({
        label: `${o.participant_first_name ?? ''} ${o.participant_last_name ?? ''} — ${o.order_type_label ?? '-'}`.trim(),
        sublabel: o.is_overdue ? 'OVERDUE' : (o.status ?? undefined),
        badge: o.priority?.toUpperCase() ?? '-',
        badgeColor: o.priority === 'stat'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : o.priority === 'urgent'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        href: o.href ?? (o.participant_id ? `/participants/${o.participant_id}` : '/orders'),
    }))
)

const woundItems = computed<ActionItem[]>(() =>
    wounds.value.map(w => ({
        label: `${w.participant?.name ?? '-'} — ${w.type_label ?? '-'}`,
        sublabel: [w.location, w.days_open != null ? `${w.days_open}d open` : null].filter(Boolean).join(' | ') || undefined,
        badge: w.is_critical ? 'Stage 3+' : (w.stage_label ?? 'Open'),
        badgeColor: w.is_critical
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: w.href ?? (w.participant?.id ? `/participants/${w.participant.id}` : '/participants'),
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">
        <ActionWidget
            title="Today's Schedule"
            description="Appointments scheduled for today."
            :items="scheduleItems"
            emptyMessage="No appointments scheduled today."
            viewAllHref="/schedule"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="Alerts requiring primary care attention."
            :items="alertItems"
            emptyMessage="No active alerts."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Unsigned Notes"
            description="Clinical notes pending provider signature."
            :items="unsignedNoteItems"
            emptyMessage="No unsigned notes."
            viewAllHref="/clinical/notes"
            :loading="loading"
        />

        <ActionWidget
            title="Overdue Assessments"
            description="Assessments past their due date."
            :items="overdueAssessmentItems"
            emptyMessage="No overdue assessments."
            viewAllHref="/clinical/assessments"
            :loading="loading"
        />

        <ActionWidget
            title="Recent Vitals"
            description="Vitals recorded recently — flagged if out of range."
            :items="vitalItems"
            emptyMessage="No recent vitals recorded."
            viewAllHref="/clinical/vitals"
            :loading="loading"
        />

        <ActionWidget
            :title="`Clinical Orders (${statCount} STAT)`"
            description="Open orders by priority."
            :items="orderItems"
            emptyMessage="No open clinical orders."
            viewAllHref="/orders"
            :loading="loading"
        />

        <ActionWidget
            :title="`Open Wounds (${criticalCount} Critical)`"
            description="Active wound records requiring monitoring."
            :items="woundItems"
            emptyMessage="No open wound records."
            viewAllHref="/participants"
            :loading="loading"
        />
    </div>
</template>
