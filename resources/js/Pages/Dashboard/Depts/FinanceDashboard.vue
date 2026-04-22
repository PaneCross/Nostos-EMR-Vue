<script setup lang="ts">
// ─── FinanceDashboard.vue ─────────────────────────────────────────────────────
// Finance department live dashboard.
// Endpoints:
//   GET /dashboards/finance/capitation         → { current_month, current_total, current_participant_count, prior_month, prior_total, change_percent }
//   GET /dashboards/finance/authorizations     → { authorizations[], expiring_count, expiring_this_week }
//   GET /dashboards/finance/enrollment-changes → { enrolled_this_month, disenrolled_this_month, total_enrolled, net_change }
//   GET /dashboards/finance/encounters         → { total_encounters, this_month_encounters, by_service_type{} }
//   GET /dashboards/finance/open-denials       → { open_count, appealing_count, overdue_count, revenue_at_risk, items[] }
//   GET /dashboards/finance/revenue-at-risk    → { total_at_risk, won_this_month, by_category[] }
//   GET /dashboards/finance/recent-remittance  → { batches[], total_received_this_month }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const capitation = ref<any>(null)
const authorizationsData = ref<any>(null)
const enrollmentChanges = ref<any>(null)
const encountersData = ref<any>(null)
const denialsData = ref<any>(null)
const revenueRiskData = ref<any>(null)
const remittanceData = ref<any>(null)
const cmsReconciliationData = ref<any>(null)

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/finance/capitation'),
        axios.get('/dashboards/finance/authorizations'),
        axios.get('/dashboards/finance/enrollment-changes'),
        axios.get('/dashboards/finance/encounters'),
        axios.get('/dashboards/finance/open-denials'),
        axios.get('/dashboards/finance/revenue-at-risk'),
        axios.get('/dashboards/finance/recent-remittance'),
        axios.get('/dashboards/finance/cms-reconciliation'),
    ]).then(([r1, r2, r3, r4, r5, r6, r7, r8]) => {
        capitation.value = r1.data
        authorizationsData.value = r2.data
        enrollmentChanges.value = r3.data
        encountersData.value = r4.data
        denialsData.value = r5.data
        revenueRiskData.value = r6.data
        remittanceData.value = r7.data
        cmsReconciliationData.value = r8.data
    }).finally(() => loading.value = false)
})

function formatCurrency(n: number | null | undefined): string {
    if (n == null) return '-'
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(n)
}

const authorizationItems = computed<ActionItem[]>(() =>
    (authorizationsData.value?.authorizations ?? []).map((a: any) => ({
        label: `${a.participant?.name ?? '-'} : ${a.service_label ?? a.service_type ?? '-'}`,
        sublabel: a.authorized_end ?? undefined,
        badge: `${a.days_until_expiry ?? 0}d`,
        badgeColor: (a.days_until_expiry ?? 0) <= 7
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    }))
)

const encounterItems = computed<ActionItem[]>(() => {
    if (!encountersData.value?.by_service_type) return []
    return Object.entries(encountersData.value.by_service_type as Record<string, number>).map(([type, count]) => ({
        label: type.replace(/_/g, ' '),
        badge: String(count),
        badgeColor: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    }))
})

const denialItems = computed<ActionItem[]>(() =>
    (denialsData.value?.items ?? []).map((d: any) => ({
        label: `${d.category_label} : ${formatCurrency(d.denied_amount)}`,
        href: d.href ?? '/finance/denials',
        badge: d.is_overdue ? 'Overdue' : `${d.days_until_deadline ?? 0}d`,
        badgeColor: d.is_overdue
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    }))
)

const revenueRiskItems = computed<ActionItem[]>(() =>
    (revenueRiskData.value?.by_category ?? []).map((c: any) => ({
        label: `${c.label} (${c.count})`,
        href: '/finance/denials',
        badge: formatCurrency(c.total_amount),
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    }))
)

