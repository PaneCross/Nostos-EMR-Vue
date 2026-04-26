<script setup lang="ts">
// ─── Portal/Login.vue — I4 password + L1 OTP ────────────────────────────────
// Login page for the participant + caregiver Portal (separate from the staff
// EMR login). Supports two modes: password and email OTP. Rate-limited.
// ─────────────────────────────────────────────────────────────────────────────
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'

type Mode = 'password' | 'otp'
const mode = ref<Mode>('otp')
const email = ref('')
const password = ref('')
const code = ref('')
const otpSent = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
const rateLimitedMsg = ref<string | null>(null)

async function submitPassword() {
  loading.value = true; error.value = null; rateLimitedMsg.value = null
  try {
    await axios.post('/portal/login', { email: email.value.trim(), password: password.value })
    router.visit('/portal/overview')
  } catch (e: any) {
    const code = e?.response?.status, body = e?.response?.data ?? {}
    if (code === 429) rateLimitedMsg.value = body.message ?? 'Too many attempts.'
    else error.value = body.error === 'invalid_credentials' ? 'Email or password is incorrect.' : (body.message ?? 'Login failed.')
  } finally { loading.value = false }
}

async function sendOtp() {
  loading.value = true; error.value = null; rateLimitedMsg.value = null
  try {
    await axios.post('/portal/otp/send', { email: email.value.trim() })
    otpSent.value = true
  } catch (e: any) {
    const status = e?.response?.status, body = e?.response?.data ?? {}
    if (status === 429) rateLimitedMsg.value = body.message ?? 'Too many requests.'
    else error.value = body.message ?? 'Unable to send code.'
  } finally { loading.value = false }
}

async function verifyOtp() {
  loading.value = true; error.value = null
  try {
    await axios.post('/portal/otp/verify', { email: email.value.trim(), code: code.value })
    router.visit('/portal/overview')
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Invalid or expired code.'
  } finally { loading.value = false }
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

      <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-sm p-6 space-y-4">
        <!-- Mode tabs -->
        <div class="flex gap-1 text-xs">
          <button
            type="button"
            :class="['flex-1 rounded px-3 py-1.5', mode === 'otp' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300']"
            @click="mode = 'otp'"
          >Email code</button>
          <button
            type="button"
            :class="['flex-1 rounded px-3 py-1.5', mode === 'password' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300']"
            @click="mode = 'password'"
          >Password</button>
        </div>

        <!-- Password mode -->
        <form v-if="mode === 'password'" @submit.prevent="submitPassword" class="space-y-4">
          <div>
            <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Email</label>
            <input v-model="email" type="email" required autocomplete="email" autofocus
                   class="mt-1 w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-slate-100"
                   data-testid="portal-login-email" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Password</label>
            <input v-model="password" type="password" required autocomplete="current-password"
                   class="mt-1 w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-slate-100"
                   data-testid="portal-login-password" />
          </div>
          <button type="submit" :disabled="loading" class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
            {{ loading ? 'Signing in…' : 'Sign in' }}
          </button>
        </form>

        <!-- OTP mode -->
        <div v-else class="space-y-4">
          <div>
            <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Email</label>
            <input v-model="email" type="email" required autocomplete="email"
                   :disabled="otpSent"
                   class="mt-1 w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 disabled:opacity-70"
                   data-testid="portal-otp-email" />
          </div>
          <button v-if="!otpSent" :disabled="loading || !email" class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50" @click="sendOtp">
            {{ loading ? 'Sending…' : 'Send code' }}
          </button>
          <template v-else>
            <div>
              <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">6-digit code</label>
              <input v-model="code" inputmode="numeric" maxlength="6" autofocus
                     class="mt-1 w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 tracking-widest text-center"
                     data-testid="portal-otp-code" />
            </div>
            <button :disabled="loading || code.length !== 6" class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50" @click="verifyOtp">
              {{ loading ? 'Verifying…' : 'Verify & sign in' }}
            </button>
            <button class="text-xs text-slate-500 hover:underline" type="button" @click="otpSent = false; code = ''">
              Send to a different email
            </button>
          </template>
        </div>

        <div v-if="error" class="text-xs text-red-600 dark:text-red-400" data-testid="portal-login-error">{{ error }}</div>
        <div v-if="rateLimitedMsg" class="text-xs text-amber-600 dark:text-amber-400">{{ rateLimitedMsg }}</div>

        <p class="text-xs text-slate-500 dark:text-slate-400 text-center">
          Need help? Contact your care manager or the site front desk.
        </p>
      </div>

      <p class="text-xs text-slate-400 dark:text-slate-500 text-center mt-4">
        Information is end-to-end audited. All access is logged per HIPAA §164.312(b).
      </p>
    </div>
  </div>
</template>
