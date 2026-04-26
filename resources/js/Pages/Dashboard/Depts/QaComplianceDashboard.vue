<script setup lang="ts">
// ─── QaComplianceDashboard.vue ────────────────────────────────────────────────
// QA / Compliance department live dashboard. Used by QAPI (Quality Assessment /
// Performance Improvement) staff to monitor SDR (Significant Decline Report)
// timeliness, overdue assessments, unsigned notes, open incidents (RCA pending),
// overdue care plans, and recent hospitalizations. Drives 42 CFR §460.200 QAPI.
// Endpoints:
//   GET /dashboards/qa-compliance/metrics    → { sdr_compliance_rate, overdue_assessments_count, unsigned_notes_count, open_incidents_count, overdue_care_plans_count, hospitalizations_count }
//   GET /dashboards/qa-compliance/incidents  → { incidents[], open_count, rca_pending_count }
//   GET /dashboards/qa-compliance/docs       → { unsigned_notes[], unsigned_count, overdue_assessments[], overdue_assess_count }
//   GET /dashboards/qa-compliance/care-plans → { care_plans[], overdue_count }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const kpis = ref<any>(null)
const incidentsData = ref<any>(null)
const docsData = ref<any>(null)
const carePlansData = ref<any>(null)
const appealsData = ref<any>(null)
const sentinelRows = ref<any[]>([])
const cvRows = ref<any[]>([])
const roiRows = ref<any[]>([])
const tbData = ref<any>({ overdue_count: 0, due_soon_count: 0 })

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/qa-compliance/metrics'),
        axios.get('/dashboards/qa-compliance/incidents'),
        axios.get('/dashboards/qa-compliance/docs'),
        axios.get('/dashboards/qa-compliance/care-plans'),
        axios.get('/dashboards/qa-compliance/appeals'),
        axios.get('/dashboards/qa-compliance/sentinel-rollup'),
        axios.get('/dashboards/qa-compliance/critical-values-pending'),
        axios.get('/dashboards/qa-compliance/roi-due-soon'),
        axios.get('/dashboards/qa-compliance/tb-overdue'),
    ]).then(([r1, r2, r3, r4, r5, r6, r7, r8, r9]) => {
        kpis.value = r1.data
        incidentsData.value = r2.data
        docsData.value = r3.data
        carePlansData.value = r4.data
        appealsData.value = r5.data
        sentinelRows.value = r6.data.rows ?? []
        cvRows.value = r7.data.rows ?? []
        roiRows.value = r8.data.rows ?? []
        tbData.value = r9.data ?? { overdue_count: 0, due_soon_count: 0 }
    }).finally(() => loading.value = false)
})

const sentinelItems = computed<ActionItem[]>(() =>
    sentinelRows.value.map(i => ({
        label: `${i.participant?.name ?? '-'}`,
        sublabel: i.classified_at ? `Classified ${i.classified_at}` : undefined,
        badge: i.cms_overdue ? 'CMS OVERDUE' : i.rca_overdue ? 'RCA OVERDUE' : 'Active',
        badgeColor: (i.cms_overdue || i.rca_overdue)
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: i.href ?? '/compliance/sentinel-events',
    }))
)

const cvItems = computed<ActionItem[]>(() =>
    cvRows.value.map(c => ({
        label: `${c.participant?.name ?? '-'} — ${c.field_name}`,
        sublabel: `Value: ${c.value} · Deadline ${c.deadline_at ?? '-'}`,
        badge: c.overdue ? 'OVERDUE' : (c.severity ?? '-'),
        badgeColor: c.overdue
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: c.href ?? '/participants',
    }))
)

const roiItems = computed<ActionItem[]>(() =>
    roiRows.value.map(r => ({
        label: `${r.participant?.name ?? '-'}`,
        sublabel: r.due_by ? `Due ${r.due_by}` : undefined,
        badge: r.overdue ? 'OVERDUE' : `${r.days_remaining ?? '?'}d left`,
        badgeColor: r.overdue
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: r.href ?? '/compliance/roi',
    }))
)

function kpiColor(value: number, threshold: number): string {
    if (value === 0) return 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300'
    if (value <= threshold) return 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300'
    return 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300'
}

function sdrColor(value: number): string {
    if (value >= 95) return 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300'
    if (value >= 80) return 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300'
    return 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300'
}

