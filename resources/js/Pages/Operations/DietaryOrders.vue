<script setup lang="ts">
// ─── Operations/DietaryOrders.vue: Phase K3 ─────────────────────────────────
// Kitchen/food-service roster. Lists active diet orders grouped by diet type
// (regular / cardiac / renal / pureed / etc.) so kitchen staff can plan meal
// prep for the day-center census.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

const groups = ref<Record<string, any[]>>({})
const loading = ref(true)

onMounted(async () => {
  try {
    const r = await axios.get('/dietary/roster')
    groups.value = r.data.groups ?? {}
  } finally { loading.value = false }
})
</script>

<template>
  <Head title="Dietary Orders" />
  <AppShell>
    <div class="p-6 space-y-6">
      <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Dietary Orders: Roster</h1>

      <div v-if="loading" class="text-sm text-gray-500 dark:text-slate-400">Loading…</div>
      <div v-else-if="Object.keys(groups).length === 0" class="text-sm text-gray-500 dark:text-slate-400">
        No active dietary orders.
      </div>

      <div v-for="(orders, diet) in groups" :key="diet" class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
        <div class="bg-gray-50 dark:bg-slate-900 px-4 py-2 flex items-baseline justify-between">
          <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ diet }}</h2>
          <span class="text-xs text-gray-500 dark:text-slate-400">{{ orders.length }} participants</span>
        </div>
        <table class="min-w-full text-sm">
          <thead class="text-xs uppercase text-gray-500 dark:text-slate-400">
            <tr>
              <th class="px-3 py-1 text-left">MRN</th>
              <th class="px-3 py-1 text-left">Name</th>
              <th class="px-3 py-1 text-left">Calorie</th>
              <th class="px-3 py-1 text-left">Fluid restriction</th>
              <th class="px-3 py-1 text-left">Effective</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <tr v-for="o in orders" :key="o.id">
              <td class="px-3 py-1">{{ o.participant?.mrn }}</td>
              <td class="px-3 py-1">
                <Link v-if="o.participant" :href="`/participants/${o.participant.id}`" class="text-blue-600 dark:text-blue-400 hover:underline">
                  {{ o.participant.first_name }} {{ o.participant.last_name }}
                </Link>
              </td>
              <td class="px-3 py-1">{{ o.calorie_target ?? '-' }}</td>
              <td class="px-3 py-1">{{ o.fluid_restriction_ml_per_day ? `${o.fluid_restriction_ml_per_day} mL/d` : '-' }}</td>
              <td class="px-3 py-1">{{ o.effective_date }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
