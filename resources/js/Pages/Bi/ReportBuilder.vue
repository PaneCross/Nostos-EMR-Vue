<script setup lang="ts">
// ─── Bi/ReportBuilder ───────────────────────────────────────────────────────
// Self-service report builder : pick an entity (participants / medications /
// problems / grievances / incidents / appointments), a dimension column to
// group by, and an optional measure (count / count_distinct_participant).
// Renders as a bar chart + a tabular view + CSV export.
//
// Audience : BI editors (typically QA Compliance + Finance + Exec).
//
// Server contract — both endpoints scoped to the user's effective tenant :
//   GET  /bi/schema  → { entities[], dimensions[], measures[], joins[] }
//                     dimensions are flat, fully-qualified ('emr_<table>.col')
//   POST /bi/report  → { labels[], datasets[{label,data[]}], row_count }
//
// We derive the per-entity dimension list client-side by prefix-matching
// 'emr_<entity>.' against the flat dimensions array. This avoids needing
// the server to nest the schema per entity (and matches the existing
// allowlist convention in ReportBuilderService).
// ────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import BarChart from '@/Components/Charts/BarChart.vue'

interface Schema {
  entities: string[]
  dimensions: string[]
  measures: string[]
  joins: string[]
}
interface ReportRow { label: string; value: number }
interface ReportResponse {
  labels: string[]
  datasets: Array<{ label: string; data: number[] }>
  row_count: number
}

const schema = ref<Schema>({ entities: [], dimensions: [], measures: [], joins: [] })
const loading = ref(true)
const running = ref(false)
const entity = ref('')
const dimension = ref('')
const measure = ref('')
const result = ref<ReportRow[]>([])
const datasetLabel = ref('')
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    const r = await axios.get<Schema>('/bi/schema')
    schema.value = {
      entities:   r.data.entities   ?? [],
      dimensions: r.data.dimensions ?? [],
      measures:   r.data.measures   ?? [],
      joins:      r.data.joins      ?? [],
    }
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Failed to load schema.'
  } finally {
    loading.value = false
  }
})

// Friendly label : strip the 'emr_<table>.' prefix on dimensions.
function prettyDim(d: string): string {
  return d.replace(/^emr_[a-z_]+\./, '')
}

// Filter the flat dimension list to columns that belong to the chosen
// entity. Convention : ENTITIES['participants'].table = 'emr_participants'
// → keep dimensions starting with 'emr_participants.'.
const dimensions = computed(() =>
  entity.value
    ? schema.value.dimensions.filter(d => d.startsWith(`emr_${entity.value}.`))
    : []
)

const measures = computed(() => schema.value.measures)

function resetWhenEntityChanges() {
  // Whenever entity changes, drop any selected dimension that no longer matches.
  if (dimension.value && ! dimensions.value.includes(dimension.value)) {
    dimension.value = ''
  }
  result.value = []
  error.value = null
}

async function run() {
  if (! entity.value || ! dimension.value) return
  running.value = true
  error.value = null
  try {
    const payload: Record<string, string> = {
      entity:    entity.value,
      dimension: dimension.value,
    }
    if (measure.value) payload.measure = measure.value
    const r = await axios.post<ReportResponse>('/bi/report', payload)
    const labels = r.data?.labels ?? []
    const data   = r.data?.datasets?.[0]?.data ?? []
    datasetLabel.value = r.data?.datasets?.[0]?.label ?? ''
    // Zip into rows so the table view can iterate in one place.
    result.value = labels.map((label, i) => ({ label, value: Number(data[i] ?? 0) }))
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Report failed.'
    result.value = []
  } finally {
    running.value = false
  }
}

const chartData = computed(() => ({
  labels:   result.value.map(r => r.label),
  datasets: [{
    label: datasetLabel.value || (measure.value || 'count'),
    data:  result.value.map(r => r.value),
  }],
}))

function downloadCsv() {
  if (! result.value.length) return
  const measureName = measure.value || 'count'
  const dimName     = prettyDim(dimension.value)
  const headers     = [dimName, measureName]
  const rows        = [headers.map(h => JSON.stringify(h)).join(',')]
  for (const row of result.value) {
    rows.push([JSON.stringify(row.label), row.value].join(','))
  }
  const blob = new Blob([rows.join('\n')], { type: 'text/csv' })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href     = url
  a.download = `report-${entity.value}-${dimName}.csv`
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <Head title="Report Builder" />
  <AppShell>
    <!-- ── Page wrapper : centered, max-width so the form doesn't span the ──
         full monitor on wide screens. Was the visual culprit before — three
         selects at 33% of a 2000px viewport read as broken UI. ────────── -->
    <div class="p-6 max-w-6xl mx-auto space-y-6">
      <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">BI Report Builder</h1>
      <p class="text-sm text-slate-500 dark:text-slate-400 -mt-3">
        Pick a data source, a column to group by, and (optionally) a measure. Tenant-scoped server-side.
      </p>

      <div v-if="loading" class="text-sm text-slate-500 dark:text-slate-400">Loading schema…</div>

      <div
        v-else
        class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5 space-y-4 shadow-sm"
      >
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <!-- Entity -->
          <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
              Data source
            </label>
            <select
              v-model="entity"
              @change="resetWhenEntityChanges"
              class="w-full rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
            >
              <option value="">— choose entity —</option>
              <option v-for="e in schema.entities" :key="e" :value="e">{{ e }}</option>
            </select>
          </div>

          <!-- Dimension -->
          <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
              Group by (dimension)
            </label>
            <select
              v-model="dimension"
              :disabled="! entity"
              class="w-full rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <option value="">{{ entity ? '— choose dimension —' : '— pick entity first —' }}</option>
              <option v-for="d in dimensions" :key="d" :value="d">{{ prettyDim(d) }}</option>
            </select>
            <p v-if="entity && ! dimensions.length" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
              No dimensions registered for this entity yet.
            </p>
          </div>

          <!-- Measure -->
          <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
              Measure (optional)
            </label>
            <select
              v-model="measure"
              :disabled="! entity"
              class="w-full rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <option value="">count (default)</option>
              <option v-for="m in measures" :key="m" :value="m">{{ m }}</option>
            </select>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2">
          <button
            :disabled="! entity || ! dimension || running"
            class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            @click="run"
          >
            {{ running ? 'Running…' : 'Run report' }}
          </button>
          <button
            :disabled="! result.length"
            class="rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-4 py-1.5 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            @click="downloadCsv"
          >
            Export CSV
          </button>
        </div>

        <div v-if="error" class="rounded-md bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 px-3 py-2 text-sm text-red-700 dark:text-red-300">
          {{ error }}
        </div>
      </div>

      <!-- Chart + table -->
      <div
        v-if="result.length"
        class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5 shadow-sm space-y-5"
      >
        <div>
          <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ datasetLabel || 'Result' }}</h2>
          <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ result.length }} row{{ result.length === 1 ? '' : 's' }} · top 30 by value</p>
        </div>
        <BarChart :labels="chartData.labels" :data="chartData" :height="320" />
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-700">
                <th class="px-3 py-2 text-left font-medium">{{ prettyDim(dimension) }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ measure || 'count' }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="(row, i) in result" :key="i" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                <td class="px-3 py-2 text-slate-800 dark:text-slate-200">{{ row.label || '—' }}</td>
                <td class="px-3 py-2 text-right tabular-nums text-slate-800 dark:text-slate-200">{{ row.value.toLocaleString() }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppShell>
</template>
