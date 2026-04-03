<script setup lang="ts">
// Tabs/CarePlanTab.vue
// PACE care plans with goals and interdisciplinary team interventions.
// Active plans editable in draft/under_review state; archived versions read-only.

import { ref, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps<{
  participantId: number
}>()

const items   = ref<Record<string, unknown>[]>([])
const loading = ref(true)
const error   = ref<string | null>(null)

async function loadData() {
  loading.value = true
  try {
    const r = await axios.get(`/participants/${props.participantId}/care-plans`)
    items.value = r.data.data ?? r.data
  } catch {
    error.value = 'Failed to load data. Please refresh.'
  } finally {
    loading.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">
        Care Plans ({{ items.length }})
      </h3>
      <button
        class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors opacity-50 cursor-not-allowed"
        disabled
        aria-label="Add new record (coming soon)"
      >+ Add</button>
    </div>

    <div v-if="loading" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm animate-pulse">Loading...</div>
    <div v-else-if="error" class="py-8 text-center text-red-500 text-sm">{{ error }}</div>
    <p v-else-if="items.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">No records on file.</p>
    <div v-else class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
      <pre class="p-4 text-xs text-gray-600 dark:text-slate-300 overflow-x-auto">{{ JSON.stringify(items.slice(0, 3), null, 2) }}</pre>
      <p v-if="items.length > 3" class="px-4 py-2 text-xs text-gray-400 dark:text-slate-500 border-t border-gray-100 dark:border-slate-700">...and {{ items.length - 3 }} more records</p>
    </div>
  </div>
</template>
