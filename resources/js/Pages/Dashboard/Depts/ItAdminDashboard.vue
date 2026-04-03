<script setup lang="ts">
// ─── ItAdminDashboard.vue ─────────────────────────────────────────────────────
// IT Admin department live dashboard.
// Endpoints:
//   GET /dashboards/it-admin/users        → { users[] }
//   GET /dashboards/it-admin/integrations → { integrations[] }
//   GET /dashboards/it-admin/security     → { items[] }
//   GET /dashboards/it-admin/alerts       → { alerts[] }
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
const securityItems = ref<any[]>([])
const alerts = ref<any[]>([])
const breakGlassEvents = ref<any[]>([])
const unreviewedCount = ref(0)

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/it-admin/users'),
        axios.get('/dashboards/it-admin/integrations'),
        axios.get('/dashboards/it-admin/security'),
        axios.get('/dashboards/it-admin/alerts'),
        axios.get('/dashboards/it-admin/break-glass'),
    ])
        .then(([r1, r2, r3, r4, r5]) => {
            users.value = r1.data.users ?? []
            integrations.value = r2.data.integrations ?? []
            securityItems.value = r3.data.items ?? []
            alerts.value = r4.data.alerts ?? r4.data ?? []
            breakGlassEvents.value = r5.data.events ?? []
            unreviewedCount.value = r5.data.unreviewed_count ?? 0
        })
        .finally(() => (loading.value = false))
})

const userItems = computed<ActionItem[]>(() =>
    users.value.map((u) => ({
        label: `${u.first_name ?? ''} ${u.last_name ?? ''}`.trim() || '-',
        sublabel:
            [u.department_label, `Last: ${u.last_login_at ?? 'Never'}`]
                .filter(Boolean)
                .join(' | ') || undefined,
        badge: u.is_active ? 'Active' : 'Inactive',
        badgeColor: u.is_active
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    })),
)

const integrationItems = computed<ActionItem[]>(() =>
    integrations.value.map((i) => ({
        label: i.connector_type ?? '-',
        sublabel: [i.direction, i.created_at].filter(Boolean).join(' | ') || undefined,
        badge: i.status ?? '-',
        badgeColor:
            i.status === 'processed'
                ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
                : i.status === 'failed'
                  ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                  : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    })),
)

const securityWidgetItems = computed<ActionItem[]>(() =>
    securityItems.value.map((s) => ({
        label: s.label ?? '-',
        sublabel: s.detail ?? undefined,
        badge:
            s.status === 'pass'
                ? 'Pass'
                : s.status === 'warn'
                  ? 'Warn'
                  : s.status === 'fail'
                    ? 'Fail'
                    : (s.status ?? '-'),
        badgeColor:
            s.status === 'pass'
                ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
                : s.status === 'warn'
                  ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
                  : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    })),
)

const alertItems = computed<ActionItem[]>(() =>
    alerts.value.map((a) => ({
        label: `${a.participant?.name ?? 'System'} — ${a.type_label ?? '-'}`,
        sublabel: a.created_at ?? undefined,
        badge: a.severity ?? '-',
        badgeColor:
            a.severity === 'critical'
                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                : a.severity === 'warning'
                  ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
                  : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    })),
)

const breakGlassItems = computed<ActionItem[]>(() =>
    breakGlassEvents.value.map((e) => ({
        label: `${e.participant?.name ?? '-'} — ${e.user?.name ?? '-'}`,
        sublabel:
            [e.access_granted_at, (e.justification ?? '').slice(0, 40)]
                .filter(Boolean)
                .join(' | ') || undefined,
        badge: e.acknowledged_at ? 'Reviewed' : 'Unreviewed',
        badgeColor: e.acknowledged_at
            ? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
            : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    })),
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ActionWidget
            title="Recent User Activity"
            description="User accounts and their recent login activity."
            :items="userItems"
            empty-message="No user activity found."
            view-all-href="/it-admin/users"
            :loading="loading"
        />

        <ActionWidget
            title="Integration Status"
            description="Recent inbound and outbound integration events."
            :items="integrationItems"
            empty-message="No integration events found."
            view-all-href="/it-admin/integrations"
            :loading="loading"
        />

        <ActionWidget
            title="Security Items"
            description="Security compliance checklist status."
            :items="securityWidgetItems"
            empty-message="No security items found."
            view-all-href="/it-admin/security"
            :loading="loading"
        />

        <ActionWidget
            title="Active Alerts"
            description="System and security alerts requiring IT attention."
            :items="alertItems"
            empty-message="No active alerts."
            view-all-href="/qa/dashboard"
            :loading="loading"
        />

        <ActionWidget
            :title="`Break-Glass Events (${unreviewedCount} Unreviewed)`"
            description="Emergency record access events requiring review."
            :items="breakGlassItems"
            empty-message="No break-glass events on record."
            view-all-href="/it-admin/break-glass"
            :loading="loading"
            class="lg:col-span-2"
        />
    </div>
</template>
