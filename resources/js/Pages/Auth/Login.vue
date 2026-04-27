<script setup lang="ts">
// ─── Login.vue ─────────────────────────────────────────────────────────────────
// Two-step OTP login page. Step 1: email entry + social OAuth. Step 2: 6-digit
// code entry with resend countdown and back navigation.
// OAuth providers: Google and Yahoo (both route through /auth/{provider}/redirect).
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onUnmounted } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import axios from 'axios'

const page = usePage<{ errors: { oauth?: string } }>()

type Step = 'email' | 'otp'

const step = ref<Step>('email')
const email = ref('')
const code = ref('')
const loading = ref(false)
const error = ref<string | null>(page.props.errors?.oauth ?? null)
const success = ref<string | null>(null)
const countdown = ref(0)

// Countdown timer: ticks down from 60 when a code is sent
let countdownTimer: ReturnType<typeof setInterval> | null = null

function startCountdown() {
    countdown.value = 60
    countdownTimer = setInterval(() => {
        if (countdown.value > 0) {
            countdown.value--
        } else {
            clearInterval(countdownTimer!)
        }
    }, 1000)
}

onUnmounted(() => {
    if (countdownTimer) clearInterval(countdownTimer)
})

// Strip non-digits, cap at 6 characters
function handleCodeInput(e: Event) {
    const val = (e.target as HTMLInputElement).value.replace(/\D/g, '').slice(0, 6)
    code.value = val
    ;(e.target as HTMLInputElement).value = val
}

const canSubmitCode = computed(() => code.value.length === 6 && !loading.value)

