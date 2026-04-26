<script setup lang="ts">
// ─── ItAdminDashboard.vue ─────────────────────────────────────────────────────
// IT Admin department live dashboard. Used by tenant IT admins for user
// provisioning trends, integration connector health (FHIR / lab / pharmacy /
// clearinghouse), the audit log, system config, and break-glass PHI access
// events that need review (HIPAA emergency-access tracking).
// Endpoints:
//   GET /dashboards/it-admin/users        → { recently_provisioned[], recently_deactivated[], total_active, total_inactive }
//   GET /dashboards/it-admin/integrations → { connectors[{ connector_type, last_status, error_count, is_healthy, total_today, last_message_at }] }
//   GET /dashboards/it-admin/audit        → { entries[] }
//   GET /dashboards/it-admin/config       → { transport_mode, auto_logout_minutes, sites[], site_count }
//   GET /dashboards/it-admin/break-glass  → { events[], unreviewed_count, total_today }
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import ActionWidget from '@/Components/Dashboard/ActionWidget.vue'
import type { ActionItem } from '@/Components/Dashboard/ActionWidget.vue'

defineProps<{ departmentLabel: string; role: string }>()

const loading = ref(true)
const usersData = ref<any>(null)
const integrationsData = ref<any>(null)
const auditData = ref<any>(null)
const configData = ref<any>(null)
const breakGlassData = ref<any>(null)
const credentialsData = ref<any>(null)

const CONNECTOR_LABELS: Record<string, string> = {
    hl7_adt:        'HL7 ADT',
    lab_results:    'Lab Results',
    pharmacy_ncpdp: 'Pharmacy NCPDP',
    other:          'Other',
}

onMounted(() => {
    Promise.all([
        axios.get('/dashboards/it-admin/users'),
        axios.get('/dashboards/it-admin/integrations'),
        axios.get('/dashboards/it-admin/audit'),
        axios.get('/dashboards/it-admin/config'),
        axios.get('/dashboards/it-admin/break-glass'),
        axios.get('/dashboards/it-admin/expiring-credentials'),
    ]).then(([r1, r2, r3, r4, r5, r6]) => {
        usersData.value = r1.data
        integrationsData.value = r2.data
        auditData.value = r3.data
        configData.value = r4.data
        breakGlassData.value = r5.data
        credentialsData.value = r6.data
    }).catch(() => {
        // Non-blocking — widgets will show empty state
    }).finally(() => loading.value = false)
})

const userItems = computed<ActionItem[]>(() => {
    if (!usersData.value) return []
    return [
        ...(usersData.value.recently_provisioned ?? []).map((u: any) => ({
            label: u.name ?? '-',
            sublabel: (u.department ?? '-').replace(/_/g, ' '),
            badge: 'Provisioned',
            badgeColor: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
            href: u.href ?? '/it-admin/users',
        })),
        ...(usersData.value.recently_deactivated ?? []).map((u: any) => ({
            label: u.name ?? '-',
            sublabel: u.updated_at ?? undefined,
            badge: 'Deactivated',
            badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
            href: u.href ?? '/it-admin/users',
        })),
    ]
})

const integrationItems = computed<ActionItem[]>(() =>
    (integrationsData.value?.connectors ?? []).map((c: any) => ({
        label: CONNECTOR_LABELS[c.connector_type] ?? c.connector_type ?? '-',
        sublabel: `${c.last_message_at ?? 'Never'} | ${c.total_today ?? 0} today`,
        badge: c.is_healthy ? 'Healthy' : `${c.error_count ?? 0} errors`,
        badgeColor: c.is_healthy
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        href: c.href ?? '/it-admin/integrations',
    }))
)

const auditItems = computed<ActionItem[]>(() =>
    (auditData.value?.entries ?? []).map((e: any) => ({
        label: e.action ?? '-',
        sublabel: `${e.user?.name ?? 'System'} | ${e.created_at ?? ''}`,
        badge: e.resource_type ?? undefined,
        badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        href: e.href ?? '/it-admin/audit',
    }))
)

