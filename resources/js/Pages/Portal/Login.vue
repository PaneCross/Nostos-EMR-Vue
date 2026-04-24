<script setup lang="ts">
// Phase I4 — Portal login page (participant + proxy).
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const rateLimitedMsg = ref<string | null>(null)

async function submit() {
  loading.value = true
  error.value = null
  rateLimitedMsg.value = null
  try {
    await axios.post('/portal/login', {
      email: email.value.trim(),
      password: password.value,
    })
    // Session cookie is set; redirect to the portal overview.
    router.visit('/portal/overview')
  } catch (e: any) {
    const code = e?.response?.status
    const body = e?.response?.data ?? {}
    if (code === 429) {
      rateLimitedMsg.value = body.message ?? 'Too many attempts. Please wait and try again.'
    } else {
      error.value = body.error === 'invalid_credentials'
        ? 'Email or password is incorrect.'
        : (body.message ?? 'Login failed. Please try again.')
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Head title="Participant Portal — Sign in" />
  <div class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-4">
    <div class="w-full max-w-md">
      <div class="text-center mb-6">
        <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Participant Portal</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Sign in to view your records, messages, and upcoming visits.</p>
      </div>

      <form @submit.prevent="submit" class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-sm p-6 space-y-4">
        <div>
          <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Email</label>
          <input
            v-model="email"
            type="email"
            required
            autocomplete="email"
            autofocus
            class="mt-1 w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-slate-100"
            data-testid="portal-login-email"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Password</label>
          <input
            v-model="password"
            type="password"
            required
            autocomplete="current-password"
            class="mt-1 w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-slate-100"
            data-testid="portal-login-password"
          />
        </div>

        <div v-if="error" class="text-xs text-red-600 dark:text-red-400" data-testid="portal-login-error">
          {{ error }}
        </div>
        <div v-if="rateLimitedMsg" class="text-xs text-amber-600 dark:text-amber-400">
          {{ rateLimitedMsg }}
        </div>

        <button
          type="submit"
          :disabled="loading"
          class="w-full inline-flex justify-center items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
        >
          {{ loading ? 'Signing in…' : 'Sign in' }}
        </button>

        <p class="text-xs text-slate-500 dark:text-slate-400 text-center">
          Need help? Contact your care manager or the site front desk.
        </p>
      </form>

      <p class="text-xs text-slate-400 dark:text-slate-500 text-center mt-4">
        Information is end-to-end audited. All access is logged per HIPAA §164.312(b).
      </p>
    </div>
  </div>
</template>
