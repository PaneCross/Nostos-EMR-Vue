<script setup lang="ts">
// ─── Pharmacy/PriorAuthQueue.vue — Phase P6 ─────────────────────────────────
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'

const props = defineProps<{ requests: any[] }>()
const list = ref(props.requests)
const transitioning = ref<number | null>(null)

function statusColor(s: string): string {
  switch (s) {
    case 'draft':     return 'bg-gray-100 dark:bg-slate-700 text-gray-700'
    case 'submitted': return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
    case 'approved':  return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
    case 'denied':    return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
    default:          return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
  }
}

async function transition(req: any, status: string) {
  let payload: any = { status }
  if (status === 'denied') {
    const r = prompt('Reason for denial:'); if (!r) return
    payload.decision_rationale = r
  } else if (status === 'approved') {
    const ref = prompt('Approval reference (vendor / payer #):') ?? ''
    const exp = prompt('Expiration date (YYYY-MM-DD):') ?? ''
    if (ref) payload.approval_reference = ref
    if (exp) payload.expiration_date = exp
  }
  transitioning.value = req.id
  try {
    await axios.post(`/prior-auth/${req.id}/transition`, payload)
    router.reload({ only: ['requests'] })
  } catch (e: any) {
    alert(e?.response?.data?.message ?? 'Failed')
  } finally { transitioning.value = null }
}
</script>

<template>
  <Head title="Prior Authorization Queue" />
  <AppShell>
    <div class="p-6 space-y-4">
      <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Prior Authorization Queue</h1>
      <div class="rounded bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-700 p-3 text-xs text-amber-800 dark:text-amber-200">
        Internal queue only. Submission to payers is a manual portal process today; real per-payer integration is paywall-gated (see paywall report item 17).
      </div>

      <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs uppercase text-gray-500 dark:text-slate-400">
            <tr>
              <th class="px-3 py-2">Participant</th>
              <th class="px-3 py-2">For</th>
              <th class="px-3 py-2">Payer</th>
              <th class="px-3 py-2">Urgency</th>
              <th class="px-3 py-2">Status</th>
              <th class="px-3 py-2">Submitted</th>
              <th class="px-3 py-2">Decision</th>
              <th class="px-3 py-2">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <tr v-for="r in list" :key="r.id">
              <td class="px-3 py-2">{{ r.participant?.first_name }} {{ r.participant?.last_name }}</td>
              <td class="px-3 py-2 text-xs">{{ r.related_to_type }} #{{ r.related_to_id }}</td>
              <td class="px-3 py-2">{{ r.payer_type.replace(/_/g, ' ') }}</td>
              <td class="px-3 py-2">
                <span v-if="r.urgency === 'expedited'" class="text-xs text-red-600 dark:text-red-400 font-semibold">Expedited</span>
                <span v-else class="text-xs text-gray-500">Standard</span>
              </td>
              <td class="px-3 py-2">
                <span class="inline-block rounded px-2 py-0.5 text-xs" :class="statusColor(r.status)">{{ r.status }}</span>
              </td>
              <td class="px-3 py-2 text-xs">{{ r.submitted_at?.slice(0, 10) ?? '—' }}</td>
              <td class="px-3 py-2 text-xs">{{ r.decision_at?.slice(0, 10) ?? '—' }}</td>
              <td class="px-3 py-2 space-x-2 text-xs">
                <button v-if="r.status === 'draft'" class="text-blue-600 hover:underline" @click="transition(r, 'submitted')">Submit</button>
                <button v-if="r.status === 'submitted'" class="text-green-600 hover:underline" @click="transition(r, 'approved')">Approve</button>
                <button v-if="r.status === 'submitted'" class="text-red-600 hover:underline" @click="transition(r, 'denied')">Deny</button>
                <button v-if="['draft','submitted'].includes(r.status)" class="text-gray-500 hover:underline" @click="transition(r, 'withdrawn')">Withdraw</button>
              </td>
            </tr>
            <tr v-if="!list?.length">
              <td colspan="8" class="px-3 py-8 text-center text-gray-500 dark:text-slate-400">No prior-auth requests pending.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
