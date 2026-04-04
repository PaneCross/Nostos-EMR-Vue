<script setup lang="ts">
// ─── DietaryDashboard.vue ─────────────────────────────────────────────────────
// Dietary department live dashboard.
// Endpoints:
//   GET /dashboards/dietary/assessments  → { overdue_assessments[] }
//   GET /dashboards/dietary/alerts       → { alerts[] }
//   GET /dashboards/dietary/allergies    → { allergies[] }
//   GET /dashboards/dietary/docs         → { unsigned_notes[] }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const overdueAssessments = ref<any[]>([])
const alerts = ref<any[]>([])
const allergies = ref<any[]>([])
const unsignedNotes = ref<any[]>([])

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/dietary/assessments'),
        axios.get('/dashboards/dietary/alerts'),
        axios.get('/dashboards/dietary/allergies'),
        axios.get('/dashboards/dietary/docs'),
    ]).then(([r1, r2, r3, r4]) => {
        overdueAssessments.value = r1.data.overdue_assessments ?? []
        alerts.value = r2.data.alerts ?? r2.data ?? []
        allergies.value = r3.data.allergies ?? []
        unsignedNotes.value = r4.data.unsigned_notes ?? []
    }).finally(() => loading.value = false)
})

const assessmentItems = computed<ActionItem[]>(() =>
    overdueAssessments.value.map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label ?? '-'}`,
        sublabel: a.next_due_date ? `Due ${a.next_due_date}` : undefined,
        badge: `${a.days_overdue ?? 0}d overdue`,
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
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

const allergyItems = computed<ActionItem[]>(() =>
    allergies.value.map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.allergen_name ?? '-'}`,
        sublabel: a.reaction_description ?? undefined,
        badge: a.severity === 'life-threatening' ? 'Life-Threatening'
            : a.severity === 'severe' ? 'Severe'
            : a.severity === 'moderate' ? 'Moderate'
            : a.severity === 'mild' ? 'Mild'
            : (a.severity ?? '-'),
        badgeColor: a.severity === 'life-threatening'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : a.severity === 'severe'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : a.severity === 'moderate'
            ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
            : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
    }))
)

const noteItems = computed<ActionItem[]>(() =>
    unsignedNotes.value.map(n => ({
        label: `${n.participant?.name ?? '-'} — ${n.type_label ?? '-'}`,
        sublabel: n.visit_date ?? n.created_at ?? undefined,
        badge: n.author ? undefined : 'Unassigned',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Overdue Nutrition Assessments"
            description="Nutritional assessments past their due date."
            :items="assessmentItems"
            emptyMessage="No overdue nutrition assessments."
            viewAllHref="/clinical/assessments"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="Alerts requiring dietary attention."
            :items="alertItems"
            emptyMessage="No active alerts."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Food Allergies and Intolerances"
            description="Known food allergies and dietary restrictions."
            :items="allergyItems"
            emptyMessage="No food allergies on record."
            viewAllHref="/participants"
            :loading="loading"
        />

        <ActionWidget
            title="Unsigned Notes"
            description="Dietary notes pending provider signature."
            :items="noteItems"
            emptyMessage="No unsigned notes."
            viewAllHref="/clinical/notes"
            :loading="loading"
        />
    </div>
</template>
