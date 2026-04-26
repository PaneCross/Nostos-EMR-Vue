<script setup lang="ts">
// ─── Portal/Problems.vue ─────────────────────────────────────────────────────
// Participant Portal page. Read-only view of the participant's active problem
// list (ICD-10 coded diagnoses). Corrections go through the amendment workflow
// (HIPAA §164.526 right to amend).
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'
import PortalShell from './PortalShell.vue'
const rows = ref<any[]>([])
const loading = ref(true)
onMounted(() => axios.get('/portal/problems').then(r => rows.value = r.data.problems ?? []).finally(() => loading.value = false))
</script>
<template>
  <PortalShell title="Problems">
    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100 mb-3">Problems</h2>
    <div class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden">
      <ul class="divide-y divide-slate-200 dark:divide-slate-700">
        <li v-for="(p, i) in rows" :key="i" class="px-4 py-3">
          <div class="text-xs font-mono text-slate-500 dark:text-slate-400">{{ p.icd10_code }}</div>
          <div class="text-slate-900 dark:text-slate-100">{{ p.icd10_description }}</div>
          <div v-if="p.onset_date" class="text-xs text-slate-500 dark:text-slate-400">Onset: {{ p.onset_date }}</div>
        </li>
        <li v-if="!loading && rows.length === 0" class="px-4 py-6 text-center text-sm text-slate-500">No active problems.</li>
      </ul>
    </div>
  </PortalShell>
</template>
