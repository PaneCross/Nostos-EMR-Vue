<script setup lang="ts">
// ─── FinanceDashboard.vue ─────────────────────────────────────────────────────
// Finance department live dashboard.
// Endpoints:
//   GET /dashboards/finance/capitation      → { records[] }
//   GET /dashboards/finance/encounters      → { encounters[] }
//   GET /dashboards/finance/authorizations  → { authorizations[] }
//   GET /dashboards/finance/alerts          → { alerts[] }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const capitationRecords = ref<any[]>([])
const encounters = ref<any[]>([])
const authorizations = ref<any[]>([])
const alerts = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/finance/capitation'),
        axios.get('/dashboards/finance/encounters'),
        axios.get('/dashboards/finance/authorizations'),
        axios.get('/dashboards/finance/alerts'),
    ]).then(([r1, r2, r3, r4]) => {
        capitationRecords.value = r1.data.records ?? []
        encounters.value = r2.data.encounters ?? []
        authorizations.value = r3.data.authorizations ?? []
        alerts.value = r4.data.alerts ?? r4.data ?? []
    }).finally(() => loading.value = false)
})

function formatCurrency(value: number | null | undefined): string {
    if (value == null) return '-'
    return `$${Number(value).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`
}

const capitationItems = computed<ActionItem[]>(() =>
    capitationRecords.value.map(r => ({
        label: r.participant?.name ?? '-',
        sublabel: [r.month_year, r.eligibility_category].filter(Boolean).join(' | ') || undefined,
        badge: formatCurrency(r.total_capitation),
        badgeColor: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    }))
)

const encounterItems = computed<ActionItem[]>(() =>
    encounters.value.map(e => ({
        label: `${e.participant?.name ?? '-'} — ${e.service_type ?? '-'}`,
        sublabel: [e.service_date, e.procedure_code ?? '-'].filter(Boolean).join(' | ') || undefined,
        badge: e.submission_status ?? '-',
        badgeColor: e.submission_status === 'accepted'
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : e.submission_status === 'submitted'
            ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
            : e.submission_status === 'pending'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    }))
)

const authorizationItems = computed<ActionItem[]>(() =>
    authorizations.value.map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.service_type ?? '-'}`,
        sublabel: [a.authorized_start, a.authorized_end ? `to ${a.authorized_end}` : null].filter(Boolean).join(' ') || undefined,
        badge: a.status === 'active' ? 'Active'
            : a.status === 'expiring_soon' ? 'Expiring Soon'
            : a.status === 'expired' ? 'Expired'
            : (a.status ?? '-'),
        badgeColor: a.status === 'active'
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : a.status === 'expiring_soon'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
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
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Recent Capitation Records"
            description="Latest capitation payments by participant."
            :items="capitationItems"
            emptyMessage="No capitation records found."
            viewAllHref="/finance/capitation"
            :loading="loading"
        />

        <ActionWidget
            title="Recent Encounters"
            description="Encounter submissions and their current status."
            :items="encounterItems"
            emptyMessage="No recent encounters."
            viewAllHref="/finance/encounters"
            :loading="loading"
        />

        <ActionWidget
            title="Expiring Authorizations"
            description="Service authorizations expiring soon or already expired."
            :items="authorizationItems"
            emptyMessage="No authorization issues found."
            viewAllHref="/finance/dashboard"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="Finance-related alerts requiring attention."
            :items="alertItems"
            emptyMessage="No active alerts."
            viewAllHref="/finance/dashboard"
            :loading="loading"
        />
    </div>
</template>