const remittanceItems = computed<ActionItem[]>(() =>
    (remittanceData.value?.batches ?? []).map((b: any) => ({
        label: `${b.payer_name ?? b.file_name}`,
        sublabel: b.payment_date ?? undefined,
        href: b.href ?? '/finance/remittance',
        badge: formatCurrency(b.payment_amount),
        badgeColor: b.denied_count > 0
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    }))
)

// Phase 6 (MVP roadmap): CMS enrollment reconciliation widget
const DISC_LABELS: Record<string, string> = {
    cms_enrolled_not_local:           'CMS Enrolled, Not Local',
    cms_disenrolled_local_enrolled:   'CMS Disenrolled, Locally Enrolled',
    capitation_variance:              'Capitation Variance',
    retroactive_adjustment:           'Retroactive Adjustment',
    unmatched_mbi:                    'Unmatched MBI',
}

const cmsReconciliationItems = computed<ActionItem[]>(() => {
    const data = cmsReconciliationData.value
    if (!data) return []

    const rows: ActionItem[] = []

    // Row 1: latest MMR summary
    if (data.latest_mmr) {
        rows.push({
            label: `Latest MMR: ${data.latest_mmr.label}`,
            sublabel: `${data.latest_mmr.record_count} records · ${formatCurrency(data.latest_mmr.total_capitation_amount)}`,
            badge: data.latest_mmr.discrepancy_count > 0
                ? `${data.latest_mmr.discrepancy_count} flagged`
                : 'clean',
            badgeColor: data.latest_mmr.discrepancy_count > 0
                ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
                : 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
            href: '/billing/reconciliation',
        })
    }

    // Row 2+: open discrepancy counts by type
    for (const [type, count] of Object.entries(data.open_by_type ?? {})) {
        rows.push({
            label: DISC_LABELS[type] ?? type,
            sublabel: 'open discrepancy',
            badge: String(count),
            badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
            href: '/billing/reconciliation',
        })
    }

    // Row: TRR rejected
    if ((data.trr_rejected_last_30d ?? 0) > 0) {
        rows.push({
            label: 'TRR rejections (30d)',
            sublabel: 'CMS rejected transactions',
            badge: String(data.trr_rejected_last_30d),
            badgeColor: 'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300',
            href: '/billing/reconciliation',
        })
    }

    return rows
})
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">

        <!-- Current Month Capitation — KPI stat widget, not a list -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Current Month Capitation</h3>
            <template v-if="loading">
                <div class="space-y-2 animate-pulse">
                    <div v-for="i in 3" :key="i" class="h-8 bg-slate-100 dark:bg-slate-800 rounded" />
                </div>
            </template>
            <template v-else-if="!capitation">
                <p class="text-sm text-gray-400 dark:text-slate-500 py-4 text-center">No capitation data</p>
            </template>
            <template v-else>
                <div class="space-y-3">
                    <div class="flex items-end justify-between">
                        <div>
                            <p class="text-2xl font-bold text-slate-900 dark:text-slate-100">
                                {{ formatCurrency(capitation.current_total) }}
                            </p>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                                {{ capitation.current_participant_count }} participants | {{ capitation.current_month }}
                            </p>
                        </div>
                        <div
                            v-if="capitation.change_percent !== null && capitation.change_percent !== undefined"
                            :class="`text-right ${capitation.change_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`"
                        >
                            <p class="text-sm font-bold">
                                {{ capitation.change_percent >= 0 ? '+' : '' }}{{ capitation.change_percent }}%
                            </p>
                            <p class="text-sm text-slate-400">vs {{ capitation.prior_month }}</p>
                        </div>
                    </div>
                    <div class="text-sm text-slate-400 pt-1 border-t border-slate-100 dark:border-slate-700">
                        Prior month: {{ formatCurrency(capitation.prior_total) }}
                    </div>
                    <a href="/finance/dashboard" class="text-sm text-blue-600 dark:text-blue-400 hover:underline block">
                        View full Finance Dashboard
                    </a>
                </div>
            </template>
        </div>

        <ActionWidget
            title="Authorizations Expiring Soon"
            description="Service authorizations expiring within 30 days. Renew before expiry to avoid service gaps."
            :items="authorizationItems"
            emptyMessage="No authorizations expiring soon."
            viewAllHref="/finance/encounters"
            :loading="loading"
        />

        <!-- Enrollment Changes This Month — KPI stat grid, not a list -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Enrollment Changes This Month</h3>
            <template v-if="loading">
                <div class="space-y-2 animate-pulse">
                    <div v-for="i in 3" :key="i" class="h-8 bg-slate-100 dark:bg-slate-800 rounded" />
                </div>
            </template>
            <template v-else-if="!enrollmentChanges">
                <p class="text-sm text-gray-400 dark:text-slate-500 py-4 text-center">No data</p>
            </template>
            <template v-else>
                <div class="space-y-3">
                    <div class="grid grid-cols-3 gap-3">
                        <div class="text-center p-3 rounded-lg bg-green-50 dark:bg-green-950/60 border border-green-200 dark:border-green-800">
                            <p class="text-xl font-bold text-green-700 dark:text-green-300">{{ enrollmentChanges.enrolled_this_month }}</p>
                            <p class="text-sm text-green-600 dark:text-green-400 font-medium">Enrolled</p>
                        </div>
                        <div class="text-center p-3 rounded-lg bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800">
                            <p class="text-xl font-bold text-red-700 dark:text-red-300">{{ enrollmentChanges.disenrolled_this_month }}</p>
                            <p class="text-sm text-red-600 dark:text-red-400 font-medium">Disenrolled</p>
                        </div>
                        <div class="text-center p-3 rounded-lg bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800">
                            <p class="text-xl font-bold text-blue-700 dark:text-blue-300">{{ enrollmentChanges.total_enrolled }}</p>
                            <p class="text-sm text-blue-600 dark:text-blue-400 font-medium">Total</p>
                        </div>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400 text-center">
                        Net change:
                        <span :class="enrollmentChanges.net_change >= 0 ? 'text-green-600 dark:text-green-400 font-semibold' : 'text-red-600 dark:text-red-400 font-semibold'">
                            {{ enrollmentChanges.net_change >= 0 ? '+' : '' }}{{ enrollmentChanges.net_change }}
                        </span>
                    </p>
                </div>
            </template>
        </div>

        <ActionWidget
            title="Encounter Log"
            description="Encounter records pending 837P batch submission. These should be submitted to CMS within 180 days."
            :items="encounterItems"
            emptyMessage="No encounter data."
            viewAllHref="/finance/encounters"
            :loading="loading"
        />

        <ActionWidget
            title="Open Denials"
            description="Claim denials requiring action. Overdue items have passed their appeal deadline."
            :items="denialItems"
            emptyMessage="No open denials."
            viewAllHref="/finance/denials"
            :loading="loading"
        />

        <ActionWidget
            title="Revenue at Risk"
            description="Denied amounts by category. Focus on highest-value categories first."
            :items="revenueRiskItems"
            emptyMessage="No revenue at risk."
            viewAllHref="/finance/denials"
            :loading="loading"
        />

        <ActionWidget
            title="Recent Remittance"
            description="Latest ERA payment batches received from payers."
            :items="remittanceItems"
            emptyMessage="No recent remittance batches."
            viewAllHref="/finance/remittance"
            :loading="loading"
        />

        <!-- Phase 6 (MVP roadmap): CMS enrollment reconciliation -->
        <ActionWidget
            title="CMS Reconciliation"
            description="MMR discrepancies + TRR rejections. Finance must reconcile before close."
            :items="cmsReconciliationItems"
            emptyMessage="No CMS reconciliation activity."
            viewAllHref="/billing/reconciliation"
            :loading="loading"
        />

    </div>
</template>
