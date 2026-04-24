<script setup lang="ts">
// ─── DischargeTab.vue ────────────────────────────────────────────────────────
// Phase J3 — Discharge events + 8-item checklist with per-item completion.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'

interface Participant { id: number }
const props = defineProps<{ participant: Participant }>()

const loading = ref(true)
const events = ref<any[]>([])
const showForm = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const form = ref({
  discharge_from_facility: '',
  discharged_on: new Date().toISOString().slice(0, 10),
  readmission_risk_score: '',
  notes: '',
})

function refresh() {
  loading.value = true
  axios.get(`/participants/${props.participant.id}/discharge-events`)
    .then(r => events.value = r.data.events ?? [])
    .finally(() => loading.value = false)
}
onMounted(refresh)

async function submit() {
  saving.value = true
  error.value = null
  try {
    const p: any = { ...form.value }
    if (!p.readmission_risk_score) delete p.readmission_risk_score
    if (!p.notes) delete p.notes
    await axios.post(`/participants/${props.participant.id}/discharge-events`, p)
    showForm.value = false
    form.value = { discharge_from_facility: '', discharged_on: new Date().toISOString().slice(0, 10), readmission_risk_score: '', notes: '' }
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Save failed'
  } finally {
    saving.value = false
  }
}

async function completeItem(eventId: number, key: string) {
  const notes = prompt('Completion note (optional)') ?? ''
  try {
    await axios.post(`/discharge-events/${eventId}/items/${key}/complete`, { notes })
    refresh()
  } catch (e: any) {
    alert(e.response?.data?.message ?? 'Failed')
  }
}

function isOverdue(item: any): boolean {
  if (item.completed_at) return false
  if (!item.due_at) return false
  return new Date(item.due_at).getTime() < Date.now()
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Discharge Events</h2>
      <button type="button" class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" @click="showForm = !showForm">
        <PlusIcon class="h-4 w-4" />
        {{ showForm ? 'Cancel' : 'New discharge event' }}
      </button>
    </div>

    <div v-if="showForm" class="rounded border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-950/40 p-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs mb-1">From facility</label>
          <input type="text" v-model="form.discharge_from_facility" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
        <div>
          <label class="block text-xs mb-1">Discharge date</label>
          <input type="date" v-model="form.discharged_on" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
        <div>
          <label class="block text-xs mb-1">Readmission risk score</label>
          <input type="number" step="0.01" v-model="form.readmission_risk_score" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
      </div>
      <textarea v-model="form.notes" rows="2" class="mt-3 block w-full rounded border-gray-300 dark:border-slate-600 text-sm" placeholder="Notes (optional)" />
      <div v-if="error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ error }}</div>
      <div class="mt-3 flex justify-end">
        <button :disabled="saving" class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="submit">
          {{ saving ? 'Saving…' : 'Create event' }}
        </button>
      </div>
    </div>

    <div v-for="ev in events" :key="ev.id" class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 space-y-3">
      <div class="flex items-baseline justify-between">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">
          Discharge from {{ ev.discharge_from_facility }}
        </h3>
        <span class="text-xs text-gray-500 dark:text-slate-400">{{ ev.discharged_on }}</span>
      </div>
      <ul class="space-y-1">
        <li
          v-for="item in ev.checklist"
          :key="item.key"
          class="flex items-center justify-between border-b border-gray-100 dark:border-slate-700 pb-1 text-sm"
          :class="isOverdue(item) ? 'bg-red-50 dark:bg-red-950/30' : ''"
        >
          <div class="flex items-center gap-2">
            <CheckCircleIcon v-if="item.completed_at" class="h-4 w-4 text-green-600 dark:text-green-400" />
            <span :class="item.completed_at ? 'line-through text-gray-500 dark:text-slate-400' : ''">
              {{ item.label ?? item.key }}
            </span>
            <span v-if="item.owner_dept" class="rounded bg-gray-100 dark:bg-slate-700 px-1.5 py-0.5 text-xs text-gray-700 dark:text-slate-300">
              {{ item.owner_dept }}
            </span>
            <span v-if="isOverdue(item)" class="text-xs text-red-600 dark:text-red-400">
              OVERDUE · due {{ item.due_at?.slice(0, 10) }}
            </span>
            <span v-else-if="item.due_at && !item.completed_at" class="text-xs text-gray-500 dark:text-slate-400">
              due {{ item.due_at?.slice(0, 10) }}
            </span>
          </div>
          <button
            v-if="!item.completed_at"
            class="text-xs text-blue-600 dark:text-blue-400 hover:underline"
            @click="completeItem(ev.id, item.key)"
          >
            Complete
          </button>
          <span v-else class="text-xs text-gray-500 dark:text-slate-400">
            {{ item.completed_at?.slice(0, 10) }}
          </span>
        </li>
      </ul>
    </div>

    <p v-if="!loading && events.length === 0" class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 text-center text-sm text-gray-500 dark:text-slate-400">
      No discharge events on record.
    </p>
  </div>
</template>
