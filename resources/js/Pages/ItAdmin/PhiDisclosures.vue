<script setup lang="ts">
// ─── ItAdmin/PhiDisclosures ─────────────────────────────────────────────────
// HIPAA "Accounting of Disclosures" viewer — every time PHI was shared with
// an outside party (other than for treatment/payment/operations) is logged
// here, so a participant can request the list at any time.
//
// Audience: IT Admin / Privacy Officer.
//
// Notable rules:
//   - HIPAA §164.528 — covered entity must be able to produce a list of
//     disclosures for the previous 6 years on participant request.
//   - Append-only — entries are immutable. Captured automatically by the
//     PhiDisclosureService when CCDA / FHIR / Bulk Export / amendment
//     §164.526(c)(3) downstream notifications fire.
// ────────────────────────────────────────────────────────────────────────────
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { ShieldCheckIcon } from '@heroicons/vue/24/outline'

defineProps<{ disclosures: any }>()

function recipientColor(t: string): string {
  switch (t) {
    case 'patient_self': return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
    case 'insurer':      return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
    case 'public_health':return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
    case 'legal':        return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
    case 'hie':          return 'bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300'
    default:             return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
  }
}
</script>

<template>
  <Head title="PHI Disclosures Log" />
  <AppShell>
    <div class="p-6 space-y-4">
      <div class="flex items-center gap-2">
        <ShieldCheckIcon class="h-6 w-6 text-blue-600 dark:text-blue-400" />
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">PHI Accounting of Disclosures</h1>
      </div>
      <p class="text-sm text-gray-500 dark:text-slate-400">
        HIPAA §164.528 — every disclosure of protected health information to a third party.
        Accounting period is 6 years; older entries are excluded by scope.
      </p>

      <div class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 dark:bg-slate-900 text-left text-xs uppercase text-gray-500 dark:text-slate-400">
            <tr>
              <th class="px-3 py-2">Disclosed at</th>
              <th class="px-3 py-2">Participant</th>
              <th class="px-3 py-2">Recipient</th>
              <th class="px-3 py-2">Method</th>
              <th class="px-3 py-2">Purpose</th>
              <th class="px-3 py-2">Records</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <tr v-for="d in disclosures.data" :key="d.id">
              <td class="px-3 py-2">{{ String(d.disclosed_at).slice(0, 16).replace('T', ' ') }}</td>
              <td class="px-3 py-2">
                <span v-if="d.participant" class="font-medium text-gray-900 dark:text-slate-100">
                  {{ d.participant.first_name }} {{ d.participant.last_name }}
                </span>
                <span class="text-xs text-gray-500 dark:text-slate-400 ml-1">{{ d.participant?.mrn }}</span>
              </td>
              <td class="px-3 py-2">
                <span class="inline-block rounded px-2 py-0.5 text-xs mr-1" :class="recipientColor(d.recipient_type)">
                  {{ d.recipient_type.replace(/_/g, ' ') }}
                </span>
                {{ d.recipient_name }}
              </td>
              <td class="px-3 py-2 text-gray-500 dark:text-slate-400">{{ d.disclosure_method }}</td>
              <td class="px-3 py-2 text-gray-700 dark:text-slate-300 max-w-md truncate">{{ d.disclosure_purpose }}</td>
              <td class="px-3 py-2 text-xs text-gray-600 dark:text-slate-400 max-w-xs truncate">{{ d.records_described }}</td>
            </tr>
            <tr v-if="!disclosures.data?.length">
              <td colspan="6" class="px-3 py-8 text-center text-gray-500 dark:text-slate-400">
                No disclosures recorded in the 6-year accounting period.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppShell>
</template>
