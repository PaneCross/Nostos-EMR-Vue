<script setup lang="ts">
// ─── ExecutiveDashboard.vue ───────────────────────────────────────────────────
// Executive role live dashboard.
// Endpoints:
//   GET /dashboards/executive/org-overview      → { participants_enrolled, active_alerts, open_incidents, pending_sdrs }
//   GET /dashboards/executive/site-comparison   → { sites[] }
//   GET /dashboards/executive/financial-overview → { records[] }
//   GET /dashboards/executive/sites-list        → { sites[] } (as all_sites)
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const kpis = ref<Record<string, number>>({})
const siteComparison = ref<any[]>([])
const capitationRecords = ref<any[]>([])
const allSites = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/executive/org-overview'),
        axios.get('/dashboards/executive/site-comparison'),
        axios.get('/dashboards/executive/financial-overview'),
        axios.get('/dashboards/executive/sites-list'),
    ])
        .then(([r1, r2, r3, r4]) => {
            kpis.value = r1.data ?? {}
            siteComparison.value = r2.data.sites ?? []
            capitationRecords.value = r3.data.records ?? []
            allSites.value = r4.data.sites ?? []
        })
        .finally(() => (loading.value = false))
})

const orgOverviewItems = computed<ActionItem[]>(() => {
    const enrolled = kpis.value.participants_enrolled ?? 0
    const activeAlerts = kpis.value.active_alerts ?? 0
    const openIncidents = kpis.value.open_incidents ?? 0
    const pendingSdrs = kpis.value.pending_sdrs ?? 0
    return [
        {
            label: 'Participants Enrolled',
            badge: String(enrolled),
            badgeColor: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        },
        {
            label: 'Active Alerts',
            badge: String(activeAlerts),
            badgeColor:
                activeAlerts > 0
                    ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
                    : 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
        },
        {
            label: 'Open Incidents',
            badge: String(openIncidents),
            badgeColor:
                openIncidents > 0
                    ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
                    : 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
        },
        {
            label: 'Pending SDRs',
            badge: String(pendingSdrs),
            badgeColor:
                pendingSdrs > 0
                    ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
                    : 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
        },
    ]
})

const siteComparisonItems = computed<ActionItem[]>(() =>
    siteComparison.value.map((s) => ({
        label: s.name ?? s.site?.name ?? '-',
        sublabel: [`${s.active_alerts ?? 0} alerts`, `${s.open_incidents ?? 0} incidents`].join(
            ' | ',
        ),
        badge: `${s.enrolled_count ?? 0} enrolled`,
        badgeColor: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    })),
)

const capitationItems = computed<ActionItem[]>(() =>
    capitationRecords.value.map((r) => ({
        label: r.participant?.name ?? '-',
        sublabel: r.month_year ?? undefined,
        badge: r.total_capitation != null ? String(r.total_capitation) : '-',
        badgeColor: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    })),
)

const allSiteItems = computed<ActionItem[]>(() =>
    allSites.value.map((s) => ({
        label: s.name ?? '-',
        sublabel: s.address ?? '-',
        badge: s.status ?? 'Active',
        badgeColor: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    })),
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Organization Overview"
            description="High-level KPIs across the entire organization."
            :items="orgOverviewItems"
            empty-message="No organization data available."
            view-all-href="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Site Comparison"
            description="Key metrics compared across all PACE sites."
            :items="siteComparisonItems"
            empty-message="No site data available."
            view-all-href="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Recent Capitation"
            description="Latest capitation records across the organization."
            :items="capitationItems"
            empty-message="No capitation records found."
            view-all-href="/finance/capitation"
            :loading="loading"
        />

        <ActionWidget
            title="All Sites"
            description="Status of all registered PACE sites."
            :items="allSiteItems"
            empty-message="No sites found."
            view-all-href="/participants"
            :loading="loading"
        />
    </div>
</template>
