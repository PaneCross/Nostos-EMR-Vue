<script setup lang="ts">
// ─── User / My Team ──────────────────────────────────────────────────────────
// D7 : supervisor view of direct reports' credential status. Read-only roll-up.
// Each report shows aggregate counts + an overall severity indicator.
// ─────────────────────────────────────────────────────────────────────────────
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { UserGroupIcon, CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface Report {
    id: number
    name: string
    email: string
    department: string
    job_title: string | null
    on_file: number
    expiring_count: number
    expired_count: number
    invalid_count: number
    pending_count: number
    missing_count: number
    severity: 'red' | 'amber' | 'green'
}
defineProps<{ reports: Report[] }>()
</script>

<template>
    <AppShell>
        <Head title="My Team" />

        <div class="max-w-5xl mx-auto px-6 py-8">
            <div class="flex items-start gap-3 mb-6">
                <UserGroupIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                <div>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">My Team — Credential Status</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Read-only view of your direct reports' credential compliance. {{ reports.length }} direct report(s).
                    </p>
                </div>
            </div>

            <div v-if="reports.length === 0" class="text-center py-12 text-slate-500 dark:text-slate-400">
                You don't have any direct reports configured. Ask IT Admin to set you as the supervisor of relevant staff.
            </div>

            <div v-else class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800 text-xs text-slate-700 dark:text-slate-200">
                        <tr>
                            <th class="text-left px-4 py-2 font-medium">Staff</th>
                            <th class="text-left px-4 py-2 font-medium">Job Title</th>
                            <th class="text-center px-3 py-2 font-medium">On File</th>
                            <th class="text-center px-3 py-2 font-medium">Expiring</th>
                            <th class="text-center px-3 py-2 font-medium">Expired</th>
                            <th class="text-center px-3 py-2 font-medium">Invalid</th>
                            <th class="text-center px-3 py-2 font-medium">Pending</th>
                            <th class="text-center px-3 py-2 font-medium">Missing</th>
                            <th class="text-center px-3 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="r in reports" :key="r.id"
                            :class="r.severity === 'red' ? 'bg-rose-50/50 dark:bg-rose-950/20' : r.severity === 'amber' ? 'bg-amber-50/50 dark:bg-amber-950/20' : ''">
                            <td class="px-4 py-2">
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ r.name }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ r.department.replace('_', ' ') }}</div>
                            </td>
                            <td class="px-4 py-2 text-slate-600 dark:text-slate-300 text-xs">{{ r.job_title ?? '-' }}</td>
                            <td class="px-3 py-2 text-center tabular-nums">{{ r.on_file }}</td>
                            <td class="px-3 py-2 text-center tabular-nums" :class="r.expiring_count > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-slate-400'">{{ r.expiring_count }}</td>
                            <td class="px-3 py-2 text-center tabular-nums" :class="r.expired_count > 0 ? 'text-rose-600 dark:text-rose-400 font-semibold' : 'text-slate-400'">{{ r.expired_count }}</td>
                            <td class="px-3 py-2 text-center tabular-nums" :class="r.invalid_count > 0 ? 'text-rose-700 dark:text-rose-300 font-semibold' : 'text-slate-400'">{{ r.invalid_count }}</td>
                            <td class="px-3 py-2 text-center tabular-nums" :class="r.pending_count > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-slate-400'">{{ r.pending_count }}</td>
                            <td class="px-3 py-2 text-center tabular-nums" :class="r.missing_count > 0 ? 'text-rose-700 dark:text-rose-300 font-semibold' : 'text-slate-400'">{{ r.missing_count }}</td>
                            <td class="px-3 py-2 text-center">
                                <CheckCircleIcon v-if="r.severity === 'green'" class="w-5 h-5 text-emerald-500 mx-auto" />
                                <ExclamationTriangleIcon v-else-if="r.severity === 'amber'" class="w-5 h-5 text-amber-500 mx-auto" />
                                <ExclamationTriangleIcon v-else class="w-5 h-5 text-rose-500 mx-auto" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400 mt-4">
                You receive credential reminders for these staff at the 14-day warning and overdue escalation steps.
                If a report's credential needs your direct attention, contact IT Admin or the staff member directly.
            </p>
        </div>
    </AppShell>
</template>
