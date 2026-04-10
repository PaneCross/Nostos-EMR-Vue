<script setup lang="ts">
// ─── EnrollmentDashboard.vue ──────────────────────────────────────────────────
// Enrollment department live dashboard.
// Endpoints:
//   GET /dashboards/enrollment/pipeline            → { pipeline[], total_active, declined_this_month, withdrawn_this_month }
//   GET /dashboards/enrollment/eligibility-pending → { referrals[], count }
//   GET /dashboards/enrollment/disenrollments      → { participants[], count }
//   GET /dashboards/enrollment/new-referrals       → { referrals[], week_count }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const pipeline = ref<any>(null)
const eligibilityReferrals = ref<any[]>([])
const disenrollmentParticipants = ref<any[]>([])
const newReferrals = ref<any[]>([])

const COLUMN_COLORS: Record<string, string> = {
    new:                 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    intake_scheduled:    'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    intake_in_progress:  'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300',
    intake_complete:     'bg-teal-100 text-teal-700 dark:text-teal-300',
    eligibility_pending: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    pending_enrollment:  'bg-orange-100 text-orange-700',
    enrolled:            'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
}

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/enrollment/pipeline'),
        axios.get('/dashboards/enrollment/eligibility-pending'),
        axios.get('/dashboards/enrollment/disenrollments'),
        axios.get('/dashboards/enrollment/new-referrals'),
    ]).then(([r1, r2, r3, r4]) => {
        pipeline.value = r1.data
        eligibilityReferrals.value = r2.data.referrals ?? []
        disenrollmentParticipants.value = r3.data.participants ?? []
        newReferrals.value = r4.data.referrals ?? []
    }).finally(() => loading.value = false)
})

const eligibilityItems = computed<ActionItem[]>(() =>
    eligibilityReferrals.value.map(r => ({
        label: r.referred_name ?? '-',
        sublabel: [r.source, r.assigned_to].filter(Boolean).join(' | ') || undefined,
        badge: `${r.days_pending ?? 0}d`,
        badgeColor: (r.days_pending ?? 0) > 30
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    }))
)

const disenrollItems = computed<ActionItem[]>(() =>
    disenrollmentParticipants.value.map(p => ({
        label: `${p.name ?? '-'} (${p.mrn ?? '-'})`,
        sublabel: [p.disenrollment_date, p.disenrollment_reason].filter(Boolean).join(' | ') || undefined,
        badge: `${p.days_until ?? 0}d`,
        badgeColor: (p.days_until ?? 0) <= 7
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    }))
)

const newReferralItems = computed<ActionItem[]>(() =>
    newReferrals.value.map(r => ({
        label: r.referred_name ?? '-',
        sublabel: [r.source, r.created_at].filter(Boolean).join(' | ') || undefined,
        badge: r.status_label ?? r.status ?? '-',
        badgeColor: COLUMN_COLORS[r.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Pipeline Summary — KPI stat grid, not a list of clickable items -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Referral Pipeline</h3>
            <template v-if="loading">
                <div class="space-y-2 animate-pulse">
                    <div v-for="i in 3" :key="i" class="h-8 bg-slate-100 dark:bg-slate-800 rounded" />
                </div>
            </template>
            <template v-else-if="!pipeline">
                <p class="text-xs text-gray-400 dark:text-slate-500 py-4 text-center">No pipeline data</p>
            </template>
            <template v-else>
                <div class="space-y-2">
                    <div class="flex flex-wrap gap-1.5">
                        <a
                            v-for="col in (pipeline.pipeline ?? [])"
                            :key="col.status"
                            :href="`/enrollment?status=${col.status}`"
                            :class="`flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs ${COLUMN_COLORS[col.status] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400'} hover:opacity-80 transition-opacity`"
                        >
                            <span class="font-bold">{{ col.count }}</span>
                            <span>{{ col.status_label }}</span>
                        </a>
                    </div>
                    <div class="flex gap-4 pt-1 border-t border-slate-100 dark:border-slate-700 text-[10px] text-slate-400">
                        <span>{{ pipeline.declined_this_month ?? 0 }} declined this month</span>
                        <span>{{ pipeline.withdrawn_this_month ?? 0 }} withdrawn this month</span>
                    </div>
                    <a href="/enrollment" class="text-xs text-blue-600 dark:text-blue-400 hover:underline block">
                        View full Kanban board
                    </a>
                </div>
            </template>
        </div>

        <ActionWidget
            title="Pending Eligibility Verification"
            description="Referrals awaiting Medicare/Medicaid eligibility verification. Enrollment cannot proceed until eligibility is confirmed."
            :items="eligibilityItems"
            emptyMessage="No referrals awaiting eligibility."
            viewAllHref="/enrollment"
            :loading="loading"
        />

        <ActionWidget
            title="Upcoming Disenrollments (30 days)"
            description="Participants with pending or recent disenrollment actions requiring follow-up."
            :items="disenrollItems"
            emptyMessage="No upcoming disenrollments."
            viewAllHref="/enrollment"
            :loading="loading"
        />

        <ActionWidget
            title="New Referrals This Week"
            description="Referrals received in the last 7 days awaiting intake scheduling."
            :items="newReferralItems"
            emptyMessage="No new referrals this week."
            viewAllHref="/enrollment"
            :loading="loading"
        />
    </div>
</template>