async function requestOtp() {
    if (!email.value.trim()) return
    loading.value = true
    error.value = null
    try {
        await axios.post('/auth/request-otp', { email: email.value })
        step.value = 'otp'
        success.value = `A 6-digit sign-in code was sent to ${email.value}`
        startCountdown()
    } catch (e: unknown) {
        error.value =
            (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
            'Something went wrong. Please try again.'
    } finally {
        loading.value = false
    }
}

async function verifyOtp() {
    if (!canSubmitCode.value) return
    loading.value = true
    error.value = null
    try {
        const response = await axios.post('/auth/verify-otp', { email: email.value, code: code.value })
        window.location.href = response.data.redirect
    } catch (e: unknown) {
        error.value =
            (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
            'Invalid code. Please try again.'
        code.value = ''
    } finally {
        loading.value = false
    }
}

async function resendCode() {
    if (countdown.value > 0 || loading.value) return
    loading.value = true
    error.value = null
    try {
        await axios.post('/auth/request-otp', { email: email.value })
        success.value = 'A new code has been sent.'
        startCountdown()
    } catch (e: unknown) {
        error.value =
            (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
            'Failed to resend. Please try again.'
    } finally {
        loading.value = false
    }
}

function backToEmail() {
    step.value = 'email'
    error.value = null
    success.value = null
    code.value = ''
}
</script>

<template>
    <Head title="Sign In" />

    <div class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-blue-950 flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <!-- Card -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl overflow-hidden">

                <!-- Dark header panel -->
                <div class="bg-slate-900 px-8 py-8 text-center">
                    <div class="inline-flex items-center justify-center w-14 h-14 bg-blue-600 rounded-xl mb-4">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-white tracking-tight">
                        Nostos<span class="text-blue-400">EMR</span>
                    </h1>
                    <p class="text-slate-400 text-sm mt-1">PACE Electronic Medical Records</p>
                </div>

                <!-- Form area -->
                <div class="px-8 py-8">

                    <!-- ── Step 1: Email ── -->
                    <template v-if="step === 'email'">
                        <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-200 mb-1">
                            Sign in to your account
                        </h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                            Enter your work email to receive a sign-in code
                        </p>

                        <!-- Error -->
                        <div
                            v-if="error"
                            class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm flex items-start gap-2"
                        >
                            <svg class="w-4 h-4 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                            {{ error }}
                        </div>

                        <form class="space-y-4" @submit.prevent="requestOtp">
                            <div>
                                <label
                                    for="email"
                                    class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                                >
                                    Work Email Address
                                </label>
                                <input
                                    id="email"
                                    v-model="email"
                                    type="email"
                                    autocomplete="email"
                                    required
                                    autofocus
                                    placeholder="you@yourorganization.com"
                                    class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-800 dark:text-slate-200 text-sm placeholder:text-slate-400"
                                />
                            </div>

                            <button
                                type="submit"
                                :disabled="loading || !email"
                                class="w-full bg-blue-600 text-white rounded-lg py-2.5 px-4 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center justify-center gap-2"
                            >
                                <svg v-if="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                                Send Sign-In Code
                            </button>
                        </form>

                        <!-- Divider -->
                        <div class="relative my-6">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-slate-200 dark:border-slate-700" />
                            </div>
                            <div class="relative flex justify-center">
                                <span class="bg-white dark:bg-slate-800 px-3 text-xs text-slate-400">or continue with</span>
                            </div>
                        </div>

                        <!-- Social OAuth buttons -->
                        <div class="grid grid-cols-2 gap-3">
                            <a
                                href="/auth/google/redirect"
                                class="flex items-center justify-center gap-2 border border-slate-300 dark:border-slate-600 rounded-lg py-2.5 px-3 text-sm text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors font-medium"
                                aria-label="Sign in with Google"
                            >
                                <svg class="w-4 h-4" viewBox="0 0 24 24" aria-hidden="true">
                                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                                </svg>
                                Google
                            </a>
                            <a
                                href="/auth/yahoo/redirect"
                                class="flex items-center justify-center gap-2 border border-slate-300 dark:border-slate-600 rounded-lg py-2.5 px-3 text-sm text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors font-medium"
                                aria-label="Sign in with Yahoo"
                            >
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="#6001D2" aria-hidden="true">
                                    <path d="M0 0l6.25 12.75L0 24h4.5l3.75-7.5L12 24h4.5L6.75 0H0zm18 0l-4.5 9h4.5L24 0h-6z" />
                                </svg>
                                Yahoo
                            </a>
                        </div>
                    </template>

                    <!-- ── Step 2: OTP Code ── -->
                    <template v-else>
                        <!-- Back button -->
                        <button
                            type="button"
                            class="flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 mb-4 transition-colors"
                            aria-label="Back to email step"
                            @click="backToEmail"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                            </svg>
                            Back
                        </button>

                        <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-200 mb-1">
                            Enter your sign-in code
                        </h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                            We sent a 6-digit code to
                            <span class="font-medium text-slate-700 dark:text-slate-300">{{ email }}</span>.
                            It expires in 10 minutes.
                        </p>

                        <!-- Success -->
                        <div
                            v-if="success"
                            class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-sm"
                        >
                            {{ success }}
                        </div>

                        <!-- Error -->
                        <div
                            v-if="error"
                            class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm"
                        >
                            {{ error }}
                        </div>

                        <form class="space-y-4" @submit.prevent="verifyOtp">
                            <div>
                                <label
                                    for="code"
                                    class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                                >
                                    6-Digit Code
                                </label>
                                <input
                                    id="code"
                                    :value="code"
                                    type="text"
                                    inputmode="numeric"
                                    pattern="\d{6}"
                                    maxlength="6"
                                    autocomplete="one-time-code"
                                    aria-label="One-time code"
                                    placeholder="000000"
                                    class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-800 dark:text-slate-200 text-2xl text-center tracking-[0.5em] font-mono placeholder:text-slate-300 placeholder:tracking-normal placeholder:text-base"
                                    @input="handleCodeInput"
                                />
                            </div>

                            <button
                                type="submit"
                                :disabled="!canSubmitCode"
                                class="w-full bg-blue-600 text-white rounded-lg py-2.5 px-4 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center justify-center gap-2"
                            >
                                <svg v-if="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                                Verify Code &amp; Sign In
                            </button>
                        </form>

                        <!-- Resend -->
                        <div class="text-center mt-4">
                            <button
                                type="button"
                                :disabled="countdown > 0 || loading"
                                class="text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 disabled:text-slate-400 disabled:cursor-not-allowed transition-colors"
                                @click="resendCode"
                            >
                                {{ countdown > 0 ? `Resend code in ${countdown}s` : "Didn't receive it? Resend code" }}
                            </button>
                        </div>
                    </template>
                </div>

                <!-- HIPAA notice -->
                <div class="bg-slate-50 dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 px-8 py-4">
                    <p class="text-xs text-slate-400 text-center leading-relaxed">
                        This system contains protected health information (PHI). Unauthorized access is
                        prohibited and monitored. All access is logged for compliance purposes.
                    </p>
                </div>
            </div>

            <!-- Below card -->
            <p class="text-center text-slate-500 text-xs mt-4">
                No account? Contact your IT administrator.
            </p>
        </div>
    </div>
</template>
