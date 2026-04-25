<script setup lang="ts">
// ─── Compliance/AmendmentRequests.vue — Phase P3 ────────────────────────────
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { ScaleIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{ requests: any[] }>()
const list = ref(props.requests)
const decidingId = ref<number | null>(null)

function statusColor(s: string): string {
  switch (s) {
    case 'pending':       return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
    case 'under_review':  return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
    case 'accepted':      return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
    case 'denied':        return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
    case 'withdrawn':     return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
    default:              return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
  }
}

async function decide(req: any, status: string) {
  let rationale: string | null = null
  if (status === 'denied') {
    rationale = prompt('Reason for denial (required by §164.526(d)(1)(ii)):')
    if (!rationale) return
  } else if (status === 'accepted') {
    rationale = prompt('Acceptance note (optional):') || ''
  }
  decidingId.value = req.id
  try {
    await axios.post(`/amendment-requests/${req.id}/decide`, {
      status, decision_rationale: rationale,
    })
    router.reload({ only: ['requests'] })
  } catch (e: any) {
    alert(e?.response?.data?.message ?? 'Failed')
  } finally { decidingId.value = null }
}

function daysRemaining(deadline: string | null): string {
  if (!deadline) return '—'
  const ms = new Date(deadline).getTime() - Date.now()
  const days = Math.floor(ms / 86400000)
  if (days < 0) return `${Math.abs(days)}d overdue`
  return `${days}d left`
}
</script>

<template>
  <Head title="Amendment Requests (HIPAA §164.526)" />
  <AppShell>
    <div class="p-6 space-y-4">
      <div class="flex items-center gap-2">
        <ScaleIcon class="h-6 w-6 text-blue-600 dark:text-blue-400" />
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Amendment Requests</h1>
      </div>
      <p class="text-sm text-gray-500 dark:text-slate-400">
        HIPAA §164.526 — covered entity has 60 days to decide (30-day extension allowed).
      </p>

      <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs uppercase text-gray-500 dark:text-slate-400">
            <tr>
              <th class="px-3 py-2">Submitted</th>
              <th class="px-3 py-2">Participant</th>
              <th class="px-3 py-2">Target</th>
              <th class="px-3 py-2">Requested change</th>
              <th class="px-3 py-2">Status</th>
              <th class="px-3 py-2">Deadline</th>
              <th class="px-3 py-2">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <tr v-for="r in list" :key="r.id">
              <td class="px-3 py-2">{{ String(r.created_at).slice(0, 10) }}</td>
              <td class="px-3 py-2">
                <span v-if="r.participant" class="font-medium">{{ r.participant.first_name }} {{ r.participant.last_name }}</span>
                <span class="text-xs text-gray-500 ml-1">{{ r.participant?.mrn }}</span>
              </td>
              <td class="px-3 py-2 text-xs">
                <div>{{ r.target_record_type ?? '—' }} <span v-if="r.target_record_id">#{{ r.target_record_id }}</span></div>
                <div class="text-gray-500 dark:text-slate-400">{{ r.target_field_or_section ?? '—' }}</div>
              </td>
              <td class="px-3 py-2 max-w-md truncate text-gray-700 dark:text-slate-300">{{ r.requested_change }}</td>
              <td class="px-3 py-2">
                <span class="inline-block rounded px-2 py-0.5 text-xs" :class="statusColor(r.status)">{{ r.status.replace(/_/g, ' ') }}</span>
              </td>
              <td class="px-3 py-2 text-xs">{{ daysRemaining(r.deadline_at) }}</td>
              <td class="px-3 py-2">
                <div v-if="['pending','under_review'].includes(r.status)" class="flex gap-1">
                  <button class="text-xs text-blue-600 dark:text-blue-400 hover:underline" :disabled="decidingId === r.id" @click="decide(r, 'under_review')">Review</button>
                  <button class="text-xs text-green-600 dark:text-green-400 hover:underline" :disabled="decidingId === r.id" @click="decide(r, 'accepted')">Accept</button>
                  <button class="text-xs text-red-600 dark:text-red-400 hover:underline" :disabled="decidingId === r.id" @click="decide(r, 'denied')">Deny</button>
                </div>
                <span v-else class="text-xs text-gray-400">—</span>
              </td>
            </tr>
            <tr v-if="!list?.length">
              <td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-slate-400">
                No amendment requests pending.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
