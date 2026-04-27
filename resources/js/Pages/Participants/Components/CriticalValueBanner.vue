<script setup lang="ts">
// ─── CriticalValueBanner.vue: Phase J5 ──────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps<{ participant: { id: number } }>()

const pending = ref<any[]>([])
const loading = ref(true)
const acking = ref<number | null>(null)

function refresh() {
  loading.value = true
  axios.get(`/participants/${props.participant.id}/critical-values`)
    .then(r => pending.value = r.data.pending ?? [])
    .finally(() => loading.value = false)
}
onMounted(refresh)

async function acknowledge(id: number) {
  const text = prompt('Action taken (required, 5+ chars):')
  if (!text || text.length < 5) return
  acking.value = id
  try {
    await axios.post(`/critical-values/${id}/acknowledge`, { action_taken_text: text })
    refresh()
  } catch (e: any) {
    alert(e.response?.data?.message ?? 'Failed')
  } finally { acking.value = null }
}

function deadlineOverdue(p: any): boolean {
  return p.deadline_at && new Date(p.deadline_at).getTime() < Date.now()
}
</script>

<template>
  <div v-if="pending.length" class="rounded border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/30 p-3 space-y-1">
    <h3 class="text-sm font-semibold text-red-800 dark:text-red-200">
      Pending critical-value acknowledgments ({{ pending.length }})
    </h3>
    <ul class="space-y-1 text-sm">
      <li v-for="p in pending" :key="p.id" class="flex items-center justify-between">
        <span class="text-red-900 dark:text-red-100">
          {{ p.field_name }} = <span class="font-semibold">{{ p.value }}</span>
          · {{ p.severity }}
          <span v-if="deadlineOverdue(p)" class="ml-2 text-xs font-bold text-red-700 dark:text-red-300">OVERDUE</span>
          <span v-else class="ml-2 text-xs text-red-700 dark:text-red-300">
            deadline {{ p.deadline_at?.slice(0, 16).replace('T', ' ') }}
          </span>
        </span>
        <button
          :disabled="acking === p.id"
          class="rounded bg-red-600 px-2 py-1 text-xs text-white hover:bg-red-700 disabled:opacity-50"
          @click="acknowledge(p.id)"
        >
          {{ acking === p.id ? '…' : 'Acknowledge' }}
        </button>
      </li>
    </ul>
  </div>
</template>
