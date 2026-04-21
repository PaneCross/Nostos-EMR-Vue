<script setup lang="ts">
// ─── PharmacyDashboard.vue ────────────────────────────────────────────────────
// Pharmacy department live dashboard.
// Endpoints:
//   GET /dashboards/pharmacy/med-changes   → { new_orders[], discontinued[] }
//   GET /dashboards/pharmacy/interactions  → { alerts[] }
//   GET /dashboards/pharmacy/controlled    → { records[] }
//   GET /dashboards/pharmacy/refills       → { medications[] }
//   GET /dashboards/pharmacy/orders        → { orders[], stat_count }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const newOrders = ref<any[]>([])
const discontinued = ref<any[]>([])
const interactionAlerts = ref<any[]>([])
const controlledRecords = ref<any[]>([])
const refillMedications = ref<any[]>([])
const orders = ref<any[]>([])
const statCount = ref(0)

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/pharmacy/med-changes'),
        axios.get('/dashboards/pharmacy/interactions'),
        axios.get('/dashboards/pharmacy/controlled'),
        axios.get('/dashboards/pharmacy/refills'),
        axios.get('/dashboards/pharmacy/orders'),
    ]).then(([r1, r2, r3, r4, r5]) => {
        newOrders.value = r1.data.new_orders ?? []
        discontinued.value = r1.data.discontinued ?? []
        interactionAlerts.value = r2.data.alerts ?? []
        controlledRecords.value = r3.data.records ?? []
        refillMedications.value = r4.data.medications ?? []
        orders.value = r5.data.orders ?? []
        statCount.value = r5.data.stat_count ?? 0
    }).finally(() => loading.value = false)
})

const medChangeItems = computed<ActionItem[]>(() => {
    const newItems: ActionItem[] = newOrders.value.map(m => ({
        label: `${m.participant?.name ?? '-'} — ${m.drug_name ?? '-'}`,
        sublabel: m.prescriber ?? undefined,
        badge: 'New',
        badgeColor: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        href: m.href ?? (m.participant?.id ? `/participants/${m.participant.id}` : '/clinical/medications'),
    }))
    const dcItems: ActionItem[] = discontinued.value.map(m => ({
        label: `${m.participant?.name ?? '-'} — ${m.drug_name ?? '-'}`,
        sublabel: m.discontinued_reason ?? undefined,
        badge: 'D/C',
        badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        href: m.href ?? (m.participant?.id ? `/participants/${m.participant.id}` : '/clinical/medications'),
    }))
    return [...newItems, ...dcItems]
})

const interactionItems = computed<ActionItem[]>(() =>
    interactionAlerts.value.map(a => ({
        label: `${a.drug_name_1 ?? '-'} : ${a.drug_name_2 ?? '-'}`,
        sublabel: [a.participant?.name, a.created_at].filter(Boolean).join(' | ') || undefined,
        badge: a.severity === 'contraindicated' ? 'Contraindicated'
            : a.severity === 'major' ? 'Major'
            : (a.severity ?? '-'),
        badgeColor: a.severity === 'contraindicated'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : a.severity === 'major'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/clinical/medications'),
    }))
)

const controlledItems = computed<ActionItem[]>(() =>
    controlledRecords.value.map(r => {
        const sublabelParts = [r.controlled_schedule, r.status, r.scheduled_time].filter(Boolean)
        return {
            label: `${r.participant?.name ?? '-'} : ${r.drug_name ?? '-'}`,
            sublabel: sublabelParts.join(' | ') || undefined,
            badge: r.needs_witness ? 'Missing Witness' : (r.controlled_schedule ?? undefined),
            badgeColor: r.needs_witness
                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
            href: r.href ?? (r.participant?.id ? `/participants/${r.participant.id}` : '/clinical/medications'),
        }
    })
)

const refillItems = computed<ActionItem[]>(() =>
    refillMedications.value.map(m => ({
        label: `${m.participant?.name ?? '-'} : ${m.drug_name ?? '-'}`,
        sublabel: m.last_filled_date ? `Last filled: ${m.last_filled_date}` : 'Never filled',
        badge: m.refills_remaining === 0 ? '0 refills'
            : m.days_since_filled != null ? `${m.days_since_filled}d`
            : '-',
        badgeColor: m.refills_remaining === 0
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: m.href ?? (m.participant?.id ? `/participants/${m.participant.id}` : '/clinical/medications'),
    }))
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
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">
        <ActionWidget
            title="Medication Changes Today"
            description="New orders and discontinuations recorded today."
            :items="medChangeItems"
            emptyMessage="No medication changes today."
            viewAllHref="/clinical/medications"
            :loading="loading"
        />

        <ActionWidget
            title="Drug Interaction Alerts"
            description="Active drug-drug interaction alerts requiring review."
            :items="interactionItems"
            emptyMessage="No active drug interaction alerts."
            viewAllHref="/clinical/medications"
            :loading="loading"
        />

        <ActionWidget
            title="Controlled Substance Log: Today"
            description="Controlled substance administrations recorded today."
            :items="controlledItems"
            emptyMessage="No controlled substance records today."
            viewAllHref="/clinical/medications"
            :loading="loading"
        />

        <ActionWidget
            title="Refill Attention Required"
            description="Medications with low or zero refills remaining."
            :items="refillItems"
            emptyMessage="No refills require attention."
            viewAllHref="/clinical/medications"
            :loading="loading"
        />

        <ActionWidget
            :title="`Medication Change Orders (${statCount} STAT)`"
            description="Open medication orders by priority."
            :items="orderItems"
            emptyMessage="No open medication orders."
            viewAllHref="/orders"
            :loading="loading"
        />
    </div>
</template>