const incidentItems = computed<ActionItem[]>(() =>
    (incidentsData.value?.incidents ?? []).map((i: any) => {
        const rcaPending = i.rca_required && !i.rca_completed
        return {
            label: `${i.participant?.name ?? 'N/A'} : ${(i.incident_type ?? '-').replace(/_/g, ' ')}`,
            sublabel: `${(i.status ?? '-').replace(/_/g, ' ')}${i.occurred_at ? ` | ${i.occurred_at}` : ''}`,
            badge: rcaPending ? 'RCA Due' : (i.status ?? '-').replace(/_/g, ' '),
            badgeColor: rcaPending
                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
            href: i.href ?? (i.participant?.id ? `/participants/${i.participant.id}` : '/qa/dashboard'),
        }
    })
)

const docItems = computed<ActionItem[]>(() => [
    ...(docsData.value?.unsigned_notes ?? []).map((n: any) => ({
        label: `${n.participant?.name ?? '-'} : ${(n.note_type ?? '-').replace(/_/g, ' ')}`,
        sublabel: (n.department ?? '-').replace(/_/g, ' '),
        badge: `${n.hours_old ?? 0}h`,
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: n.href ?? (n.participant?.id ? `/participants/${n.participant.id}` : '/clinical/notes'),
    })),
    ...(docsData.value?.overdue_assessments ?? []).map((a: any) => ({
        label: `${a.participant?.name ?? '-'} : ${(a.assessment_type ?? '-').replace(/_/g, ' ')}`,
        sublabel: a.next_due_date ?? undefined,
        badge: `${a.days_overdue ?? 0}d overdue`,
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/clinical/assessments'),
    })),
])

const carePlanItems = computed<ActionItem[]>(() =>
    (carePlansData.value?.care_plans ?? []).map((p: any) => ({
        label: p.participant?.name ?? '-',
        sublabel: (p.status ?? '-').replace(/_/g, ' '),
        badge: `${p.days_overdue ?? 0}d overdue`,
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        href: p.href ?? (p.participant?.id ? `/participants/${p.participant.id}` : '/clinical/care-plans'),
    }))
)

// Phase 1 (MVP roadmap): §460.122 appeals widget
const appealItems = computed<ActionItem[]>(() =>
    (appealsData.value?.appeals ?? []).map((a: any) => {
        const pct = a.window_pct ?? 0
        const badgeColor =
            a.overdue      ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' :
            pct >= 75      ? 'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300' :
            pct >= 50      ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' :
                             'bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300'
        const badge = a.overdue ? 'overdue' : a.type === 'expedited' ? `exp ${pct}%` : `${pct}%`
        return {
            label: a.participant?.name ?? '—',
            sublabel: `${(a.status ?? '').replace(/_/g, ' ')}${a.continuation_of_benefits ? ' · COB' : ''}`,
            badge,
            badgeColor,
            href: a.href ?? `/appeals/${a.id}`,
        }
    })
)
</script>

