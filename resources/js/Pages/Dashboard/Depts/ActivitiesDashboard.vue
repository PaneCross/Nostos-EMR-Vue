<script setup lang="ts">
// ─── ActivitiesDashboard.vue ──────────────────────────────────────────────────
// Activities department live dashboard.
// Endpoints:
//   GET /dashboards/activities/schedule  → { appointments[] }
//   GET /dashboards/activities/goals     → { goals[] }
//   GET /dashboards/activities/sdrs      → { sdrs[], overdue_count, open_count }
//   GET /dashboards/activities/docs      → { unsigned_notes[] }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const appointments = ref<any[]>([])
const goals = ref<any[]>([])
const sdrs = ref<any[]>([])
const unsignedNotes = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/activities/schedule'),
        axios.get('/dashboards/activities/goals'),
        axios.get('/dashboards/activities/sdrs'),
        axios.get('/dashboards/activities/docs'),
    ]).then(([r1, r2, r3, r4]) => {
        appointments.value = r1.data.appointments ?? []
        goals.value = r2.data.goals ?? []
        sdrs.value = r3.data.sdrs ?? []
        unsignedNotes.value = r4.data.unsigned_notes ?? []
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

const goalItems = computed<ActionItem[]>(() =>
    goals.value.map(g => ({
        label: `${g.participant?.name ?? '-'} - Activities Goal`,
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

const noteItems = computed<ActionItem[]>(() =>
    unsignedNotes.value.map(n => ({
        label: `${n.participant?.name ?? '-'} - ${n.type_label ?? '-'}`,
        sublabel: n.visit_date ?? n.created_at ?? undefined,
        badge: n.author ? undefined : 'Unassigned',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: n.href ?? (n.participant?.id ? `/participants/${n.participant.id}` : '/clinical/notes'),
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">
        <ActionWidget
            title="Today's Activities"
            description="Activity sessions and group appointments scheduled for today."
            :items="scheduleItems"
            emptyMessage="No activity appointments today."
            viewAllHref="/schedule"
            :loading="loading"
        />

        <ActionWidget
            title="Goals Due"
            description="Activity and recreational care plan goals with target dates within 14 days or past due."
            :items="goalItems"
            emptyMessage="No active activity goals due soon."
            viewAllHref="/clinical/care-plans"
            :loading="loading"
        />

        <ActionWidget
            title="Overdue SDRs"
            description="SDRs assigned to activities past their 72-hour deadline."
            :items="sdrItems"
            emptyMessage="No open SDRs."
            viewAllHref="/sdrs"
            :loading="loading"
        />

        <ActionWidget
            title="Unsigned Notes"
            description="Activity notes pending provider signature."
            :items="noteItems"
            emptyMessage="No unsigned notes."
            viewAllHref="/clinical/notes"
            :loading="loading"
        />
    </div>
</template>
