<script setup lang="ts">
// ─── Login.vue ─────────────────────────────────────────────────────────────────
// Two-step OTP login page. Step 1: email entry. Step 2: 6-digit OTP entry.
// Also shows the Google OAuth button if GOOGLE_CLIENT_ID is configured.
// ─────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import { useForm, Head } from '@inertiajs/vue3'
import axios from 'axios'

const step = ref<'email' | 'otp'>('email')
const email = ref('')
const error = ref('')
const loading = ref(false)

// OTP form (Inertia form for CSRF + redirect handling)
const otpForm = useForm({ email: '', code: '' })

async function requestOtp() {
    if (!email.value.trim()) {
        error.value = 'Please enter your email address.'
        return
    }
    loading.value = true
    error.value = ''
    try {
        await axios.post('/auth/request-otp', { email: email.value })
        otpForm.email = email.value
        step.value = 'otp'
    } catch (e: unknown) {
        error.value =
            (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
            'Unable to send OTP. Please try again.'
    } finally {
        loading.value = false
    }
}

function submitOtp() {
    otpForm.post('/auth/verify-otp', {
        onError: (errors) => {
            error.value = errors.code ?? errors.email ?? 'Invalid or expired code.'
        },
    })
}

function backToEmail() {
    step.value = 'email'
    error.value = ''
    otpForm.code = ''
}
</script>

<template>
    <Head title="Sign In" />

    <div
        class="min-h-screen flex flex-col items-center justify-center bg-slate-50 dark:bg-slate-900 px-4"
    >
        <!-- Card -->
        <div class="w-full max-w-sm bg-white dark:bg-slate-800 rounded-2xl shadow-lg p-8">
            <!-- Brand -->
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">NostosEMR</h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                    PACE Electronic Medical Records
                </p>
            </div>

            <!-- Error -->
            <div
                v-if="error"
                class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 rounded-lg px-3 py-2 text-sm"
            >
                {{ error }}
            </div>

            <!-- Step 1: Email -->
            <form v-if="step === 'email'" class="space-y-4" @submit.prevent="requestOtp">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                        Work Email
                    </label>
                    <input
                        v-model="email"
                        type="email"
                        autocomplete="email"
                        required
                        class="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm text-gray-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="you@organization.com"
                    />
                </div>
                <button
                    type="submit"
                    :disabled="loading"
                    class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white rounded-lg text-sm font-semibold transition"
                >
                    {{ loading ? 'Sending...' : 'Send One-Time Code' }}
                </button>

                <div class="relative flex items-center gap-2 my-2">
                    <div class="flex-1 h-px bg-gray-200 dark:bg-slate-700"></div>
                    <span class="text-xs text-gray-400 dark:text-slate-500">or</span>
                    <div class="flex-1 h-px bg-gray-200 dark:bg-slate-700"></div>
                </div>

                <!-- Google OAuth -->
                <a
                    href="/auth/google"
                    class="w-full flex items-center justify-center gap-2 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg text-sm font-medium text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition"
                >
                    <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                            fill="#4285F4"
                        />
                        <path
                            d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                            fill="#34A853"
                        />
                        <path
                            d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                            fill="#FBBC05"
                        />
                        <path
                            d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                            fill="#EA4335"
                        />
                    </svg>
                    Sign in with Google
                </a>
            </form>

            <!-- Step 2: OTP -->
            <form v-else class="space-y-4" @submit.prevent="submitOtp">
                <div class="text-sm text-gray-600 dark:text-slate-400 text-center mb-2">
                    Enter the 6-digit code sent to<br />
                    <span class="font-medium text-gray-800 dark:text-slate-200">{{ email }}</span>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                        One-Time Code
                    </label>
                    <input
                        v-model="otpForm.code"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        autocomplete="one-time-code"
                        required
                        class="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm text-center text-gray-900 dark:text-slate-100 tracking-widest text-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="000000"
                    />
                </div>
                <button
                    type="submit"
                    :disabled="otpForm.processing"
                    class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white rounded-lg text-sm font-semibold transition"
                >
                    {{ otpForm.processing ? 'Verifying...' : 'Verify Code' }}
                </button>
                <button
                    type="button"
                    class="w-full text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 transition"
                    @click="backToEmail"
                >
                    Back to email
                </button>
            </form>
        </div>

        <!-- Footer -->
        <p class="mt-6 text-xs text-gray-400 dark:text-slate-600 text-center max-w-sm">
            This system contains protected health information (PHI). Unauthorized access is
            prohibited under HIPAA 45 CFR Part 164. All access is logged.
        </p>
    </div>
</template>