<template>
    <div class="space-y-6">

        <!-- KPI Card Row -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <template v-if="loading || !kpis">
                <div v-for="i in 6" :key="i" class="h-20 bg-slate-100 dark:bg-slate-800 rounded-xl animate-pulse" />
            </template>
            <template v-else>
                <div :class="`rounded-xl border p-3 ${sdrColor(kpis.sdr_compliance_rate ?? 0)}`">
                    <p class="text-sm font-semibold uppercase tracking-wide opacity-70">SDR Compliance</p>
                    <p class="text-2xl font-bold mt-1">{{ kpis.sdr_compliance_rate ?? 0 }}%</p>
                    <p class="text-sm mt-0.5 opacity-60">30-day rate</p>
                </div>
                <div :class="`rounded-xl border p-3 ${kpiColor(kpis.overdue_assessments_count ?? 0, 5)}`">
                    <p class="text-sm font-semibold uppercase tracking-wide opacity-70">Overdue Assessments</p>
                    <p class="text-2xl font-bold mt-1">{{ kpis.overdue_assessments_count ?? 0 }}</p>
                    <p class="text-sm mt-0.5 opacity-60">Past due date</p>
                </div>
                <div :class="`rounded-xl border p-3 ${kpiColor(kpis.unsigned_notes_count ?? 0, 3)}`">
                    <p class="text-sm font-semibold uppercase tracking-wide opacity-70">Unsigned Notes &gt;24h</p>
                    <p class="text-2xl font-bold mt-1">{{ kpis.unsigned_notes_count ?? 0 }}</p>
                    <p class="text-sm mt-0.5 opacity-60">Documentation gap</p>
                </div>
                <div :class="`rounded-xl border p-3 ${kpiColor(kpis.open_incidents_count ?? 0, 5)}`">
                    <p class="text-sm font-semibold uppercase tracking-wide opacity-70">Open Incidents</p>
                    <p class="text-2xl font-bold mt-1">{{ kpis.open_incidents_count ?? 0 }}</p>
                    <p class="text-sm mt-0.5 opacity-60">All statuses</p>
                </div>
                <div :class="`rounded-xl border p-3 ${kpiColor(kpis.overdue_care_plans_count ?? 0, 3)}`">
                    <p class="text-sm font-semibold uppercase tracking-wide opacity-70">Overdue Care Plans</p>
                    <p class="text-2xl font-bold mt-1">{{ kpis.overdue_care_plans_count ?? 0 }}</p>
                    <p class="text-sm mt-0.5 opacity-60">Review past due</p>
                </div>
                <div :class="`rounded-xl border p-3 ${kpiColor(kpis.hospitalizations_count ?? 0, 2)}`">
                    <p class="text-sm font-semibold uppercase tracking-wide opacity-70">Hospital/ER (Month)</p>
                    <p class="text-2xl font-bold mt-1">{{ kpis.hospitalizations_count ?? 0 }}</p>
                    <p class="text-sm mt-0.5 opacity-60">This calendar month</p>
                </div>
            </template>
        </div>

        <!-- Widget Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">
            <ActionWidget
                title="Open Incidents"
                description="Open incidents requiring review or RCA completion. Red = RCA required per CMS 42 CFR 460.136."
                :items="incidentItems"
                emptyMessage="No open incidents."
                viewAllHref="/qa/dashboard"
                :loading="loading"
            />

            <ActionWidget
                title="Documentation Compliance"
                description="Unsigned notes older than 24h and overdue assessments. QA monitors these for audit readiness."
                :items="docItems"
                emptyMessage="No documentation gaps."
                viewAllHref="/qa/dashboard"
                :loading="loading"
            />

            <ActionWidget
                title="Overdue Care Plans"
                description="Active care plans with overdue or upcoming review dates. Reviewed at IDT."
                :items="carePlanItems"
                emptyMessage="No overdue care plans."
                viewAllHref="/clinical/care-plans"
                :loading="loading"
            />

            <!-- Phase 1 (MVP roadmap): §460.122 appeals -->
            <ActionWidget
                title="Open Appeals"
                description="§460.122 participant appeals of service denials. Clock-aged; overdue first."
                :items="appealItems"
                emptyMessage="No open appeals."
                viewAllHref="/appeals"
                :loading="loading"
            />

            <!-- Phase I7 -->
            <ActionWidget
                :title="`Sentinel Events (${sentinelRows.length})`"
                description="Classified sentinel events in the last 30 days. Tracks CMS-5d + RCA-30d deadlines."
                :items="sentinelItems"
                emptyMessage="No classified sentinel events in last 30 days."
                viewAllHref="/compliance/sentinel-events"
                :loading="loading"
            />

            <ActionWidget
                :title="`Critical Values Pending (${cvRows.length})`"
                description="Unacknowledged critical value alerts, earliest deadline first."
                :items="cvItems"
                emptyMessage="No pending critical value acknowledgments."
                viewAllHref="/participants"
                :loading="loading"
            />

            <ActionWidget
                :title="`ROI Requests Due Soon (${roiRows.length})`"
                description="Open ROI requests with due date within 5 days or overdue."
                :items="roiItems"
                emptyMessage="No ROI requests due in the next 5 days."
                viewAllHref="/compliance/roi"
                :loading="loading"
            />

            <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 shadow-sm">
                <div class="flex items-baseline justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">TB Screening Cadence</h3>
                </div>
                <p class="text-xs text-gray-500 dark:text-slate-400 mb-3">§460.71 annual TB screening cadence status.</p>
                <div class="grid grid-cols-2 gap-3">
                    <div class="text-center p-3 rounded bg-red-50 dark:bg-red-900/30">
                        <div class="text-2xl font-bold text-red-700 dark:text-red-300">{{ tbData.overdue_count }}</div>
                        <div class="text-xs text-red-800 dark:text-red-300">Overdue</div>
                    </div>
                    <div class="text-center p-3 rounded bg-amber-50 dark:bg-amber-900/30">
                        <div class="text-2xl font-bold text-amber-700 dark:text-amber-300">{{ tbData.due_soon_count }}</div>
                        <div class="text-xs text-amber-800 dark:text-amber-300">Due &le; 30d</div>
                    </div>
                </div>
                <a
                    href="/compliance/tb-screening"
                    class="mt-3 inline-block text-xs text-blue-600 dark:text-blue-400 hover:underline"
                >View details →</a>
            </div>
        </div>

    </div>
</template>
