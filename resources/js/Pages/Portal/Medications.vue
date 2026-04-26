<script setup lang="ts">
// ─── Portal/Medications.vue ──────────────────────────────────────────────────
// Participant Portal page. Read-only list of the participant's active
// medications (drug, dose, sig, prescriber). Refill requests are submitted
// from Portal/Requests.vue, not from this page.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'
import PortalShell from './PortalShell.vue'
const meds = ref<any[]>([])
const loading = ref(true)
onMounted(() => axios.get('/portal/medications').then(r => meds.value = r.data.medications ?? []).finally(() => loading.value = false))
</script>
<template>
  <PortalShell title="Medications">
    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100 mb-3">Active Medications</h2>
    <div class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden">
      <ul class="divide-y divide-slate-200 dark:divide-slate-700">
        <li v-for="m in meds" :key="m.id" class="px-4 py-3">
          <div class="font-medium text-slate-900 dark:text-slate-100">{{ m.drug_name }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400">
            {{ m.dose ? `${m.dose} ${m.dose_unit}` : '' }}{{ m.route ? ` · ${m.route}` : '' }}{{ m.frequency ? ` · ${m.frequency}` : '' }}
          </div>
        </li>
        <li v-if="!loading && meds.length === 0" class="px-4 py-6 text-center text-sm text-slate-500">No active medications.</li>
      </ul>
    </div>
  </PortalShell>
</template>
