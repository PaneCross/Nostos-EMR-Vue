<script setup lang="ts">
// ─── Operations/Panel.vue — Phase K3 ─────────────────────────────────────────
// Panel-management view. Each PCP / care-manager has a "panel" of assigned
// participants — this page shows the current user's panel plus a comparison
// of panel sizes across the org (for load-balancing decisions).
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

const loading = ref(true)
const mine = ref<any[]>([])
const sizes = ref<any[]>([])

onMounted(async () => {
  try {
    const [m, s] = await Promise.all([
      axios.get('/panel/my').catch(() => ({ data: { participants: [] } })),
      axios.get('/panel/sizes').catch(() => ({ data: { rows: [] } })),
    ])
    mine.value = m.data.participants ?? m.data ?? []
    sizes.value = s.data.rows ?? s.data ?? []
  } finally { loading.value = false }
})
</script>

<template>
  <Head title="Panel Management" />
  <AppShell>
    <div class="p-6 space-y-6">
      <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Panel Management</h1>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
          <h2 class="text-sm font-semibold mb-3 text-gray-900 dark:text-slate-100">My panel ({{ mine.length }})</h2>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="text-xs uppercase text-gray-500 dark:text-slate-400">
                <tr><th class="px-2 py-1 text-left">MRN</th><th class="px-2 py-1 text-left">Name</th><th class="px-2 py-1 text-left">DOB</th></tr>
              </thead>
              <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                <tr v-for="p in mine" :key="p.id">
                  <td class="px-2 py-1">{{ p.mrn }}</td>
                  <td class="px-2 py-1">
                    <Link :href="`/participants/${p.id}`" class="text-blue-600 dark:text-blue-400 hover:underline">
                      {{ p.first_name }} {{ p.last_name }}
                    </Link>
                  </td>
                  <td class="px-2 py-1">{{ p.dob }}</td>
                </tr>
                <tr v-if="!loading && mine.length === 0">
                  <td colspan="3" class="px-2 py-4 text-center text-gray-500 dark:text-slate-400">No panel assigned.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
          <h2 class="text-sm font-semibold mb-3 text-gray-900 dark:text-slate-100">Panel sizes</h2>
          <table class="min-w-full text-sm">
            <thead class="text-xs uppercase text-gray-500 dark:text-slate-400">
              <tr><th class="px-2 py-1 text-left">Provider</th><th class="px-2 py-1 text-left">Count</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
              <tr v-for="r in sizes" :key="r.id ?? r.user_id">
                <td class="px-2 py-1">{{ r.first_name }} {{ r.last_name }}</td>
                <td class="px-2 py-1 font-semibold">{{ r.count ?? r.panel_size }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppShell>
</template>
