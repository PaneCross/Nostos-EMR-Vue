<script setup lang="ts">
// ─── Dashboards/HighRisk.vue ────────────────────────────────────────────────
// High-risk participant registry. Shows participants flagged by the
// predictive risk model (recent hospitalization, polypharmacy, fall history,
// LACE+ score, ADL dependence, age) so the IDT can prioritize outreach.
// IDT = Interdisciplinary Team.
//
// Data provenance, scores come from PredictiveRiskService.scoreType()
// which extracts numeric features from each participant's actual clinical
// records and runs them through either a heuristic weighted-sum (default)
// or a trained logistic-regression model when one exists in
// emr_predictive_model_versions for the (tenant, risk_type). Two scores
// per participant : disenrollment (12-month likelihood) + acute_event
// (90-day hospitalization / ER). Nightly job runs at 03:00 and the
// Recompute button below hits POST /predictive-risk/recompute-all on demand.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { ArrowPathIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'

const rows = ref<any[]>([])
const loading = ref(true)
const filter = ref<string>('all')
const recomputing = ref(false)
const recomputeMessage = ref<string | null>(null)

async function loadAll() {
  loading.value = true
  try {
    const r = await axios.get('/dashboards/high-risk')
    rows.value = r.data.rows ?? []
  } finally { loading.value = false }
}

onMounted(loadAll)

async function recomputeNow() {
  recomputing.value = true
  recomputeMessage.value = null
  try {
    const r = await axios.post('/predictive-risk/recompute-all')
    const n = r.data?.participants_scored ?? 0
    const high = r.data?.by_band?.high ?? 0
    recomputeMessage.value = `Re-scored ${n} participant${n === 1 ? '' : 's'} · ${high} high-band score${high === 1 ? '' : 's'}.`
    await loadAll()
  } catch (e: any) {
    recomputeMessage.value = e.response?.data?.message ?? 'Recompute failed.'
  } finally {
    recomputing.value = false
    setTimeout(() => { recomputeMessage.value = null }, 6000)
  }
}

const filtered = computed(() =>
  filter.value === 'all'
    ? rows.value
    : rows.value.filter(r => r.risk_type === filter.value)
)

function bandColor(b: string): string {
  if (b === 'high') return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
  if (b === 'medium') return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
  return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
}
</script>

<template>
  <Head title="High Risk" />
  <AppShell>
    <div class="p-6 space-y-6">
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">High-Risk Participants</h1>
          <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Predictive risk scores recomputed nightly from clinical data.</p>
        </div>
        <div class="flex items-center gap-2">
          <select
            v-model="filter"
            class="rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 text-sm px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            <option value="all">All risk types</option>
            <option value="disenrollment">Disenrollment</option>
            <option value="acute_event">Acute event</option>
          </select>
          <button
            :disabled="recomputing"
            @click="recomputeNow"
            class="inline-flex items-center gap-2 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 px-3 py-1.5 text-sm font-medium text-slate-700 dark:text-slate-200 disabled:opacity-50 transition-colors"
          >
            <ArrowPathIcon :class="['w-4 h-4', recomputing && 'animate-spin']" aria-hidden="true" />
            {{ recomputing ? 'Recomputing…' : 'Recompute now' }}
          </button>
        </div>
      </div>

      <!-- Provenance / data-source banner -->
      <div class="rounded-lg border border-blue-200 dark:border-blue-800/60 bg-blue-50 dark:bg-blue-950/40 p-4 text-sm text-blue-900 dark:text-blue-200 flex gap-3">
        <InformationCircleIcon class="w-5 h-5 shrink-0 mt-0.5 text-blue-500 dark:text-blue-400" aria-hidden="true" />
        <div class="space-y-1.5">
          <p>
            <strong>Where this data comes from.</strong>
            <code class="bg-blue-100 dark:bg-blue-900/60 px-1 rounded text-xs">PredictiveRiskService</code>
            extracts five features from each participant's records (LACE+ assessment score, hospitalizations and ER visits in the
            last 90 days, active medication count for polypharmacy, ADL dependence, and age), then runs them through a weighted-sum
            heuristic. If a trained logistic-regression model exists for the tenant, that takes precedence. Two scores per
            participant: <em>disenrollment</em> (12-month) and <em>acute_event</em> (90-day). Bands: 70 and above is high, 40 to 69
            is medium, below 40 is low.
          </p>
          <p class="text-xs text-blue-700 dark:text-blue-300">
            This page is read-only and shows only scores from the last 24 hours in the high band. The default heuristic is a demo
            model. Production deployments need outcome data to train. Feature contributions are stored in the <code class="text-xs">factors</code>
            JSON column for each score row, viewable on the participant's chart.
          </p>
        </div>
      </div>

      <div v-if="recomputeMessage" class="rounded-md bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
        {{ recomputeMessage }}
      </div>

      <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 dark:bg-slate-900/50 text-left text-xs uppercase text-slate-500 dark:text-slate-400">
            <tr>
              <th class="px-3 py-2 font-medium">Participant</th>
              <th class="px-3 py-2 font-medium">MRN</th>
              <th class="px-3 py-2 font-medium">Risk type</th>
              <th class="px-3 py-2 font-medium text-right">Score</th>
              <th class="px-3 py-2 font-medium">Band</th>
              <th class="px-3 py-2 font-medium">Computed</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            <tr v-for="r in filtered" :key="r.id" class="text-slate-800 dark:text-slate-200">
              <td class="px-3 py-2">
                <Link v-if="r.participant" :href="`/participants/${r.participant.id}`" class="text-blue-600 dark:text-blue-400 hover:underline">
                  {{ r.participant.first_name }} {{ r.participant.last_name }}
                </Link>
              </td>
              <td class="px-3 py-2 font-mono text-xs">{{ r.participant?.mrn }}</td>
              <td class="px-3 py-2">{{ r.risk_type }}</td>
              <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ r.score }}</td>
              <td class="px-3 py-2">
                <span class="inline-block rounded px-2 py-0.5 text-xs font-medium" :class="bandColor(r.band)">{{ r.band?.toUpperCase() }}</span>
              </td>
              <td class="px-3 py-2 text-slate-500 dark:text-slate-400 tabular-nums">{{ String(r.computed_at).slice(0, 16).replace('T', ' ') }}</td>
            </tr>
            <tr v-if="!loading && filtered.length === 0">
              <td colspan="6" class="px-3 py-6 text-center text-slate-500 dark:text-slate-400">
                No high-risk participants. Try Recompute now if scores are stale.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
