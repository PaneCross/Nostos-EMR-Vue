<script setup lang="ts">
// ─── Portal/PortalShell.vue ──────────────────────────────────────────────────
// Layout shell for all Participant Portal pages. Provides the portal-only
// header + nav (Overview / Medications / Allergies / Problems / Appointments
// / Messages / Requests) and a logout action. Distinct from the staff
// AppShell — portal users never see staff navigation or PHI for other people.
// ─────────────────────────────────────────────────────────────────────────────
import { Link, Head, router } from '@inertiajs/vue3'
import axios from 'axios'
defineProps<{ title?: string }>()

async function logout() {
  try { await axios.post('/portal/logout') } catch {}
  router.visit('/portal/login')
}

const NAV = [
  { href: '/portal/overview', label: 'Overview' },
  { href: '/portal/medications', label: 'Medications' },
  { href: '/portal/allergies', label: 'Allergies' },
  { href: '/portal/problems', label: 'Problems' },
  { href: '/portal/appointments', label: 'Appointments' },
  { href: '/portal/messages', label: 'Messages' },
  { href: '/portal/requests', label: 'Requests' },
]
</script>

<template>
  <Head :title="title ?? 'Participant Portal'" />
  <div class="min-h-screen bg-slate-50 dark:bg-slate-950">
    <header class="bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
      <div class="max-w-4xl mx-auto flex items-center justify-between px-4 py-3">
        <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Participant Portal</h1>
        <button class="text-xs text-slate-500 hover:text-slate-700 dark:text-slate-400 hover:underline" @click="logout">Sign out</button>
      </div>
      <nav class="max-w-4xl mx-auto flex gap-1 px-4 overflow-x-auto">
        <Link
          v-for="n in NAV"
          :key="n.href"
          :href="n.href"
          class="whitespace-nowrap rounded-t-md px-3 py-2 text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700"
        >
          {{ n.label }}
        </Link>
      </nav>
    </header>
    <main class="max-w-4xl mx-auto p-4">
      <slot />
    </main>
  </div>
</template>
