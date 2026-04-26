<script setup lang="ts">
// ─── HomeCareDashboard.vue ────────────────────────────────────────────────────
// Home Care department live dashboard. Used by home-care nurses + aides for the
// day's home visit schedule, ADL/IADL alerts (Activities of Daily Living),
// home-care goals, open SDRs, and active wound-care cases.
// Endpoints:
//   GET /dashboards/home-care/schedule    → { appointments[] }
//   GET /dashboards/home-care/adl-alerts  → { alerts[], unacknowledged_count }
//   GET /dashboards/home-care/goals       → { goals[] }
//   GET /dashboards/home-care/sdrs        → { sdrs[], overdue_count, open_count }
//   GET /dashboards/home-care/wounds      → { wounds[], open_count, critical_count }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const appointments = ref<any[]>([])
const adlAlerts = ref<any[]>([])
const goals = ref<any[]>([])
const sdrs = ref<any[]>([])
const wounds = ref<any[]>([])
const criticalCount = ref(0)
const restraintOverdue = ref<any[]>([])
const activeInfections = ref<any[]>([])
const highRisk = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/home-care/schedule'),
        axios.get('/dashboards/home-care/adl-alerts'),
        axios.get('/dashboards/home-care/goals'),
        axios.get('/dashboards/home-care/sdrs'),
        axios.get('/dashboards/home-care/wounds'),
        axios.get('/dashboards/home-care/restraint-overdue'),
        axios.get('/dashboards/home-care/active-infections'),
        axios.get('/dashboards/home-care/high-risk-caseload'),
    ]).then(([r1, r2, r3, r4, r5, r6, r7, r8]) => {
        appointments.value = r1.data.appointments ?? []
        adlAlerts.value = r2.data.alerts ?? []
        goals.value = r3.data.goals ?? []
        sdrs.value = r4.data.sdrs ?? []
        wounds.value = r5.data.wounds ?? []
        criticalCount.value = r5.data.critical_count ?? 0
        restraintOverdue.value = r6.data.rows ?? []
        activeInfections.value = r7.data.rows ?? []
        highRisk.value = r8.data.rows ?? []
    }).finally(() => loading.value = false)
})

const restraintItems = computed<ActionItem[]>(() =>
    restraintOverdue.value.map(e => ({
        label: `${e.participant?.name ?? '-'}`,
        sublabel: `${e.minutes_since_last_obs}min since last obs (limit ${e.interval_min ?? '?'})`,
        badge: 'OVERDUE',
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        href: e.href ?? '/participants',
    }))
)

const infectionItems = computed<ActionItem[]>(() =>
    activeInfections.value.map(c => ({
        label: `${c.participant?.name ?? '-'}`,
        sublabel: [c.organism, c.onset_date ? `onset ${c.onset_date}` : null].filter(Boolean).join(' · ') || undefined,
        badge: c.severity ?? 'active',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: c.href ?? '/participants',
    }))
)

const highRiskItems = computed<ActionItem[]>(() =>
    highRisk.value.map(s => ({
        label: `${s.participant?.name ?? '-'} — ${s.risk_type}`,
        sublabel: `Score ${s.score} · Band ${s.band}`,
        badge: s.band?.toUpperCase(),
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        href: s.href ?? '/participants',
    }))
)

const scheduleItems = computed<ActionItem[]>(() =>
    appointments.value.map(a => ({
        label: `${a.participant?.name ?? '-'} - ${a.type_label ?? '-'}`,
        sublabel: [a.scheduled_start, a.transport_required ? 'Transport required' : null].filter(Boolean).join(' | ') || undefined,
        badge: a.status === 'confirmed' ? 'Confirmed'
            : a.status === 'scheduled' ? 'Scheduled'
            : (a.status ?? '-'),
        badgeColor: a.status === 'confirmed'
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/schedule'),
    }))
)

const adlAlertItems = computed<ActionItem[]>(() =>
    adlAlerts.value.map(a => ({
        label: `${a.participant?.name ?? 'No participant'} - ${a.type_label ?? '-'}`,
        sublabel: a.acknowledged ? `${a.created_at} (ack'd)` : (a.created_at ?? undefined),
        badge: a.severity ?? '-',
        badgeColor: a.severity === 'critical'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : a.severity === 'warning'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/participants'),
    }))
)

const goalItems = computed<ActionItem[]>(() =>
    goals.value.map(g => ({
        label: `${g.participant?.name ?? '-'} - Home Care Goal`,
        sublabel: g.goal_description ?? undefined,
        badge: g.target_date ? `Due ${g.target_date}` : undefined,
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: g.href ?? (g.participant?.id ? `/participants/${g.participant.id}` : '/clinical/care-plans'),
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

const woundItems = computed<ActionItem[]>(() =>
    wounds.value.map(w => ({
        label: `${w.participant?.name ?? '-'} - ${w.type_label ?? '-'}`,
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
            title="Today's Home Visits"
            description="Home visits scheduled for today. Complete visit documentation within 24h."
            :items="scheduleItems"
            emptyMessage="No home visits scheduled today."
            viewAllHref="/schedule"
            :loading="loading"
        />

        <ActionWidget
            title="ADL Alerts"
            description="Participants with ADL threshold breaches requiring care plan review."
            :items="adlAlertItems"
            emptyMessage="No active ADL alerts."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Goals Due"
            description="Home care goals with target dates within 14 days or past due."
            :items="goalItems"
            emptyMessage="No active home care goals due soon."
            viewAllHref="/clinical/care-plans"
            :loading="loading"
        />

        <ActionWidget
            title="Overdue SDRs"
            description="SDRs assigned to home care past their 72-hour deadline."
            :items="sdrItems"
            emptyMessage="No open SDRs."
            viewAllHref="/sdrs"
            :loading="loading"
        />

        <ActionWidget
            :title="`Open Wounds${criticalCount ? ` (${criticalCount} Critical)` : ''}`"
            description="Open wound records to monitor between day-center visits. Stage 3+, unstageable, and DTI wounds require immediate escalation."
            :items="woundItems"
            emptyMessage="No open wound records."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            :title="`Restraint Monitoring Overdue (${restraintOverdue.length})`"
            description="Active restraint episodes past their monitoring interval."
            :items="restraintItems"
            emptyMessage="No overdue restraint monitoring."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            :title="`Active Infections (${activeInfections.length})`"
            description="Active infection cases without resolution date."
            :items="infectionItems"
            emptyMessage="No active infection cases."
            viewAllHref="/compliance/infections"
            :loading="loading"
        />

        <ActionWidget
            :title="`High-Risk Caseload (${highRisk.length})`"
            description="Participants with recent high-band predictive risk scores."
            :items="highRiskItems"
            emptyMessage="No high-risk participants."
            viewAllHref="/participants"
            :loading="loading"
        />
    </div>
</template>
