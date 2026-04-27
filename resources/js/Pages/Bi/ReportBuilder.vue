<script setup lang="ts">
// ─── Bi/ReportBuilder ───────────────────────────────────────────────────────
// Self-service report builder: pick a domain (encounters / participants /
// quality indicators / capitation), filter, group, and visualize. Saved
// reports become reusable widgets in Bi/Dashboards.vue.
//
// Audience: BI editors (typically QA Compliance + Finance + Exec).
//
// Notable rules:
//   - Queries run via a server-side allowlisted query builder: users
//     cannot inject arbitrary SQL.
//   - Tenant-scoped at every level; cross-tenant data leakage is impossible
//     by design.
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import BarChart from '@/Components/Charts/BarChart.vue'

const schema = ref<any>({})
const loading = ref(true)
const running = ref(false)
const entity = ref('')
const dimension = ref('')
const measure = ref('')
const result = ref<any[]>([])
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    const r = await axios.get('/bi/schema')
    schema.value = r.data ?? {}
  } finally { loading.value = false }
})

const entities = computed(() => Object.keys(schema.value?.entities ?? schema.value ?? {}))
const entityDef = computed<any>(() => (schema.value?.entities ?? schema.value ?? {})[entity.value] ?? {})
const dimensions = computed(() => entityDef.value?.dimensions ?? [])
const measures = computed(() => entityDef.value?.measures ?? [])

async function run() {
  running.value = true
  error.value = null
  try {
    const payload: any = { entity: entity.value, dimension: dimension.value }
    if (measure.value) payload.measure = measure.value
    const r = await axios.post('/bi/report', payload)
    result.value = r.data?.rows ?? r.data ?? []
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Report failed'
    result.value = []
  } finally { running.value = false }
}

const chartData = computed(() => ({
  labels: result.value.map((r: any) => String(r.dimension ?? r[dimension.value] ?? '-')),
  datasets: [{
    label: measure.value || 'count',
    data: result.value.map((r: any) => Number(r.measure ?? r.count ?? 0)),
  }],
}))

function downloadCsv() {
  if (!result.value.length) return
  const headers = Object.keys(result.value[0])
  const rows = [headers.join(',')]
  for (const r of result.value) rows.push(headers.map(h => JSON.stringify(r[h] ?? '')).join(','))
  const blob = new Blob([rows.join('\n')], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `report-${entity.value}-${dimension.value}.csv`
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <Head title="Report Builder" />
  <AppShell>
    <div class="p-6 space-y-6">
      <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">BI Report Builder</h1>

      <div v-if="loading" class="text-sm text-gray-500 dark:text-slate-400">Loading schema…</div>
      <div v-else class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label class="block text-xs mb-1">Entity</label>
            <select v-model="entity" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
              <option value="">- select -</option>
              <option v-for="e in entities" :key="e" :value="e">{{ e }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs mb-1">Dimension</label>
            <select v-model="dimension" :disabled="!entity" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
              <option value="">- select -</option>
              <option v-for="d in dimensions" :key="d" :value="d">{{ d }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs mb-1">Measure (optional)</label>
            <select v-model="measure" :disabled="!entity" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm">
              <option value="">count (default)</option>
              <option v-for="m in measures" :key="m" :value="m">{{ m }}</option>
            </select>
          </div>
        </div>
        <div class="flex justify-end gap-2">
          <button
            :disabled="!entity || !dimension || running"
            class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
            @click="run"
          >
            {{ running ? 'Running…' : 'Run report' }}
          </button>
          <button
            :disabled="!result.length"
            class="rounded bg-gray-600 px-3 py-1.5 text-sm text-white hover:bg-gray-700 disabled:opacity-50"
            @click="downloadCsv"
          >
            Export CSV
          </button>
        </div>
        <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>
      </div>

      <div v-if="result.length" class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
        <BarChart :labels="chartData.labels" :data="chartData" :height="300" />
        <div class="mt-3 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="text-xs uppercase text-gray-500 dark:text-slate-400">
                <th v-for="k in Object.keys(result[0])" :key="k" class="px-2 py-1 text-left">{{ k }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
              <tr v-for="(r, i) in result" :key="i">
                <td v-for="k in Object.keys(result[0])" :key="k" class="px-2 py-1">
                  {{ r[k] }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppShell>
</template>
