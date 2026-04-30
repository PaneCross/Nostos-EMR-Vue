<script setup lang="ts">
// ─── Dashboards/QualityMeasures.vue ─────────────────────────────────────────
// HEDIS / CMS-Stars quality measure dashboard. Tracks numerator/denominator
// rates over time per measure so QA + leadership can see whether quality
// scores are improving or backsliding month-over-month.
//
// Data provenance, these numbers are DERIVED, not entered. Every snapshot
// comes from QualityMeasureService.computeAll() which queries real clinical
// tables (immunizations, clinical notes, problems, incidents, consent
// records, participants). The scheduled QualityMeasureSnapshotJob runs
// nightly at 02:30 against every tenant. The Recompute button below hits
// POST /quality-measures/compute on demand.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import LineChart from '@/Components/Charts/LineChart.vue'
import { ArrowPathIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'

const measures = ref<any[]>([])
const snapshots = ref<Record<string, any[]>>({})
const loading = ref(true)
const recomputing = ref(false)
const recomputeMessage = ref<string | null>(null)

async function loadAll() {
  loading.value = true
  try {
    const [m, s] = await Promise.all([
      axios.get('/quality-measures'),
      axios.get('/quality-measures/snapshots', { params: { days: 90 } }),
    ])
    measures.value = m.data.measures ?? []
    snapshots.value = s.data.rows ?? {}
  } finally { loading.value = false }
}

onMounted(loadAll)

async function recomputeNow() {
  recomputing.value = true
  recomputeMessage.value = null
  try {
    const r = await axios.post('/quality-measures/compute')
    const count = (r.data?.snapshots ?? []).length
    recomputeMessage.value = `Recomputed ${count} measure${count === 1 ? '' : 's'} from current clinical data.`
    await loadAll()
  } catch (e: any) {
    recomputeMessage.value = e.response?.data?.message ?? 'Recompute failed.'
  } finally {
    recomputing.value = false
    setTimeout(() => { recomputeMessage.value = null }, 6000)
  }
}

function cardData(measureId: string) {
  const rows = snapshots.value[measureId] ?? []
  const labels = rows.map((r: any) => String(r.computed_at).slice(0, 10))
  // Column on emr_quality_measure_snapshots is rate_pct (decimal:2), not rate.
  const data = rows.map((r: any) => parseFloat(r.rate_pct ?? r.rate ?? 0))
  return {
    labels,
    datasets: [{ label: 'Rate (%)', data, borderColor: 'rgb(59, 130, 246)', backgroundColor: 'rgba(59, 130, 246, 0.15)', fill: true }],
  }
}

function latestRate(measureId: string): string {
  const rows = snapshots.value[measureId] ?? []
  const last = rows[rows.length - 1]
  if (! last) return '-'
  const v = parseFloat(last.rate_pct ?? last.rate ?? 0)
  return Number.isFinite(v) ? `${v.toFixed(1)}%` : '-'
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
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Quality Measures</h1>
          <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">HEDIS / CMS Stars rate trends, recomputed nightly from your clinical data.</p>
        </div>
        <button
          :disabled="recomputing"
          @click="recomputeNow"
          class="inline-flex items-center gap-2 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 px-3 py-1.5 text-sm font-medium text-slate-700 dark:text-slate-200 disabled:opacity-50 transition-colors"
        >
          <ArrowPathIcon :class="['w-4 h-4', recomputing && 'animate-spin']" aria-hidden="true" />
          {{ recomputing ? 'Recomputing…' : 'Recompute now' }}
        </button>
      </div>

      <!-- Provenance / data-source banner -->
      <div class="rounded-lg border border-blue-200 dark:border-blue-800/60 bg-blue-50 dark:bg-blue-950/40 p-4 text-sm text-blue-900 dark:text-blue-200 flex gap-3">
        <InformationCircleIcon class="w-5 h-5 shrink-0 mt-0.5 text-blue-500 dark:text-blue-400" aria-hidden="true" />
        <div class="space-y-1.5">
          <p>
            <strong>Where this data comes from.</strong>
            Each rate is computed by <code class="bg-blue-100 dark:bg-blue-900/60 px-1 rounded text-xs">QualityMeasureService</code>,
            which queries the source clinical tables (immunizations, clinical notes, problems, incidents, consent records, and
            participant demographics). Numerator and denominator counts are exact. Rates update on the nightly schedule at 02:30
            and on demand via the <em>Recompute now</em> button above.
          </p>
          <p class="text-xs text-blue-700 dark:text-blue-300">
            This page is read-only. To move a rate, fix the underlying clinical reality (sign that note, record the immunization),
            then recompute.
          </p>
        </div>
      </div>

      <div v-if="recomputeMessage" class="rounded-md bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
        {{ recomputeMessage }}
      </div>

      <div v-if="loading" class="text-sm text-slate-500 dark:text-slate-400">Loading…</div>
      <div v-else-if="measures.length === 0" class="text-sm text-slate-500 dark:text-slate-400">No quality measures seeded.</div>

      <div v-for="(group, cat) in byCategory" :key="cat" class="space-y-3">
        <h2 class="text-sm font-semibold uppercase text-slate-500 dark:text-slate-400">{{ cat }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          <div
            v-for="m in group"
            :key="m.measure_id"
            class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 shadow-sm"
          >
            <div class="flex items-baseline justify-between mb-2">
              <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ m.name ?? m.measure_id }}</h3>
              <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                {{ latestRate(m.measure_id) }}
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
