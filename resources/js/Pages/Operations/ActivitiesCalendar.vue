<script setup lang="ts">
// ─── Operations/ActivitiesCalendar.vue: Phase K3 ────────────────────────────
// Day-center activities calendar. Activities staff schedule group programs
// (bingo, exercise, music therapy, outings) here for participants to attend.
// Shows a 7-day window by default; staff can create new events inline.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { PlusIcon } from '@heroicons/vue/24/outline'

const events = ref<any[]>([])
const loading = ref(true)
const from = ref(new Date().toISOString().slice(0, 10))
const to = ref(new Date(Date.now() + 6 * 86400000).toISOString().slice(0, 10))

const showForm = ref(false)
const form = ref({
  title: '',
  category: 'social',
  scheduled_at: new Date().toISOString().slice(0, 16),
  duration_min: 60,
  location: '',
})
const saving = ref(false)
const error = ref<string | null>(null)

function refresh() {
  loading.value = true
  axios.get('/activities', { params: { from: from.value, to: to.value } })
    .then(r => events.value = r.data.events ?? r.data ?? [])
    .finally(() => loading.value = false)
}
onMounted(refresh)

const days = computed(() => {
  const start = new Date(from.value)
  const out = []
  for (let i = 0; i < 7; i++) {
    const d = new Date(start.getTime() + i * 86400000)
    out.push(d.toISOString().slice(0, 10))
  }
  return out
})

function eventsForDay(day: string): any[] {
  return events.value.filter(e => String(e.scheduled_at).startsWith(day))
}

async function submit() {
  saving.value = true
  error.value = null
  try {
    await axios.post('/activities', form.value)
    showForm.value = false
    form.value.title = ''
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Save failed'
  } finally { saving.value = false }
}
</script>

<template>
  <Head title="Activities Calendar" />
  <AppShell>
    <div class="p-6 space-y-4">
      <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Activities Calendar</h1>
        <div class="flex items-center gap-2">
          <input type="date" v-model="from" @change="refresh" class="rounded border-gray-300 dark:border-slate-600 text-sm" />
          <span class="text-xs">→</span>
          <input type="date" v-model="to" @change="refresh" class="rounded border-gray-300 dark:border-slate-600 text-sm" />
          <button class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700" @click="showForm = !showForm">
            <PlusIcon class="h-4 w-4" />
            {{ showForm ? 'Cancel' : 'New activity' }}
          </button>
        </div>
      </div>

      <div v-if="showForm" class="rounded border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-950/40 p-4 space-y-2">
        <input v-model="form.title" placeholder="Title" class="block w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
          <input type="datetime-local" v-model="form.scheduled_at" class="rounded border-gray-300 dark:border-slate-600 text-sm" />
          <input type="number" v-model="form.duration_min" placeholder="Duration (min)" class="rounded border-gray-300 dark:border-slate-600 text-sm" />
          <input v-model="form.location" placeholder="Location" class="rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
        <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>
        <div class="flex justify-end">
          <button :disabled="!form.title || saving" class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="submit">
            {{ saving ? 'Saving…' : 'Create' }}
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-7 gap-2">
        <div
          v-for="d in days"
          :key="d"
          class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-2 min-h-[120px]"
        >
          <div class="text-xs font-semibold text-gray-700 dark:text-slate-300 mb-1">{{ d }}</div>
          <ul class="space-y-1">
            <li
              v-for="e in eventsForDay(d)"
              :key="e.id"
              class="text-xs rounded bg-blue-50 dark:bg-blue-900/40 px-1.5 py-1 text-blue-900 dark:text-blue-200"
            >
              <div class="font-semibold">{{ e.title }}</div>
              <div class="text-[10px] text-gray-600 dark:text-slate-400">
                {{ String(e.scheduled_at).slice(11, 16) }} · {{ e.duration_min }}m
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </AppShell>
</template>
