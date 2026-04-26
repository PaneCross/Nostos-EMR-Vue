<script setup lang="ts">
// ─── Registries/RegistryView ────────────────────────────────────────────────
// Generic disease-registry view — rendered for CHF (Congestive Heart Failure),
// COPD (Chronic Obstructive Pulmonary Disease), Diabetes, and any other
// registries via the `registry` prop. Lists every enrolled participant with
// the condition + their care-gap status (last A1C, last echo, etc.).
//
// Audience: Primary Care, IDT for population health.
//
// Notable rules:
//   - Membership is computed from active problem list entries (SNOMED-coded);
//     adding/removing a problem updates registry membership automatically.
//   - Care-gap thresholds align with HEDIS / Stars measure specifications.
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

const props = defineProps<{ registry: string }>()

const loading = ref(true)
const data = ref<any>({ label: '', count: 0, rows: [] })

onMounted(async () => {
  try {
    const r = await axios.get(`/registries/${props.registry}`)
    data.value = r.data ?? { label: '', count: 0, rows: [] }
  } finally { loading.value = false }
})

const columns = computed(() => {
  const first = data.value.rows?.[0] ?? {}
  return Object.keys(first)
})
</script>

<template>
  <Head :title="data.label || registry" />
  <AppShell>
    <div class="p-6 space-y-4">
      <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">
          {{ data.label || registry }} Registry
          <span class="ml-2 inline-block rounded bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 px-2 py-0.5 text-sm">
            {{ data.count }} participants
          </span>
        </h1>
        <a
          :href="`/registries/${registry}/export`"
          class="rounded bg-gray-600 px-3 py-1.5 text-sm text-white hover:bg-gray-700"
        >
          Download CSV
        </a>
      </div>

      <div v-if="loading" class="text-sm text-gray-500 dark:text-slate-400">Loading…</div>

      <div v-else class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs uppercase text-gray-500 dark:text-slate-400">
              <tr><th v-for="c in columns" :key="c" class="px-3 py-2">{{ c.replace(/_/g, ' ') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
              <tr v-for="r in data.rows" :key="r.id">
                <td v-for="c in columns" :key="c" class="px-3 py-2">
                  <template v-if="c === 'name' && r.id">
                    <Link :href="`/participants/${r.id}`" class="text-blue-600 dark:text-blue-400 hover:underline">
                      {{ r[c] }}
                    </Link>
                  </template>
                  <template v-else>{{ r[c] ?? '—' }}</template>
                </td>
              </tr>
              <tr v-if="data.rows.length === 0">
                <td :colspan="columns.length || 1" class="px-3 py-4 text-center text-gray-500 dark:text-slate-400">
                  No participants in this registry.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppShell>
</template>