const configItems = computed<ActionItem[]>(() => {
    if (!configData.value) return []
    return [
        {
            label: `Transport Mode: ${configData.value.transport_mode ?? '-'}`,
            badge: configData.value.transport_mode ?? '-',
            badgeColor: configData.value.transport_mode === 'broker'
                ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
                : 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
            href: '/admin/settings',
        },
        {
            label: `Auto-Logout: ${configData.value.auto_logout_minutes ?? '-'} min`,
            badge: `${configData.value.auto_logout_minutes ?? '-'}m`,
            badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
            href: '/admin/settings',
        },
        ...(configData.value.sites ?? []).map((s: any) => ({
            label: s.name ?? '-',
            sublabel: 'Site',
            badge: s.mrn_prefix ?? '-',
            badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
            href: s.href ?? '/admin/locations',
        })),
    ]
})

const breakGlassItems = computed<ActionItem[]>(() =>
    (breakGlassData.value?.events ?? []).map((e: any) => ({
        label: `${e.user?.name ?? 'Unknown user'} accessed ${e.participant?.name ?? 'unknown participant'}`,
        sublabel: [e.participant?.mrn, e.accessed_at ?? e.access_granted_at].filter(Boolean).join(' | ') || undefined,
        badge: (e.is_acknowledged || e.acknowledged_at) ? 'Reviewed' : 'Unreviewed',
        badgeColor: (e.is_acknowledged || e.acknowledged_at)
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        href: e.href ?? '/it-admin/break-glass',
    }))
)

const breakGlassTitle = computed(() => {
    const count = breakGlassData.value?.unreviewed_count
    return count ? `Break-the-Glass Access (${count} Unreviewed)` : 'Break-the-Glass Access'
})

// Phase 4 (MVP roadmap): staff credential expiration widget (§460.71)
const credentialItems = computed<ActionItem[]>(() =>
    (credentialsData.value?.credentials ?? []).map((c: any) => {
        const days = c.days_remaining
        const badge = c.status === 'expired' || (days !== null && days < 0)
            ? `${days !== null ? Math.abs(days) + 'd ' : ''}overdue`
            : days === 0 ? 'today'
            : `${days}d`
        const badgeColor =
            c.status === 'expired'   ? 'bg-red-600 text-white' :
            c.status === 'due_today' ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' :
            c.status === 'due_14'    ? 'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300' :
            c.status === 'due_30'    ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' :
                                       'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
        return {
            label: c.user?.name ?? '—',
            sublabel: `${c.type_label} · ${c.title}`,
            badge,
            badgeColor,
            href: c.href ?? '/it-admin/users',
        }
    })
)
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 grid-flow-dense gap-6">
        <ActionWidget
            title="User Management"
            description="Recently provisioned or recently deactivated users needing follow-up."
            :items="userItems"
            emptyMessage="No recent user changes."
            viewAllHref="/it-admin/users"
            :loading="loading"
        />

        <ActionWidget
            title="Integration Health"
            description="Failed or retryable integration messages (HL7 ADT, lab results). Click to review and retry."
            :items="integrationItems"
            emptyMessage="No integration data."
            viewAllHref="/it-admin/integrations"
            :loading="loading"
        />

        <ActionWidget
            title="Recent Audit Activity"
            description="Recent security and configuration events in the audit log."
            :items="auditItems"
            emptyMessage="No recent audit entries."
            viewAllHref="/it-admin/audit"
            :loading="loading"
        />

        <ActionWidget
            title="Tenant Configuration"
            description="Active site configurations and system parameters."
            :items="configItems"
            emptyMessage="No configuration data."
            viewAllHref="/it-admin/users"
            :loading="loading"
        />

        <ActionWidget
            :title="breakGlassTitle"
            description="Emergency access events bypassing normal RBAC. Unreviewed events require IT Admin acknowledgment for HIPAA compliance."
            :items="breakGlassItems"
            emptyMessage="No break-the-glass events."
            viewAllHref="/it-admin/break-glass"
            :loading="loading"
        />

        <!-- Phase 4 (MVP roadmap): §460.71 staff credential expiration tracker -->
        <ActionWidget
            title="Expiring Staff Credentials"
            description="Staff licenses, TB clearances, certifications expiring in the next 60 days (or already expired). 42 CFR §460.71."
            :items="credentialItems"
            emptyMessage="No credentials expiring soon."
            viewAllHref="/compliance/personnel-credentials"
            :loading="loading"
        />
    </div>
</template>
