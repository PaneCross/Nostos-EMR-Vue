<script setup lang="ts">
// Phase I1. TB screening compliance universe (42 CFR §460.71). Inertia branch
// of the existing C2a JSON endpoint.
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'

interface Row {
    id: number
    mrn: string
    name: string
    latest_type: string | null
    latest_result: string | null
    performed_date: string | null
    next_due_date: string | null
    days_until_due: number | null
    status: 'current' | 'due_60' | 'due_30' | 'due_today' | 'overdue' | 'missing'
    href: string
}

interface Summary {
    count_total: number
    count_current: number
    count_due_60: number
    count_overdue: number
    count_missing: number
    count_positive: number
}

const props = defineProps<{ rows: Row[]; summary: Summary }>()
const filter = ref<'all'|'current'|'due_60'|'overdue'|'missing'>('all')
const filtered = computed(() => {
    switch (filter.value) {
        case 'current':  return props.rows.filter(r => r.status === 'current')
        case 'due_60':   return props.rows.filter(r => ['due_60','due_30','due_today'].includes(r.status))
        case 'overdue':  return props.rows.filter(r => r.status === 'overdue')
        case 'missing':  return props.rows.filter(r => r.status === 'missing')
        default: return props.rows
    }
})

function fmt(d: string | null): string {
    if (!d) return '—'
    return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

const STATUS_CLASS: Record<string, string> = {
    current:   'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300',
    due_60:    'bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300',
    due_30:    'bg-amber-200 dark:bg-amber-800/50 text-amber-800 dark:text-amber-200',
    due_today: 'bg-orange-100 dark:bg-orange-900/50 text-orange-700 dark:text-orange-300',
    overdue:   'bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300',
    missing:   'bg-red-200 dark:bg-red-800/50 text-red-800 dark:text-red-200',
}
</script>

<template>
    <AppShell title="TB Screening">
        <Head title="TB Screening — Compliance" />
        <div class="max-w-7xl mx-auto p-6 space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">TB Screening — Enrolled Roster</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">42 CFR §460.71 — annual TB screening required for every enrolled participant.</p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <button @click="filter = 'all'"
                    :class="['text-left p-3 rounded-xl border', filter === 'all' ? 'border-blue-500 ring-1 ring-blue-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-slate-500">Enrolled total</div>
                    <div class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ summary.count_total }}</div>
                </button>
                <button @click="filter = 'current'"
                    :class="['text-left p-3 rounded-xl border', filter === 'current' ? 'border-emerald-500 ring-1 ring-emerald-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-emerald-600 dark:text-emerald-400">Current</div>
                    <div class="text-2xl font-semibold text-emerald-700 dark:text-emerald-300">{{ summary.count_current }}</div>
                </button>
                <button @click="filter = 'due_60'"
                    :class="['text-left p-3 rounded-xl border', filter === 'due_60' ? 'border-amber-500 ring-1 ring-amber-500' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-amber-600 dark:text-amber-400">Due ≤60d</div>
                    <div class="text-2xl font-semibold text-amber-700 dark:text-amber-300">{{ summary.count_due_60 }}</div>
                </button>
                <button @click="filter = 'overdue'"
                    :class="['text-left p-3 rounded-xl border', filter === 'overdue' ? 'border-red-600 ring-1 ring-red-600' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-600 dark:text-red-400">Overdue</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_overdue }}</div>
                </button>
                <button @click="filter = 'missing'"
                    :class="['text-left p-3 rounded-xl border', filter === 'missing' ? 'border-red-700 ring-1 ring-red-700' : 'border-gray-200 dark:border-slate-700', 'bg-white dark:bg-slate-800']">
                    <div class="text-xs text-red-700 dark:text-red-400">Missing</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300">{{ summary.count_missing }}</div>
                </button>
                <div class="p-3 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <div class="text-xs text-slate-500">Positive history</div>
                    <div class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ summary.count_positive }}</div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900 text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-left">Participant</th>
                            <th class="px-3 py-2 text-left">Latest type</th>
                            <th class="px-3 py-2 text-left">Latest result</th>
                            <th class="px-3 py-2 text-left">Performed</th>
                            <th class="px-3 py-2 text-left">Next due</th>
                            <th class="px-3 py-2 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="filtered.length === 0">
                            <td colspan="6" class="px-3 py-6 text-center text-slate-400">No rows match.</td>
                        </tr>
                        <tr v-for="r in filtered" :key="r.id" class="border-t border-gray-100 dark:border-slate-700">
                            <td class="px-3 py-2">
                                <Link :href="r.href" class="text-blue-600 dark:text-blue-400 hover:underline">{{ r.name }}</Link>
                                <div class="text-xs text-slate-500">{{ r.mrn }}</div>
                            </td>
                            <td class="px-3 py-2 text-slate-700 dark:text-slate-200">{{ r.latest_type ?? '—' }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ r.latest_result ?? '—' }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ fmt(r.performed_date) }}</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ fmt(r.next_due_date) }}</td>
                            <td class="px-3 py-2">
                                <span :class="['inline-flex px-2 py-0.5 rounded text-xs', STATUS_CLASS[r.status]]">{{ r.status }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
