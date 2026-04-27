<script setup lang="ts">
// ─── Dashboards/QualityMeasures.vue: Phase K1 ───────────────────────────────
// Quality measures dashboard. Tracks HEDIS/CMS Stars rates over time
// (numerator/denominator with trendlines) so QA + leadership can see whether
// quality scores are improving or backsliding month-over-month.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import LineChart from '@/Components/Charts/LineChart.vue'

const measures = ref<any[]>([])
const snapshots = ref<Record<string, any[]>>({})
const loading = ref(true)

onMounted(async () => {
  try {
    const [m, s] = await Promise.all([
      axios.get('/quality-measures'),
      axios.get('/quality-measures/snapshots', { params: { days: 90 } }),
    ])
    measures.value = m.data.measures ?? []
    snapshots.value = s.data.rows ?? {}
  } finally { loading.value = false }
})

function cardData(measureId: string) {
  const rows = snapshots.value[measureId] ?? []
  const labels = rows.map((r: any) => String(r.computed_at).slice(0, 10))
  const data = rows.map((r: any) => parseFloat(r.rate ?? 0))
  return {
    labels,
    datasets: [{ label: 'Rate', data, borderColor: 'rgb(59, 130, 246)', backgroundColor: 'rgba(59, 130, 246, 0.15)', fill: true }],
  }
}

function latestRate(measureId: string): number | null {
  const rows = snapshots.value[measureId] ?? []
  const last = rows[rows.length - 1]
  return last ? parseFloat(last.rate ?? 0) : null
}

const byCategory = computed(() => {
  const out: Record<string, any[]> = {}
  for (const m of measures.value) {
    const cat = m.category ?? 'other'
    if (!out[cat]) out[cat] = []
    out[cat].push(m)
  }
  return out
})
</script>

<template>
  <Head title="Quality Measures" />
  <AppShell>
    <div class="p-6 space-y-6">
      <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Quality Measures</h1>
      <div v-if="loading" class="text-sm text-gray-500 dark:text-slate-400">Loading…</div>
      <div v-else-if="measures.length === 0" class="text-sm text-gray-500 dark:text-slate-400">No quality measures seeded.</div>

      <div v-for="(group, cat) in byCategory" :key="cat" class="space-y-3">
        <h2 class="text-sm font-semibold uppercase text-gray-500 dark:text-slate-400">{{ cat }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          <div
            v-for="m in group"
            :key="m.measure_id"
            class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4"
          >
            <div class="flex items-baseline justify-between mb-2">
              <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ m.name ?? m.measure_id }}</h3>
              <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                {{ latestRate(m.measure_id) ?? '-' }}
              </span>
            </div>
            <LineChart
              :labels="cardData(m.measure_id).labels"
              :data="cardData(m.measure_id)"
              :height="140"
            />
          </div>
        </div>
      </div>
    </div>
  </AppShell>
</template>
