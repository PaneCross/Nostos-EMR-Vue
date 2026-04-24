<script setup lang="ts">
// ─── DietaryDashboard.vue ─────────────────────────────────────────────────────
// Dietary department live dashboard.
// Endpoints:
//   GET /dashboards/dietary/assessments   → { overdue[], due_soon[], overdue_count, due_soon_count }
//   GET /dashboards/dietary/goals         → { goals[] }
//   GET /dashboards/dietary/restrictions  → { counts_by_type, critical_food_allergies[] }
//   GET /dashboards/dietary/sdrs          → { sdrs[], overdue_count, open_count }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const overdueAssessments = ref<any[]>([])
const dueSoonAssessments = ref<any[]>([])
const goals = ref<any[]>([])
const criticalFoodAllergies = ref<any[]>([])
const sdrs = ref<any[]>([])
const ordersByType = ref<{ rows: any[]; total: number }>({ rows: [], total: 0 })
const foodPrepCandidates = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/dietary/assessments'),
        axios.get('/dashboards/dietary/goals'),
        axios.get('/dashboards/dietary/restrictions'),
        axios.get('/dashboards/dietary/sdrs'),
        axios.get('/dashboards/dietary/orders-by-type'),
        axios.get('/dashboards/dietary/iadl-food-prep-candidates'),
    ]).then(([r1, r2, r3, r4, r5, r6]) => {
        overdueAssessments.value = r1.data.overdue ?? []
        dueSoonAssessments.value = r1.data.due_soon ?? []
        goals.value = r2.data.goals ?? []
        criticalFoodAllergies.value = r3.data.critical_food_allergies ?? []
        sdrs.value = r4.data.sdrs ?? []
        ordersByType.value = r5.data ?? { rows: [], total: 0 }
        foodPrepCandidates.value = r6.data.rows ?? []
    }).finally(() => loading.value = false)
})

const foodPrepItems = computed<ActionItem[]>(() =>
    foodPrepCandidates.value.map(c => ({
        label: `${c.participant?.name ?? '-'}`,
        sublabel: c.interpretation ?? undefined,
        badge: 'Consult',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: c.href ?? '/participants',
    }))
)

const assessmentItems = computed<ActionItem[]>(() => [
    ...overdueAssessments.value.map((a: any) => ({
        label: `${a.participant?.name ?? '-'} - Nutritional Assessment`,
        sublabel: a.next_due_date ? `Due ${a.next_due_date}` : undefined,
        badge: a.days_overdue != null ? `${a.days_overdue}d overdue` : 'Overdue',
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' as const,
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/clinical/assessments'),
    })),
    ...dueSoonAssessments.value.map((a: any) => ({
        label: `${a.participant?.name ?? '-'} - Nutritional Assessment`,
        sublabel: undefined,
        badge: a.next_due_date ? `Due ${a.next_due_date}` : 'Due soon',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' as const,
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/clinical/assessments'),
    })),
])

const goalItems = computed<ActionItem[]>(() =>
    goals.value.map(g => ({
        label: `${g.participant?.name ?? '-'} - Dietary Goal`,
        sublabel: g.goal_description ?? undefined,
        badge: g.target_date ? `Due ${g.target_date}` : undefined,
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        href: g.href ?? (g.participant?.id ? `/participants/${g.participant.id}` : '/clinical/care-plans'),
    }))
)

const restrictionItems = computed<ActionItem[]>(() =>
    criticalFoodAllergies.value.map(a => ({
        label: `${a.participant?.name ?? '-'} - ${a.allergen ?? '-'}`,
        sublabel: a.reaction ?? undefined,
        badge: 'Life-threatening',
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        href: a.href ?? (a.participant?.id ? `/participants/${a.participant.id}` : '/participants'),
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
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">
        <ActionWidget
            title="Overdue Assessments"
            description="Nutritional assessments past their due date. Required annually per care plan."
            :items="assessmentItems"
            emptyMessage="No overdue or upcoming assessments."
            viewAllHref="/clinical/assessments"
            :loading="loading"
        />

        <ActionWidget
            title="Goals Due"
            description="Dietary care plan goals with target dates within 14 days or past due."
            :items="goalItems"
            emptyMessage="No active dietary goals due soon."
            viewAllHref="/clinical/care-plans"
            :loading="loading"
        />

        <ActionWidget
            title="Dietary Restrictions"
            description="Active food allergies and dietary restrictions across enrolled participants."
            :items="restrictionItems"
            emptyMessage="No critical food allergies on record."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Overdue SDRs"
            description="SDRs assigned to dietary past their 72-hour deadline."
            :items="sdrItems"
            emptyMessage="No open SDRs."
            viewAllHref="/sdrs"
            :loading="loading"
        />

        <ActionWidget
            :title="`IADL Food-Prep Candidates (${foodPrepCandidates.length})`"
            description="Participants with recent IADL food-prep impairment — dietary consult candidates."
            :items="foodPrepItems"
            emptyMessage="No food-prep impairments on latest IADL records."
            viewAllHref="/participants"
            :loading="loading"
        />

        <div class="rounded-lg border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 shadow-sm">
            <div class="flex items-baseline justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Active Orders by Diet Type</h3>
                <span class="text-xs text-gray-500 dark:text-slate-400">Total: {{ ordersByType.total }}</span>
            </div>
            <ul v-if="ordersByType.rows?.length" class="text-sm space-y-1">
                <li
                    v-for="d in ordersByType.rows"
                    :key="d.diet_type"
                    class="flex justify-between border-b border-gray-100 dark:border-slate-700 pb-1"
                >
                    <span class="text-gray-700 dark:text-slate-200">{{ d.diet_type }}</span>
                    <span class="font-semibold text-gray-900 dark:text-slate-100">{{ d.count }}</span>
                </li>
            </ul>
            <p v-else class="text-sm text-gray-500 dark:text-slate-400">No active dietary orders.</p>
            <a
                href="/dietary/roster"
                class="mt-3 inline-block text-xs text-blue-600 dark:text-blue-400 hover:underline"
            >View roster →</a>
        </div>
    </div>
</template>
