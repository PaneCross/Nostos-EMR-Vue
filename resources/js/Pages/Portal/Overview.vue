<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import PortalShell from './PortalShell.vue'
const data = ref<any>(null)
const loading = ref(true)
onMounted(() => axios.get('/portal/overview').then(r => data.value = r.data).finally(() => loading.value = false))
</script>
<template>
  <PortalShell title="Overview">
    <div v-if="loading" class="text-sm text-slate-500">Loading…</div>
    <div v-else-if="data" class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-6 space-y-2">
      <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">
        {{ data.participant?.first_name }} {{ data.participant?.last_name }}
      </h2>
      <div class="text-sm text-slate-600 dark:text-slate-300">
        MRN: <span class="font-mono">{{ data.participant?.mrn }}</span>
      </div>
      <div class="text-sm text-slate-600 dark:text-slate-300">DOB: {{ data.participant?.dob }}</div>
      <div v-if="data.is_proxy" class="rounded bg-amber-50 dark:bg-amber-950/30 text-amber-800 dark:text-amber-200 text-xs px-3 py-2">
        You are signed in as a proxy · scope: <span class="font-semibold">{{ data.scope }}</span>
      </div>
    </div>
  </PortalShell>
</template>
