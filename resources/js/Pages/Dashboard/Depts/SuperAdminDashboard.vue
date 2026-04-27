<script setup lang="ts">
// ─── SuperAdminDashboard.vue ───────────────────────────────────────────────────
// Dashboard for Nostos Super Admin users. SuperAdmin is a Nostos-internal role
// that can act ACROSS tenants: every other user is hard-scoped to one tenant
// org. Provides quick-launch to the full Super Admin panel (tenant management,
// system health monitoring, new-tenant onboarding wizard).
// ─────────────────────────────────────────────────────────────────────────────
import { router } from '@inertiajs/vue3'
defineProps<{ departmentLabel: string; role: string }>()
</script>

<template>
    <div class="space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <button
                type="button"
                class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-6 text-left hover:shadow-md transition-shadow"
                @click="router.visit('/super-admin-panel')"
            >
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">Super Admin Panel</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Tenant management, health monitoring, and onboarding.</p>
            </button>
            <button
                type="button"
                class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-6 text-left hover:shadow-md transition-shadow"
                @click="router.visit('/participants')"
            >
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">All Participants</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Browse all participants across all tenants.</p>
            </button>
            <button
                type="button"
                class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-6 text-left hover:shadow-md transition-shadow"
                @click="router.visit('/it-admin/audit')"
            >
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">Audit Log</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">View system-wide audit trail.</p>
            </button>
        </div>
    </div>
</template>
