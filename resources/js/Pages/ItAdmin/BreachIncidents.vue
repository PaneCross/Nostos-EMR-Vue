<script setup lang="ts">
// ─── ItAdmin/BreachIncidents.vue — Phase P4 ─────────────────────────────────
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{ incidents: any[] }>()
const list = ref(props.incidents)
const showForm = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const form = ref({
  discovered_at: new Date().toISOString().slice(0, 16),
  occurred_at: '',
  affected_count: 1,
  breach_type: 'unauthorized_access',
  description: '',
  root_cause: '',
  mitigation_taken: '',
  state: '',
})

const TYPES = [
  'lost_device', 'email_misdirect', 'unauthorized_access', 'hacking',
  'paper_disposal', 'improper_disclosure', 'other',
]

async function submit() {
  saving.value = true; error.value = null
  try {
    const p: any = { ...form.value }
    if (!p.occurred_at) delete p.occurred_at
    if (!p.state) delete p.state
    await axios.post('/it-admin/breaches', p)
    showForm.value = false
    router.reload({ only: ['incidents'] })
  } catch (e: any) {
    // Phase V4 — extract per-field 422 errors when present.
    const errs = e?.response?.data?.errors ?? null
    error.value = (errs && Object.keys(errs).length)
      ? Object.values(errs).flat().join('; ')
      : (e?.response?.data?.message ?? 'Save failed')
  } finally { saving.value = false }
}

async function markIndividuals(id: number) {
  if (!confirm('Confirm individual notifications were sent.')) return
  await axios.post(`/it-admin/breaches/${id}/individuals-notified`)
  router.reload({ only: ['incidents'] })
}
async function markHhs(id: number) {
  if (!confirm('Confirm HHS submitted via https://ocrportal.hhs.gov/ocr/breach/.')) return
  await axios.post(`/it-admin/breaches/${id}/hhs-notified`)
  router.reload({ only: ['incidents'] })
}

function statusColor(s: string) {
  if (s === 'closed') return 'bg-gray-100 dark:bg-slate-700 text-gray-600'
  if (s === 'hhs_notified') return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
  if (s === 'individuals_notified') return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
  return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
}

function deadline(d: any): string {
  if (!d.hhs_deadline_at) return '—'
  const ms = new Date(d.hhs_deadline_at).getTime() - Date.now()
  const days = Math.floor(ms / 86400000)
  if (days < 0) return `${Math.abs(days)}d OVERDUE`
  return `${days}d`
}
</script>

<template>
  <Head title="Breach Notifications (HIPAA §164.404)" />
  <AppShell>
    <div class="p-6 space-y-4">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <ExclamationTriangleIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
          <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Breach Incidents</h1>
        </div>
        <button class="rounded bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700" @click="showForm = !showForm">
          {{ showForm ? 'Cancel' : 'Log incident' }}
        </button>
      </div>
      <p class="text-sm text-gray-500 dark:text-slate-400">
        HIPAA §164.404 / §164.408 — log breaches affecting PHI. Notify affected individuals + HHS within deadline.
        500+ affected: 60d from discovery. &lt;500: by March 1 of following year.
        HHS portal:
        <a href="https://ocrportal.hhs.gov/ocr/breach/" class="text-blue-600 dark:text-blue-400 underline" target="_blank" rel="noopener">ocrportal.hhs.gov/ocr/breach/</a>
        (manual submission — no automated API).
      </p>

      <div v-if="showForm" class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-4 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div><label class="block text-xs mb-1">Discovered at</label>
            <input type="datetime-local" v-model="form.discovered_at" class="w-full rounded border-slate-300 dark:border-slate-600 text-sm" /></div>
          <div><label class="block text-xs mb-1">Occurred at (optional)</label>
            <input type="datetime-local" v-model="form.occurred_at" class="w-full rounded border-slate-300 dark:border-slate-600 text-sm" /></div>
          <div><label class="block text-xs mb-1">Affected individuals</label>
            <input type="number" min="1" v-model="form.affected_count" class="w-full rounded border-slate-300 dark:border-slate-600 text-sm" /></div>
          <div><label class="block text-xs mb-1">Type</label>
            <select v-model="form.breach_type" class="w-full rounded border-slate-300 dark:border-slate-600 text-sm">
              <option v-for="t in TYPES" :key="t" :value="t">{{ t.replace(/_/g, ' ') }}</option>
            </select></div>
          <div><label class="block text-xs mb-1">State (2-letter)</label>
            <input v-model="form.state" maxlength="2" class="w-full rounded border-slate-300 dark:border-slate-600 text-sm uppercase" /></div>
        </div>
        <textarea v-model="form.description" rows="3" placeholder="Description of breach (required)" class="w-full rounded border-slate-300 dark:border-slate-600 text-sm" />
        <textarea v-model="form.root_cause" rows="2" placeholder="Root cause (optional)" class="w-full rounded border-slate-300 dark:border-slate-600 text-sm" />
        <textarea v-model="form.mitigation_taken" rows="2" placeholder="Mitigation taken (optional)" class="w-full rounded border-slate-300 dark:border-slate-600 text-sm" />
        <div v-if="error" class="text-sm text-red-600">{{ error }}</div>
        <div class="flex justify-end">
          <button :disabled="saving" class="rounded bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700 disabled:opacity-50" @click="submit">
            {{ saving ? 'Saving…' : 'Log breach' }}
          </button>
        </div>
      </div>

      <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs uppercase text-gray-500 dark:text-slate-400">
            <tr>
              <th class="px-3 py-2">Discovered</th>
              <th class="px-3 py-2">Type</th>
              <th class="px-3 py-2">Affected</th>
              <th class="px-3 py-2">Description</th>
              <th class="px-3 py-2">Status</th>
              <th class="px-3 py-2">HHS deadline</th>
              <th class="px-3 py-2">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <tr v-for="i in list" :key="i.id">
              <td class="px-3 py-2">{{ String(i.discovered_at).slice(0, 16).replace('T', ' ') }}</td>
              <td class="px-3 py-2">{{ i.breach_type.replace(/_/g, ' ') }}</td>
              <td class="px-3 py-2">{{ i.affected_count }}</td>
              <td class="px-3 py-2 text-gray-700 dark:text-slate-300 max-w-md truncate">{{ i.description }}</td>
              <td class="px-3 py-2">
                <span class="inline-block rounded px-2 py-0.5 text-xs" :class="statusColor(i.status)">{{ i.status.replace(/_/g, ' ') }}</span>
              </td>
              <td class="px-3 py-2 text-xs">{{ deadline(i) }}</td>
              <td class="px-3 py-2 space-x-2 text-xs">
                <button v-if="!i.individual_notification_sent_at" class="text-blue-600 hover:underline" @click="markIndividuals(i.id)">Mark individuals notified</button>
                <button v-if="!i.hhs_notified_at" class="text-green-600 hover:underline" @click="markHhs(i.id)">Mark HHS notified</button>
              </td>
            </tr>
            <tr v-if="!list?.length">
              <td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-slate-400">
                No breach incidents logged.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
