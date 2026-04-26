<script setup lang="ts">
// ─── Portal/Appointments.vue ─────────────────────────────────────────────────
// Participant Portal page. Lists upcoming + past appointments. Participant can
// submit an appointment-request (free-text reason) which becomes a staff task
// for scheduling. Direct booking is intentionally NOT supported — IDT routes.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'
import PortalShell from './PortalShell.vue'
const rows = ref<any[]>([])
const loading = ref(true)
const submitting = ref(false)
function refresh() { axios.get('/portal/appointments').then(r => rows.value = r.data.appointments ?? []).finally(() => loading.value = false) }
onMounted(refresh)

async function requestAppointment() {
  const reason = prompt('Reason for the visit?')
  if (!reason) return
  submitting.value = true
  try {
    await axios.post('/portal/requests', { request_type: 'appointment', payload: { reason } })
    alert('Request submitted. Your care team will follow up.')
  } catch (e: any) {
    alert(e?.response?.data?.message ?? 'Failed')
  } finally { submitting.value = false }
}
</script>
<template>
  <PortalShell title="Appointments">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Appointments</h2>
      <button :disabled="submitting" class="rounded bg-blue-600 text-white text-xs px-3 py-1.5 hover:bg-blue-700 disabled:opacity-50" @click="requestAppointment">
        Request appointment
      </button>
    </div>
    <div class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden">
      <ul class="divide-y divide-slate-200 dark:divide-slate-700">
        <li v-for="a in rows" :key="a.id" class="px-4 py-3">
          <div class="flex items-baseline justify-between">
            <div class="font-medium text-slate-900 dark:text-slate-100">{{ a.appointment_type ?? 'Visit' }}</div>
            <div class="text-xs text-slate-500 dark:text-slate-400">
              {{ String(a.scheduled_at ?? a.scheduled_start ?? '').slice(0, 16).replace('T', ' ') }}
            </div>
          </div>
          <div class="text-xs text-slate-500 dark:text-slate-400">{{ a.status }}</div>
        </li>
        <li v-if="!loading && rows.length === 0" class="px-4 py-6 text-center text-sm text-slate-500">No recent appointments.</li>
      </ul>
    </div>
  </PortalShell>
</template>
