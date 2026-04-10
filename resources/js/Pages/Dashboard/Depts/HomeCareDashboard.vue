<script setup lang="ts">
// ─── HomeCareDashboard.vue ────────────────────────────────────────────────────
// Home Care department live dashboard.
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

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/home-care/schedule'),
        axios.get('/dashboards/home-care/adl-alerts'),
        axios.get('/dashboards/home-care/goals'),
        axios.get('/dashboards/home-care/sdrs'),
        axios.get('/dashboards/home-care/wounds'),
    ]).then(([r1, r2, r3, r4, r5]) => {
        appointments.value = r1.data.appointments ?? []
        adlAlerts.value = r2.data.alerts ?? []
        goals.value = r3.data.goals ?? []
        sdrs.value = r4.data.sdrs ?? []
        wounds.value = r5.data.wounds ?? []
        criticalCount.value = r5.data.critical_count ?? 0
    }).finally(() => loading.value = false)
})

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
    }))
)

const goalItems = computed<ActionItem[]>(() =>
    goals.value.map(g => ({
        label: `${g.participant?.name ?? '-'} - Home Care Goal`,
        sublabel: g.goal_description ?? undefined,
        badge: g.target_date ? `Due ${g.target_date}` : undefined,
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
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
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
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
            class="lg:col-span-2"
        />
    </div>
</template>
