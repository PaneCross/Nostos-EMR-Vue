<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import PortalShell from './PortalShell.vue'
const rows = ref<any[]>([])
const loading = ref(true)
onMounted(() => axios.get('/portal/allergies').then(r => rows.value = r.data.allergies ?? []).finally(() => loading.value = false))
function sevColor(s: string): string {
  if (s === 'life_threatening' || s === 'severe') return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
  if (s === 'moderate') return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
  return 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300'
}
</script>
<template>
  <PortalShell title="Allergies">
    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100 mb-3">Allergies</h2>
    <div class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden">
      <ul class="divide-y divide-slate-200 dark:divide-slate-700">
        <li v-for="(a, i) in rows" :key="i" class="px-4 py-3 flex items-center justify-between">
          <div>
            <div class="font-medium text-slate-900 dark:text-slate-100">{{ a.allergen_name }}</div>
            <div class="text-xs text-slate-500 dark:text-slate-400">{{ a.reaction_description }}</div>
          </div>
          <span class="inline-block rounded px-2 py-0.5 text-xs" :class="sevColor(a.severity)">{{ a.severity }}</span>
        </li>
        <li v-if="!loading && rows.length === 0" class="px-4 py-6 text-center text-sm text-slate-500">No allergies on record.</li>
      </ul>
    </div>
  </PortalShell>
</template>
