<script setup lang="ts">
// ─── Compliance/SentinelEvents.vue ───────────────────────────────────────────
// Phase B3. Surveyor-ready 12-month sentinel events pull. 42 CFR §460.136.
// Dual-deadline tracking: CMS 5-day + RCA 30-day.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Row {
    id: number
    participant: { id: number | null; mrn: string | null; name: string | null }
    incident_type: string
    incident_type_label: string
    occurred_at: string | null
    description: string | null
    status: string
    classified_at: string | null
    classified_by: string | null
    classification_reason: string | null
    cms_deadline: string | null
    cms_notification_sent_at: string | null
    cms_deadline_missed: boolean
    rca_deadline: string | null
    rca_completed_at: string | null
    rca_completed_by: string | null
    rca_deadline_missed: boolean
    href: string
}

interface Summary {
    count_total: number
    count_cms_missed: number
    count_rca_missed: number
    count_rca_pending: number
    count_cms_satisfied: number
    window_start: string
    window_end: string
}

const props = defineProps<{ rows: Row[]; summary: Summary }>()

const filter = ref<'all'|'cms_missed'|'rca_missed'|'rca_pending'>('all')
const filtered = computed(() => {
    switch (filter.value) {
        case 'cms_missed':  return props.rows.filter(r => r.cms_deadline_missed)
        case 'rca_missed':  return props.rows.filter(r => r.rca_deadline_missed)
        case 'rca_pending': return props.rows.filter(r => !r.rca_completed_at)
        default:            return props.rows
    }
})

function fmt(ts: string | null): string {
    if (!ts) return '—'
    return new Date(ts).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}
</script>

<template>
    <AppShell title="Sentinel Events">
        <Head title="Sentinel Events — Compliance" />
        <div class="max-w-7xl mx-auto p-6 space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Sentinel Events — 12-Month Universe</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    42 CFR §460.136. Window:
                    {{ fmt(summary.window_start) }} → {{ fmt(summary.window_end) }}.
                    Dual deadlines tracked per event: CMS 5-day + RCA 30-day.
                </p>
            </div>

            <!-- KPI -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <button @click="filter = 'all'"
                    :class="['text-left p-3 rounded-xl border', filter === 'all' ? 'border-blue-500 ring-1 ring-blue-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-slate-500">Total</div>
                    <div class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ summary.count_total }}</div>
                </button>
                <button @click="filter = 'cms_missed'"
                    :class="['text-left p-3 rounded-xl border', filter === 'cms_missed' ? 'border-red-500 ring-1 ring-red-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">CMS deadline missed</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_cms_missed }}</div>
                </button>
                <button @click="filter = 'rca_missed'"
                    :class="['text-left p-3 rounded-xl border', filter === 'rca_missed' ? 'border-red-600 ring-1 ring-red-600' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">RCA deadline missed</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_rca_missed }}</div>
                </button>
                <button @click="filter = 'rca_pending'"
                    :class="['text-left p-3 rounded-xl border', filter === 'rca_pending' ? 'border-amber-500 ring-1 ring-amber-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-amber-600 dark:text-amber-400">RCA pending</div>
                    <div class="text-2xl font-semibold text-amber-700 dark:text-amber-300">{{ summary.count_rca_pending }}</div>
                </button>
                <div class="p-3 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <div class="text-xs text-emerald-600 dark:text-emerald-400">CMS notification sent</div>
                    <div class="text-2xl font-semibold text-emerald-700 dark:text-emerald-300">{{ summary.count_cms_satisfied }}</div>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-left">#</th>
                            <th class="px-3 py-2 text-left">Participant</th>
                            <th class="px-3 py-2 text-left">Type</th>
                            <th class="px-3 py-2 text-left">Classified</th>
                            <th class="px-3 py-2 text-left">CMS deadline</th>
                            <th class="px-3 py-2 text-left">CMS sent</th>
                            <th class="px-3 py-2 text-left">RCA deadline</th>
                            <th class="px-3 py-2 text-left">RCA done</th>
                            <th class="px-3 py-2 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="filtered.length === 0">
                            <td colspan="9" class="px-3 py-6 text-center text-slate-400">No sentinel events match.</td>
                        </tr>
                        <tr v-for="r in filtered" :key="r.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2">
                                <Link :href="r.href" class="text-blue-600 dark:text-blue-400 hover:underline">#{{ r.id }}</Link>
                            </td>
                            <td class="px-3 py-2">
                                <Link v-if="r.participant.id" :href="`/participants/${r.participant.id}`" class="text-blue-600 dark:text-blue-400 hover:underline">
                                    {{ r.participant.name }}
                                </Link>
                                <span v-else>—</span>
                                <div class="text-xs text-slate-500">{{ r.participant.mrn }}</div>
                            </td>
                            <td class="px-3 py-2 text-slate-700 dark:text-slate-200">{{ r.incident_type_label }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">
                                {{ fmt(r.classified_at) }}
                                <div class="text-xs text-slate-500">{{ r.classified_by }}</div>
                            </td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ fmt(r.cms_deadline) }}</td>
                            <td class="px-3 py-2">
                                <span v-if="r.cms_notification_sent_at" class="text-emerald-600 dark:text-emerald-400">{{ fmt(r.cms_notification_sent_at) }}</span>
                                <span v-else-if="r.cms_deadline_missed" class="inline-flex px-2 py-0.5 rounded text-xs bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">MISSED</span>
                                <span v-else class="text-xs text-amber-600 dark:text-amber-400">pending</span>
                            </td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ fmt(r.rca_deadline) }}</td>
                            <td class="px-3 py-2">
                                <span v-if="r.rca_completed_at" class="text-emerald-600 dark:text-emerald-400">{{ fmt(r.rca_completed_at) }}</span>
                                <span v-else-if="r.rca_deadline_missed" class="inline-flex px-2 py-0.5 rounded text-xs bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">MISSED</span>
                                <span v-else class="text-xs text-amber-600 dark:text-amber-400">pending</span>
                            </td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ r.status }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
