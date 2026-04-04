<script setup lang="ts">
// ─── HomeCareDashboard.vue ────────────────────────────────────────────────────
// Home Care department live dashboard.
// Endpoints:
//   GET /dashboards/home-care/schedule  → { appointments[] }
//   GET /dashboards/home-care/alerts    → { alerts[] }
//   GET /dashboards/home-care/docs      → { unsigned_notes[] }
//   GET /dashboards/home-care/wounds    → { wounds[], critical_count }
//   GET /dashboards/home-care/adl       → { adl_records[] }
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
const wounds = ref<any[]>([])
const criticalCount = ref(0)
const adlRecords = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/home-care/schedule'),
        axios.get('/dashboards/home-care/alerts'),
        axios.get('/dashboards/home-care/docs'),
        axios.get('/dashboards/home-care/wounds'),
        axios.get('/dashboards/home-care/adl'),
    ]).then(([r1, r2, r3, r4, r5]) => {
        appointments.value = r1.data.appointments ?? r1.data ?? []
        alerts.value = r2.data.alerts ?? r2.data ?? []
        unsignedNotes.value = r3.data.unsigned_notes ?? []
        wounds.value = r4.data.wounds ?? []
        criticalCount.value = r4.data.critical_count ?? 0
        adlRecords.value = r5.data.adl_records ?? []
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

const woundItems = computed<ActionItem[]>(() =>
    wounds.value.map(w => ({
        label: `${w.participant?.name ?? '-'} — ${w.type_label ?? '-'}`,
        sublabel: [w.location, w.days_open != null ? `${w.days_open}d open` : null].filter(Boolean).join(' | ') || undefined,
        badge: w.is_critical ? 'Stage 3+' : (w.stage_label ?? 'Open'),
        badgeColor: w.is_critical
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    }))
)

const adlItems = computed<ActionItem[]>(() =>
    adlRecords.value.map(r => ({
        label: r.participant?.name ?? '-',
        sublabel: r.recorded_at ?? undefined,
        badge: r.total_score != null ? `${r.total_score} pts` : '-',
        badgeColor: r.threshold_breached
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Home Visits Today"
            description="Home care visits scheduled for today."
            :items="scheduleItems"
            emptyMessage="No home visits scheduled today."
            viewAllHref="/schedule"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="Alerts requiring home care attention."
            :items="alertItems"
            emptyMessage="No active alerts."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Unsigned Notes"
            description="Home care notes pending provider signature."
            :items="noteItems"
            emptyMessage="No unsigned notes."
            viewAllHref="/clinical/notes"
            :loading="loading"
        />

        <ActionWidget
            :title="`Open Wounds (${criticalCount} Critical)`"
            description="Active wound records under home care monitoring."
            :items="woundItems"
            emptyMessage="No open wound records."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Recent ADL Records"
            description="Activities of daily living assessments recorded recently."
            :items="adlItems"
            emptyMessage="No recent ADL records."
            viewAllHref="/participants"
            :loading="loading"
            class="lg:col-span-2"
        />
    </div>
</template>
