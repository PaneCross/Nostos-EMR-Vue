<script setup lang="ts">
// ─── ItAdminDashboard.vue ─────────────────────────────────────────────────────
// IT Admin department live dashboard.
// Endpoints:
//   GET /dashboards/it-admin/users        → { users[] }
//   GET /dashboards/it-admin/integrations → { integrations[] }
//   GET /dashboards/it-admin/audit        → { entries[] }
//   GET /dashboards/it-admin/config       → { config{} }
//   GET /dashboards/it-admin/break-glass  → { events[], unreviewed_count }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const users = ref<any[]>([])
const integrations = ref<any[]>([])
const auditEntries = ref<any[]>([])
const breakGlassEvents = ref<any[]>([])
const unreviewedCount = ref(0)

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/it-admin/users'),
        axios.get('/dashboards/it-admin/integrations'),
        axios.get('/dashboards/it-admin/audit'),
        axios.get('/dashboards/it-admin/break-glass'),
    ]).then(([r1, r2, r3, r4]) => {
        users.value = r1.data.users ?? []
        integrations.value = r2.data.integrations ?? []
        auditEntries.value = r3.data.entries ?? r3.data.audit ?? []
        breakGlassEvents.value = r4.data.events ?? []
        unreviewedCount.value = r4.data.unreviewed_count ?? 0
    }).catch(() => {
        // Non-blocking — widgets will show empty state
    }).finally(() => loading.value = false)
})

const userItems = computed<ActionItem[]>(() =>
    users.value.map(u => ({
        label: `${u.first_name ?? ''} ${u.last_name ?? ''}`.trim() || '-',
        sublabel: [u.department_label, `Last: ${u.last_login_at ?? 'Never'}`].filter(Boolean).join(' | ') || undefined,
        badge: u.is_active ? 'Active' : 'Inactive',
        badgeColor: u.is_active
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    }))
)

const integrationItems = computed<ActionItem[]>(() =>
    integrations.value.map(i => ({
        label: i.connector_type ?? '-',
        sublabel: [i.direction, i.created_at].filter(Boolean).join(' | ') || undefined,
        badge: i.status ?? '-',
        badgeColor: i.status === 'processed'
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : i.status === 'failed'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    }))
)

const auditItems = computed<ActionItem[]>(() =>
    auditEntries.value.map(e => ({
        label: e.action ?? '-',
        sublabel: [e.user_name, e.created_at].filter(Boolean).join(' | ') || undefined,
        badge: e.resource_type ?? undefined,
        badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        href: '/it-admin/audit',
    }))
)

const breakGlassItems = computed<ActionItem[]>(() =>
    breakGlassEvents.value.map(e => ({
        label: `${e.participant?.name ?? '-'} — ${e.user?.name ?? '-'}`,
        sublabel: [e.access_granted_at, (e.justification ?? '').slice(0, 40)].filter(Boolean).join(' | ') || undefined,
        badge: e.acknowledged_at ? 'Reviewed' : 'Unreviewed',
        badgeColor: e.acknowledged_at
            ? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
            : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    }))
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Recent User Activity"
            description="User accounts and their recent login activity."
            :items="userItems"
            emptyMessage="No user activity found."
            viewAllHref="/it-admin/users"
            :loading="loading"
        />

        <ActionWidget
            title="Integration Status"
            description="Recent inbound and outbound integration events."
            :items="integrationItems"
            emptyMessage="No integration events found."
            viewAllHref="/it-admin/integrations"
            :loading="loading"
        />

        <ActionWidget
            title="Recent Audit Log"
            description="Recent system audit events."
            :items="auditItems"
            emptyMessage="No audit entries found."
            viewAllHref="/it-admin/audit"
            :loading="loading"
            class="lg:col-span-2"
        />

        <ActionWidget
            :title="`Break-Glass Events (${unreviewedCount} Unreviewed)`"
            description="Emergency record access events requiring review."
            :items="breakGlassItems"
            emptyMessage="No break-glass events on record."
            viewAllHref="/it-admin/break-glass"
            :loading="loading"
            class="lg:col-span-2"
        />
    </div>
</template>
