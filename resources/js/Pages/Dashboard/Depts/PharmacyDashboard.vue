<script setup lang="ts">
// ─── PharmacyDashboard.vue ────────────────────────────────────────────────────
// Pharmacy department live dashboard. Used by pharmacists to review new + DC'd
// medication orders, drug-drug interaction alerts, controlled-substance log
// entries, refills due, and STAT pharmacy orders.
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
const bcmaOverrides = ref<{ rows: any[]; total: number }>({ rows: [], total: 0 })
const beersRollup = ref<any>({ participants_with_pims: 0, enrolled_total: 0, top_pim_categories: [] })
const medwatchRows = ref<any[]>([])
const polyRows = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/pharmacy/med-changes'),
        axios.get('/dashboards/pharmacy/interactions'),
        axios.get('/dashboards/pharmacy/controlled'),
        axios.get('/dashboards/pharmacy/refills'),
        axios.get('/dashboards/pharmacy/orders'),
        axios.get('/dashboards/pharmacy/bcma-overrides'),
        axios.get('/dashboards/pharmacy/beers-rollup'),
        axios.get('/dashboards/pharmacy/medwatch-deadlines'),
        axios.get('/dashboards/pharmacy/polypharmacy-queue'),
    ]).then(([r1, r2, r3, r4, r5, r6, r7, r8, r9]) => {
        newOrders.value = r1.data.new_orders ?? []
        discontinued.value = r1.data.discontinued ?? []
        interactionAlerts.value = r2.data.alerts ?? []
        controlledRecords.value = r3.data.records ?? []
        refillMedications.value = r4.data.medications ?? []
        orders.value = r5.data.orders ?? []
        statCount.value = r5.data.stat_count ?? 0
        bcmaOverrides.value = r6.data ?? { rows: [], total: 0 }
        beersRollup.value = r7.data ?? { participants_with_pims: 0, enrolled_total: 0, top_pim_categories: [] }
        medwatchRows.value = r8.data.rows ?? []
        polyRows.value = r9.data.rows ?? []
    }).finally(() => loading.value = false)
})

const medwatchItems = computed<ActionItem[]>(() =>
    medwatchRows.value.map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.medication ?? '-'}`,
        sublabel: `Onset ${a.onset_date ?? '-'} · ${a.days_since_onset ?? '?'}d since`,
        badge: a.overdue ? 'OVERDUE' : a.severity,
        badgeColor: a.overdue
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: a.href ?? '/compliance/ade-reporting',
    }))
)

const polyItems = computed<ActionItem[]>(() =>
    polyRows.value.map(r => ({
        label: `${r.participant?.name ?? '-'}`,
        sublabel: `${r.active_med_count_at_queue} active meds`,
        badge: 'Pending',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: r.href ?? '/pharmacy',
    }))
)

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

        <ActionWidget
            :title="`MedWatch Deadlines (${medwatchRows.length})`"
            description="Severe+ ADEs awaiting MedWatch reporting (15-day rule)."
            :items="medwatchItems"
            emptyMessage="No MedWatch reports pending."
            viewAllHref="/compliance/ade-reporting"
            :loading="loading"
        />

        <ActionWidget
            :title="`Polypharmacy Review Queue (${polyRows.length})`"
            description="Participants queued for polypharmacy review."
            :items="polyItems"
            emptyMessage="No polypharmacy reviews pending."
            viewAllHref="/pharmacy"
            :loading="loading"
        />

        <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 shadow-sm">
            <div class="flex items-baseline justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Beers PIM Rollup</h3>
                <!-- Phase P11 — honest empty-state when tenant has no enrolled participants -->
                <span v-if="beersRollup.enrolled_total > 0" class="text-xs text-gray-500 dark:text-slate-400">
                    {{ beersRollup.participants_with_pims }}/{{ beersRollup.enrolled_total }} enrolled
                </span>
                <span v-else class="text-xs text-gray-400 italic">no enrolled participants</span>
            </div>
            <p class="text-xs text-gray-500 dark:text-slate-400 mb-2">Top PIM categories across panel.</p>
            <ul v-if="beersRollup.top_pim_categories?.length" class="text-sm space-y-1">
                <li
                    v-for="c in beersRollup.top_pim_categories"
                    :key="c.category"
                    class="flex justify-between border-b border-gray-100 dark:border-slate-700 pb-1"
                >
                    <span class="text-gray-700 dark:text-slate-200">{{ c.category }}</span>
                    <span class="font-semibold text-gray-900 dark:text-slate-100">{{ c.count }}</span>
                </li>
            </ul>
            <p v-else-if="beersRollup.enrolled_total === 0" class="text-sm text-gray-500 dark:text-slate-400">
                No enrolled participants in this tenant yet.
            </p>
            <p v-else class="text-sm text-gray-500 dark:text-slate-400">No PIMs detected on enrolled panel.</p>
        </div>

        <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 shadow-sm">
            <div class="flex items-baseline justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">BCMA Overrides — Last 7 Days</h3>
                <span class="text-xs text-gray-500 dark:text-slate-400">Total: {{ bcmaOverrides.total }}</span>
            </div>
            <ul v-if="bcmaOverrides.rows?.length" class="text-sm space-y-1">
                <li
                    v-for="d in bcmaOverrides.rows"
                    :key="d.day"
                    class="flex justify-between border-b border-gray-100 dark:border-slate-700 pb-1"
                >
                    <span class="text-gray-700 dark:text-slate-200">{{ d.day }}</span>
                    <span class="font-semibold text-gray-900 dark:text-slate-100">{{ d.count }}</span>
                </li>
            </ul>
            <p v-else class="text-sm text-gray-500 dark:text-slate-400">No BCMA overrides in the last 7 days.</p>
        </div>
    </div>
</template>
