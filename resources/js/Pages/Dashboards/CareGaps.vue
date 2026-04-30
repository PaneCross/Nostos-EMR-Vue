<script setup lang="ts">
// ─── Dashboards/CareGaps.vue ────────────────────────────────────────────────
// Population-health "care gaps" view : participants missing recommended
// preventive screenings (annual flu vaccine, A1c, mammogram, etc.) per
// HEDIS / CMS Stars measures. Two views : org-wide summary + the logged-in
// clinician's own panel.
//
// Data provenance, gap rows come from CareGapService.evaluate(), which
// queries each participant's actual clinical history (clinical notes,
// immunizations, problems) against the 7 measure definitions. The
// scheduled CareGapCalculationJob runs nightly at 02:00 and the Recompute
// button below hits POST /care-gaps/recompute-all on demand.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import BarChart from '@/Components/Charts/BarChart.vue'
import { ArrowPathIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'

const summary = ref<any[]>([])
const myPanel = ref<any[]>([])
const loading = ref(true)
const recomputing = ref(false)
const recomputeMessage = ref<string | null>(null)

async function loadAll() {
  loading.value = true
  try {
    const [s, p] = await Promise.all([
      axios.get('/care-gaps/summary'),
      axios.get('/care-gaps/my-panel').catch(() => ({ data: { rows: [] } })),
    ])
    summary.value = s.data.rows ?? []
    myPanel.value = p.data.rows ?? p.data ?? []
  } finally { loading.value = false }
}

onMounted(loadAll)

async function recomputeNow() {
  recomputing.value = true
  recomputeMessage.value = null
  try {
    const r = await axios.post('/care-gaps/recompute-all')
    const ev   = r.data?.participants_evaluated ?? 0
    const open = r.data?.open_gaps ?? 0
    recomputeMessage.value = `Re-evaluated ${ev} participant${ev === 1 ? '' : 's'} · ${open} open gap${open === 1 ? '' : 's'}.`
    await loadAll()
  } catch (e: any) {
    recomputeMessage.value = e.response?.data?.message ?? 'Recompute failed.'
  } finally {
    recomputing.value = false
    setTimeout(() => { recomputeMessage.value = null }, 6000)
  }
}

const chart = computed(() => {
  const labels = summary.value.map((r: any) => r.measure)
  return {
    labels,
    data: {
      labels,
      datasets: [
        { label: 'Open',      data: summary.value.map((r: any) => Number(r.open) || 0),      backgroundColor: 'rgba(239, 68, 68, 0.7)' },
        { label: 'Satisfied', data: summary.value.map((r: any) => Number(r.satisfied) || 0), backgroundColor: 'rgba(34, 197, 94, 0.7)' },
      ],
    },
  }
})
</script>

<template>
  <Head title="Care Gaps" />
  <AppShell>
    <div class="p-6 space-y-6">
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Care Gaps</h1>
          <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Preventive screenings overdue per participant, recomputed nightly from your clinical data.</p>
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
            <code class="bg-blue-100 dark:bg-blue-900/60 px-1 rounded text-xs">CareGapService</code>
            checks each enrolled participant against 7 preventive measures
            (annual PCP visit, flu shot, pneumococcal, colonoscopy, mammogram, A1c, diabetic eye exam) using their clinical
            notes, immunizations, and active problems. One row per participant per measure. Updates nightly at 02:00 and on demand.
          </p>
          <p class="text-xs text-blue-700 dark:text-blue-300">
            This page is read-only. Closing a gap means signing a fresh note, recording the immunization, and so on, then
            pressing recompute.
          </p>
        </div>
      </div>

      <div v-if="recomputeMessage" class="rounded-md bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
        {{ recomputeMessage }}
      </div>

      <div v-if="loading" class="text-sm text-slate-500 dark:text-slate-400">Loading…</div>

      <div v-else class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5 shadow-sm">
        <h2 class="text-sm font-semibold mb-3 text-slate-900 dark:text-slate-100">Tenant summary by measure</h2>
        <BarChart :labels="chart.labels" :data="chart.data" :height="300" />
      </div>

      <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5 shadow-sm">
        <h2 class="text-sm font-semibold mb-3 text-slate-900 dark:text-slate-100">My panel</h2>
        <table class="min-w-full text-sm">
          <thead class="text-xs uppercase text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-700">
            <tr>
              <th class="px-2 py-1 text-left font-medium">Participant</th>
              <th class="px-2 py-1 text-left font-medium">Measure</th>
              <th class="px-2 py-1 text-left font-medium">Status</th>
              <th class="px-2 py-1 text-left font-medium">Next due</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            <tr v-for="g in myPanel" :key="g.id" class="text-slate-800 dark:text-slate-200">
              <td class="px-2 py-1.5">{{ g.participant?.first_name }} {{ g.participant?.last_name }}</td>
              <td class="px-2 py-1.5">{{ g.measure }}</td>
              <td class="px-2 py-1.5">
                <span :class="g.satisfied ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400 font-medium'">
                  {{ g.satisfied ? 'Satisfied' : 'Open' }}
                </span>
              </td>
              <td class="px-2 py-1.5 text-slate-500 dark:text-slate-400">{{ g.next_due_date ?? '-' }}</td>
            </tr>
            <tr v-if="myPanel.length === 0">
              <td colspan="4" class="px-2 py-4 text-center text-slate-500 dark:text-slate-400">
                No gaps on your panel, or you don't have a participant panel assigned.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
