<script setup lang="ts">
// ─── TherapiesDashboard.vue ───────────────────────────────────────────────────
// Therapies department live dashboard.
// Endpoints:
//   GET /dashboards/therapies/schedule  → appointments[]
//   GET /dashboards/therapies/goals     → { goals[] }
//   GET /dashboards/therapies/sdrs      → { sdrs[] }
//   GET /dashboards/therapies/docs      → { unsigned_notes[] }
//   GET /dashboards/therapies/orders    → { orders[], stat_count }
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
const orders = ref<any[]>([])
const statCount = ref(0)

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/therapies/schedule'),
        axios.get('/dashboards/therapies/goals'),
        axios.get('/dashboards/therapies/sdrs'),
        axios.get('/dashboards/therapies/docs'),
        axios.get('/dashboards/therapies/orders'),
    ])
        .then(([r1, r2, r3, r4, r5]) => {
            appointments.value = r1.data.appointments ?? r1.data ?? []
            goals.value = r2.data.goals ?? []
            sdrs.value = r3.data.sdrs ?? []
            unsignedNotes.value = r4.data.unsigned_notes ?? []
            orders.value = r5.data.orders ?? []
            statCount.value = r5.data.stat_count ?? 0
        })
        .finally(() => (loading.value = false))
})

const scheduleItems = computed<ActionItem[]>(() =>
    appointments.value.map((a) => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label ?? '-'}`,
        sublabel: a.scheduled_start ?? undefined,
        badge:
            a.status === 'confirmed'
                ? 'Confirmed'
                : a.status === 'scheduled'
                  ? 'Scheduled'
                  : (a.status ?? '-'),
        badgeColor:
            a.status === 'confirmed'
                ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
                : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    })),
)

const goalItems = computed<ActionItem[]>(() =>
    goals.value.map((g) => {
        const parts: string[] = []
        if (g.discipline) parts.push(g.discipline)
        if (g.target_date) parts.push(g.target_date)
        return {
            label: `${g.participant?.name ?? '-'} — ${(g.goal_text ?? '').slice(0, 40)}`,
            sublabel: parts.join(' | ') || undefined,
            badge:
                g.status === 'on_track'
                    ? 'On Track'
                    : g.status === 'at_risk'
                      ? 'At Risk'
                      : g.status === 'not_met'
                        ? 'Not Met'
                        : (g.status ?? '-'),
            badgeColor:
                g.status === 'on_track'
                    ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
                    : g.status === 'at_risk'
                      ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
                      : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        }
    }),
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

const noteItems = computed<ActionItem[]>(() =>
    unsignedNotes.value.map((n) => ({
        label: `${n.participant?.name ?? '-'} — ${n.type_label ?? '-'}`,
        sublabel: n.visit_date ?? n.created_at ?? undefined,
        badge: n.author ? undefined : 'Unassigned',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    })),
)

const orderItems = computed<ActionItem[]>(() =>
    orders.value.map((o) => ({
        label: `${o.participant_first_name ?? ''} ${o.participant_last_name ?? ''} — ${o.order_type_label ?? '-'}`.trim(),
        sublabel: o.is_overdue ? 'OVERDUE' : (o.status ?? undefined),
        badge: o.priority?.toUpperCase() ?? '-',
        badgeColor:
            o.priority === 'stat'
                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                : o.priority === 'urgent'
                  ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
                  : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
    })),
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Today's Schedule"
            description="Therapy appointments scheduled for today."
            :items="scheduleItems"
            empty-message="No therapy appointments today."
            view-all-href="/schedule"
            :loading="loading"
        />

        <ActionWidget
            title="Active Care Plan Goals"
            description="Therapy goals across active care plans."
            :items="goalItems"
            empty-message="No active care plan goals."
            view-all-href="/clinical/care-plans"
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
            title="Unsigned Notes"
            description="Therapy notes pending provider signature."
            :items="noteItems"
            empty-message="No unsigned notes."
            view-all-href="/clinical/notes"
            :loading="loading"
        />

        <ActionWidget
            :title="`Therapy Orders (${statCount} STAT)`"
            description="Open therapy orders by priority."
            :items="orderItems"
            empty-message="No open therapy orders."
            view-all-href="/orders"
            :loading="loading"
            class="lg:col-span-2"
        />
    </div>
</template>
