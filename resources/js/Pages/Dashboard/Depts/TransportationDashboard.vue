<script setup lang="ts">
// ─── TransportationDashboard.vue ──────────────────────────────────────────────
// Transportation department dashboard.
// Transport integration is pending Nostos deployment — shows Coming Soon.
// ─────────────────────────────────────────────────────────────────────────────
defineProps<{ departmentLabel: string; role: string }>()
</script>

<template>
    <div class="space-y-4">
        <div class="bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 rounded-xl p-6 text-center">
            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Nostos Transport Integration Pending</p>
            <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">Live transport dashboard data will appear here once the transport bridge is connected.</p>
        </div>
    </div>
</template>
