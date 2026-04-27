<script setup lang="ts">
// ─── HospiceTab.vue ────────────────────────────────────────────────────────
// Hospice lifecycle for a PACE participant: refer → enroll → IDT
// (Interdisciplinary Team) review → death, plus bereavement contacts
// for family at day-15 / day-30 / month-3 after death. PACE may carry
// hospice in-house or refer out: this tab covers both paths.
//
// Notable rules: enrolling auto-creates a 5-order comfort-care bundle.
// Recording death also auto-disenrolls the participant.
// ───────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

interface Participant {
  id: number
  hospice_status?: string | null
  hospice_referred_at?: string | null
  hospice_started_at?: string | null
  hospice_last_idt_review_at?: string | null
  hospice_provider_text?: string | null
  hospice_diagnosis_text?: string | null
  date_of_death?: string | null
}
const props = defineProps<{ participant: Participant }>()

const state = ref<Participant>({ ...props.participant })
const bereavementContacts = ref<any[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

async function refresh() {
  loading.value = true
  try {
    const r = await axios.get(`/participants/${props.participant.id}`)
    state.value = r.data.participant ?? r.data ?? state.value
    if (state.value.hospice_status === 'deceased') {
      const b = await axios.get(`/participants/${props.participant.id}/bereavement-contacts`)
      bereavementContacts.value = b.data.contacts ?? []
    }
  } catch { /* keep prop state */ }
  finally { loading.value = false }
}
onMounted(refresh)

const statusColor = computed(() => {
  switch (state.value.hospice_status) {
    case 'referred':  return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
    case 'enrolled':  return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
    case 'graduated': return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
    case 'deceased':  return 'bg-gray-700 text-white dark:bg-slate-700'
    default:          return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
  }
})

const idtClockWarning = computed(() => {
  if (state.value.hospice_status !== 'enrolled') return null
  const last = state.value.hospice_last_idt_review_at
  const base = last ?? state.value.hospice_started_at
  if (!base) return null
  const days = Math.floor((Date.now() - new Date(base).getTime()) / 86400000)
  if (days >= 180) return { text: `IDT review ${days - 180}d overdue`, color: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' }
  if (days >= 150) return { text: `IDT review due in ${180 - days}d`, color: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' }
  return { text: `${days}d since last review`, color: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' }
})

async function callLifecycle(action: string) {
  error.value = null
  const needsData: Record<string, () => any> = {
    refer: () => ({ hospice_provider_text: prompt('Provider (optional)') || null, hospice_diagnosis_text: prompt('Diagnosis (optional)') || null }),
    enroll: () => ({
      hospice_started_at: new Date().toISOString().slice(0, 10),
      hospice_provider_text: state.value.hospice_provider_text || null,
      hospice_diagnosis_text: state.value.hospice_diagnosis_text || null,
    }),
    'idt-review': () => ({ notes: prompt('IDT review notes (optional)') || null }),
    death: () => {
      const dod = prompt('Date of death (YYYY-MM-DD)?', new Date().toISOString().slice(0, 10))
      if (!dod) return null
      return { date_of_death: dod }
    },
  }
  const payload = needsData[action]?.() ?? {}
  if (payload === null) return
  try {
    await axios.post(`/participants/${props.participant.id}/hospice/${action}`, payload)
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Action failed'
  }
}

async function completeBereavement(id: number, outcome: string) {
  await axios.post(`/bereavement-contacts/${id}/complete`, { outcome })
  refresh()
}
</script>

<template>
  <div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Hospice</h2>
    <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>

    <!-- Status card -->
    <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 space-y-2">
      <div class="flex items-center gap-3">
        <span class="inline-block rounded px-2 py-0.5 text-xs" :class="statusColor">
          {{ state.hospice_status ?? 'none' }}
        </span>
        <span v-if="idtClockWarning" class="inline-block rounded px-2 py-0.5 text-xs" :class="idtClockWarning.color">
          {{ idtClockWarning.text }}
        </span>
      </div>
      <div v-if="state.hospice_provider_text" class="text-sm"><span class="font-semibold">Provider:</span> {{ state.hospice_provider_text }}</div>
      <div v-if="state.hospice_diagnosis_text" class="text-sm"><span class="font-semibold">Diagnosis:</span> {{ state.hospice_diagnosis_text }}</div>
      <div v-if="state.hospice_referred_at" class="text-xs text-gray-500 dark:text-slate-400">Referred {{ state.hospice_referred_at }}</div>
      <div v-if="state.hospice_started_at" class="text-xs text-gray-500 dark:text-slate-400">Enrolled {{ state.hospice_started_at }}</div>
      <div v-if="state.hospice_last_idt_review_at" class="text-xs text-gray-500 dark:text-slate-400">Last IDT {{ state.hospice_last_idt_review_at }}</div>
      <div v-if="state.date_of_death" class="text-xs text-gray-700 dark:text-slate-300 font-semibold">Died {{ state.date_of_death }}</div>
    </div>

    <!-- Actions -->
    <div class="flex flex-wrap gap-2">
      <button
        v-if="!state.hospice_status || state.hospice_status === 'none'"
        class="rounded bg-amber-600 px-3 py-1.5 text-sm text-white hover:bg-amber-700"
        @click="callLifecycle('refer')"
      >Refer</button>
      <button
        v-if="state.hospice_status !== 'deceased' && state.hospice_status !== 'graduated'"
        class="rounded bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700"
        @click="callLifecycle('enroll')"
      >Enroll</button>
      <button
        v-if="state.hospice_status === 'enrolled'"
        class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700"
        @click="callLifecycle('idt-review')"
      >Record IDT review</button>
      <button
        v-if="state.hospice_status !== 'deceased'"
        class="rounded bg-gray-700 px-3 py-1.5 text-sm text-white hover:bg-gray-800"
        @click="callLifecycle('death')"
      >Record death</button>
    </div>

    <!-- Bereavement schedule -->
    <div v-if="state.hospice_status === 'deceased'" class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
      <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-2">Bereavement contacts</h3>
      <table class="min-w-full text-sm">
        <thead class="text-xs uppercase text-gray-500 dark:text-slate-400">
          <tr>
            <th class="px-2 py-1 text-left">Scheduled</th>
            <th class="px-2 py-1 text-left">Kind</th>
            <th class="px-2 py-1 text-left">Status</th>
            <th class="px-2 py-1"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
          <tr v-for="c in bereavementContacts" :key="c.id">
            <td class="px-2 py-1">{{ c.scheduled_at }}</td>
            <td class="px-2 py-1">{{ c.kind }}</td>
            <td class="px-2 py-1">{{ c.status }}</td>
            <td class="px-2 py-1">
              <div v-if="c.status === 'scheduled'" class="flex gap-2">
                <button class="text-xs text-green-600 dark:text-green-400 hover:underline" @click="completeBereavement(c.id, 'completed')">Completed</button>
                <button class="text-xs text-amber-600 dark:text-amber-400 hover:underline" @click="completeBereavement(c.id, 'missed')">Missed</button>
                <button class="text-xs text-gray-600 dark:text-slate-400 hover:underline" @click="completeBereavement(c.id, 'declined')">Declined</button>
              </div>
            </td>
          </tr>
          <tr v-if="!loading && bereavementContacts.length === 0">
            <td colspan="4" class="px-2 py-4 text-center text-gray-500 dark:text-slate-400">No bereavement contacts scheduled.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
