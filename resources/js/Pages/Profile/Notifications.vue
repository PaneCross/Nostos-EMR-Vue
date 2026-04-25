<script setup lang="ts">
// Profile/Notifications.vue
// Notification preference settings for the authenticated user.
// Each alert type has four delivery modes: in_app_only, email_immediate,
// email_digest, off. Changes are saved via PATCH /profile/notifications.
// No PHI is ever included in email notifications (HIPAA compliance).
// Route: GET /profile/notifications → Inertia::render('Profile/Notifications')

import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { CheckCircleIcon } from '@heroicons/vue/24/solid'

// ── Types ──────────────────────────────────────────────────────────────────────

type PrefValue = 'in_app_only' | 'email_immediate' | 'email_digest' | 'off'

// ── Props from Inertia ─────────────────────────────────────────────────────────

const props = defineProps<{
    preferences: Record<string, PrefValue>
}>()

// ── Constants ──────────────────────────────────────────────────────────────────

const PREF_KEYS: string[] = [
    'alert_critical',
    'alert_warning',
    'alert_info',
    'sdr_overdue',
    'new_message',
]

const PREF_LABELS: Record<string, string> = {
    alert_critical: 'Critical Alerts',
    alert_warning:  'Warning Alerts',
    alert_info:     'Informational Alerts',
    sdr_overdue:    'SDR Overdue Notifications',
    new_message:    'Chat Messages',
}

const VALUE_LABELS: Record<PrefValue, string> = {
    in_app_only:     'In-App Only',
    email_immediate: 'Email Immediate',
    email_digest:    'Email Digest (every 2h)',
    off:             'Off',
}

const VALUE_DESCRIPTIONS: Record<PrefValue, string> = {
    in_app_only:     'Shows in the notification bell only. No email.',
    email_immediate: 'Sends an email as soon as the notification is generated. No PHI included.',
    email_digest:    'Batched into a single digest email every 2 hours. No PHI included.',
    off:             'No notification delivered.',
}

const ALL_VALUES: PrefValue[] = ['in_app_only', 'email_immediate', 'email_digest', 'off']

// ── State ──────────────────────────────────────────────────────────────────────

const prefs  = ref<Record<string, PrefValue>>({ ...props.preferences })
const saving = ref(false)
const saved  = ref(false)

// ── Methods ────────────────────────────────────────────────────────────────────

function handleChange(key: string, value: PrefValue) {
    prefs.value = { ...prefs.value, [key]: value }
    saved.value = false
}

const saveError = ref<string | null>(null)
async function handleSave() {
    saving.value = true
    saveError.value = null
    try {
        // Phase U2 — was axios.patch which 405'd against the PUT-only route;
        // route now accepts both PUT and PATCH. Frontend uses PUT for clarity.
        await axios.put('/profile/notifications', { preferences: prefs.value })
        saved.value = true
        setTimeout(() => { saved.value = false }, 3000)
    } catch (e: any) {
        // Surface failure rather than silently flipping saved=true (Audit-9 H7).
        saveError.value = e?.response?.data?.message
            ?? `Save failed (${e?.response?.status ?? 'network error'}). Try again.`
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <AppShell>
        <Head title="Notification Preferences" />

        <div class="max-w-2xl mx-auto px-6 py-8">

            <!-- Header -->
            <div class="mb-6">
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Notification Preferences</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Control how you receive notifications. Emails contain no patient or clinical
                    information in compliance with HIPAA.
                </p>
            </div>

            <!-- Preference cards -->
            <div class="space-y-4">
                <div
                    v-for="key in PREF_KEYS"
                    :key="key"
                    class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5"
                >
                    <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-3">
                        {{ PREF_LABELS[key] ?? key }}
                    </h3>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <button
                            v-for="val in ALL_VALUES"
                            :key="val"
                            @click="handleChange(key, val)"
                            :title="VALUE_DESCRIPTIONS[val]"
                            :aria-label="`Set ${PREF_LABELS[key] ?? key} to ${VALUE_LABELS[val]}`"
                            :aria-pressed="prefs[key] === val"
                            :class="[
                                'rounded-lg border px-3 py-2.5 text-left transition-colors',
                                prefs[key] === val
                                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300'
                                    : 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-600 dark:text-slate-400 hover:border-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700'
                            ]"
                        >
                            <p class="text-xs font-medium">{{ VALUE_LABELS[val] }}</p>
                            <p class="text-xs mt-0.5 opacity-70 leading-snug">
                                {{ VALUE_DESCRIPTIONS[val] }}
                            </p>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Save button -->
            <div class="mt-6 flex items-center gap-3">
                <button
                    @click="handleSave"
                    :disabled="saving"
                    class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                    aria-label="Save notification preferences"
                >
                    {{ saving ? 'Saving...' : 'Save Preferences' }}
                </button>
                <span
                    v-if="saved"
                    class="inline-flex items-center gap-1.5 text-sm text-green-600 dark:text-green-400 font-medium"
                    role="status"
                    aria-live="polite"
                >
                    <CheckCircleIcon class="w-4 h-4" aria-hidden="true" />
                    Saved
                </span>
                <span
                    v-if="saveError"
                    class="inline-flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400 font-medium"
                    role="alert"
                    aria-live="assertive"
                >
                    {{ saveError }}
                </span>
            </div>

        </div>
    </AppShell>
</template>
