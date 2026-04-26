<script setup lang="ts">
// ─── Dashboards/HighRisk.vue — Phase K1 ──────────────────────────────────────
// High-risk participant registry. Lists participants flagged by the predictive
// risk model (recent hospitalization, polypharmacy, fall history, etc.) so the
// IDT can prioritize outreach. Supports filters by risk category.
// IDT = Interdisciplinary Team.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

const rows = ref<any[]>([])
const loading = ref(true)
const filter = ref<string>('all')

onMounted(async () => {
  try {
    const r = await axios.get('/dashboards/high-risk')
    rows.value = r.data.rows ?? []
  } finally { loading.value = false }
})

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
      <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">High-Risk Participants</h1>
        <select v-model="filter" class="rounded border-gray-300 dark:border-slate-600 text-sm">
          <option value="all">All risk types</option>
          <option value="disenrollment">Disenrollment</option>
          <option value="acute_event">Acute event</option>
        </select>
      </div>

      <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs uppercase text-gray-500 dark:text-slate-400">
            <tr>
              <th class="px-3 py-2">Participant</th>
              <th class="px-3 py-2">MRN</th>
              <th class="px-3 py-2">Risk type</th>
              <th class="px-3 py-2">Score</th>
              <th class="px-3 py-2">Band</th>
              <th class="px-3 py-2">Computed</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <tr v-for="r in filtered" :key="r.id">
              <td class="px-3 py-2">
                <Link v-if="r.participant" :href="`/participants/${r.participant.id}`" class="text-blue-600 dark:text-blue-400 hover:underline">
                  {{ r.participant.first_name }} {{ r.participant.last_name }}
                </Link>
              </td>
              <td class="px-3 py-2">{{ r.participant?.mrn }}</td>
              <td class="px-3 py-2">{{ r.risk_type }}</td>
              <td class="px-3 py-2">{{ r.score }}</td>
              <td class="px-3 py-2">
                <span class="inline-block rounded px-2 py-0.5 text-xs" :class="bandColor(r.band)">{{ r.band?.toUpperCase() }}</span>
              </td>
              <td class="px-3 py-2 text-gray-500 dark:text-slate-400">{{ String(r.computed_at).slice(0, 16).replace('T', ' ') }}</td>
            </tr>
            <tr v-if="!loading && filtered.length === 0">
              <td colspan="6" class="px-3 py-4 text-center text-gray-500 dark:text-slate-400">
                No high-risk participants.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
