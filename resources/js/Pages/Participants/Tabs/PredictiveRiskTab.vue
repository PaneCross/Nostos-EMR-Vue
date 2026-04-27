<script setup lang="ts">
// ─── PredictiveRiskTab.vue ─────────────────────────────────────────────────
// Risk-score dashboard: hospitalization, fall, mortality, frailty, etc.
// Each model shows latest score + history sparkline. "Recompute"
// triggers backend recalculation against current chart data.
//
// Scores are advisory: they inform IDT (Interdisciplinary Team: the
// PACE clinical team that meets weekly) discussion but do not auto-
// trigger orders. Models live in app/Services/PredictiveRisk/.
// ───────────────────────────────────────────────────────────────────────────
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'

interface Participant { id: number }
const props = defineProps<{ participant: Participant }>()

const loading = ref(true)
const latest = ref<Record<string, any>>({})
const history = ref<Record<string, any[]>>({})
const recomputing = ref(false)

function refresh() {
  loading.value = true
  axios.get(`/participants/${props.participant.id}/predictive-risk`)
    .then(r => {
      latest.value = r.data.latest ?? {}
      history.value = r.data.history ?? {}
    })
    .finally(() => loading.value = false)
}
onMounted(refresh)

async function recompute() {
  recomputing.value = true
  try {
    await axios.post(`/participants/${props.participant.id}/predictive-risk/compute`)
    refresh()
  } finally { recomputing.value = false }
}

function bandColor(b: string): string {
  if (b === 'high') return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
  if (b === 'medium') return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
  return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
}

const factors = computed(() => {
  const out: Record<string, Array<{ name: string; value: any }>> = {}
  for (const [kind, score] of Object.entries(latest.value)) {
    if (!score?.factors) continue
    let raw = score.factors
    if (typeof raw === 'string') {
      try { raw = JSON.parse(raw) } catch { raw = {} }
    }
    out[kind] = Object.entries(raw ?? {}).map(([name, value]) => ({ name, value }))
  }
  return out
})
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Predictive Risk</h2>
      <button :disabled="recomputing" class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="recompute">
        {{ recomputing ? 'Recomputing…' : 'Recompute' }}
      </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div
        v-for="(score, kind) in latest"
        :key="kind"
        class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4"
      >
        <div class="flex items-baseline justify-between">
          <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ kind }}</h3>
          <span class="inline-block rounded px-2 py-0.5 text-xs" :class="bandColor(score.band)">
            {{ score.band?.toUpperCase() }}
          </span>
        </div>
        <div class="mt-2 text-sm">
          <div>Score: <span class="font-semibold">{{ score.score }}</span></div>
          <div class="text-xs text-gray-500 dark:text-slate-400">Computed {{ score.computed_at?.slice(0, 16).replace('T', ' ') }}</div>
          <!-- Phase O6: provenance chip -->
          <span
            class="mt-1 inline-block rounded px-1.5 py-0.5 text-[10px]"
            :class="String(score.model_version ?? '').startsWith('trained-')
              ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
              : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'"
          >
            {{ String(score.model_version ?? '').startsWith('trained-') ? score.model_version : 'heuristic' }}
          </span>
        </div>
        <div v-if="factors[kind]?.length" class="mt-3 space-y-1">
          <div v-for="f in factors[kind]" :key="f.name" class="text-xs flex justify-between">
            <span>{{ f.name }}</span>
            <span class="font-semibold">{{ f.value }}</span>
          </div>
        </div>
      </div>
      <p v-if="!loading && Object.keys(latest).length === 0" class="col-span-full rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 text-center text-sm text-gray-500 dark:text-slate-400">
        No risk scores computed yet.
      </p>
    </div>
  </div>
</template>
