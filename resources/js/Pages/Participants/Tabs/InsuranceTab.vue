<script setup lang="ts">
// ─── Tabs/InsuranceTab.vue ────────────────────────────────────────────────────
// Read-only display of all insurance coverages for the participant. Shows payer
// type, member ID, plan name, effective/term dates, and active status. Insurance
// records are managed through the enrollment workflow, not directly here.
// ─────────────────────────────────────────────────────────────────────────────

interface Insurance {
  id: number
  payer_type: string
  member_id: string | null
  plan_name: string | null
  effective_date: string | null
  term_date: string | null
  is_active: boolean
}

defineProps<{
  insurances: Insurance[]
}>()

const PAYER_LABELS: Record<string, string> = {
  medicare_a: 'Medicare Part A', medicare_b: 'Medicare Part B',
  medicare_d: 'Medicare Part D', medicaid: 'Medicaid', other: 'Other',
}
</script>

<template>
  <div>
    <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Insurance Coverage ({{ insurances.length }})</h3>
    <p v-if="insurances.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-4 text-center">No insurance records on file.</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div
        v-for="ins in insurances"
        :key="ins.id"
        class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-4"
      >
        <div class="flex items-center justify-between mb-2">
          <span class="font-semibold text-sm text-gray-900 dark:text-slate-100">{{ PAYER_LABELS[ins.payer_type] ?? ins.payer_type }}</span>
          <span :class="`text-xs px-2 py-0.5 rounded-full font-medium ${ins.is_active ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' : 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'}`">
            {{ ins.is_active ? 'Active' : 'Inactive' }}
          </span>
        </div>
        <dl class="grid grid-cols-2 gap-2 text-sm">
          <div v-if="ins.member_id">
            <dt class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">Member ID</dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-slate-100 font-mono">{{ ins.member_id }}</dd>
          </div>
          <div v-if="ins.plan_name">
            <dt class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">Plan</dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-slate-100">{{ ins.plan_name }}</dd>
          </div>
          <div v-if="ins.effective_date">
            <dt class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">Effective</dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-slate-100">{{ new Date(ins.effective_date).toLocaleDateString('en-US') }}</dd>
          </div>
          <div v-if="ins.term_date">
            <dt class="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">Term Date</dt>
            <dd class="mt-0.5 text-sm text-gray-900 dark:text-slate-100">{{ new Date(ins.term_date).toLocaleDateString('en-US') }}</dd>
          </div>
        </dl>
      </div>
    </div>
  </div>
</template>
