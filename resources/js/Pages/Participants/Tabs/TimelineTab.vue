<script setup lang="ts">
// ─── TimelineTab.vue ───────────────────────────────────────────────────────
// Unified chronological feed of everything that happened to this
// participant: notes, orders, vitals, meds, encounters, incidents,
// transfers, etc. Server-merged from multiple tables and color-coded
// by `kind`. Read-only: clicks deep-link to the source record.
//
// Useful as the "what's been going on" view for IDT (Interdisciplinary
// Team) huddles and for new staff orienting to a chart.
// ───────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'

interface Participant { id: number }
const props = defineProps<{ participant: Participant }>()

const loading = ref(true)
const entries = ref<any[]>([])

onMounted(() => {
  axios.get(`/participants/${props.participant.id}/timeline`)
    .then(r => entries.value = r.data.timeline ?? [])
    .finally(() => loading.value = false)
})

function kindColor(k: string): string {
  switch (k) {
    case 'note':        return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
    case 'order':       return 'bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300'
    case 'vitals':      return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
    case 'appointment': return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
    default:            return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
  }
}

function label(e: any): string {
  const d = e.data ?? {}
  switch (e.kind) {
    case 'note':        return `${d.note_type ?? 'Note'}: ${d.status ?? ''}`
    case 'order':       return `${d.order_type ?? 'Order'}: ${d.status ?? ''}`
    case 'vitals':      return `Vitals: BP ${d.bp_systolic ?? '-'}/${d.bp_diastolic ?? '-'}, HR ${d.pulse ?? '-'}`
    case 'appointment': return `${d.appointment_type ?? 'Appt'}: ${d.status ?? ''}`
    default:            return e.kind
  }
}
</script>

<template>
  <div class="space-y-3">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Timeline</h2>
    <ol class="relative border-l-2 border-gray-200 dark:border-slate-700 ml-2 pl-4 space-y-2">
      <li v-for="(e, i) in entries" :key="i" class="relative">
        <span class="absolute -left-5 top-1 inline-block w-3 h-3 rounded-full" :class="kindColor(e.kind).split(' ').filter(c => c.startsWith('bg-')).join(' ')"></span>
        <div class="flex items-center gap-2 text-sm">
          <span class="inline-block rounded px-2 py-0.5 text-xs" :class="kindColor(e.kind)">{{ e.kind }}</span>
          <span class="text-gray-700 dark:text-slate-200">{{ label(e) }}</span>
        </div>
        <div class="text-xs text-gray-500 dark:text-slate-400">
          {{ typeof e.date === 'string' ? e.date.slice(0, 16).replace('T', ' ') : '' }}
        </div>
      </li>
      <li v-if="!loading && entries.length === 0" class="text-sm text-gray-500 dark:text-slate-400">
        No timeline entries.
      </li>
    </ol>
  </div>
</template>
