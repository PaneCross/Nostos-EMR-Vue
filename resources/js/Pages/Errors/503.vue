<script setup lang="ts">
// Errors/503.vue
// Full-page 503 Service Unavailable screen. Shown during maintenance windows
// or when the application is temporarily down. No AppShell — renders as a
// standalone centered layout. Provides a Refresh button using window.location.reload().
// Route: rendered by Inertia on 503 responses (e.g. maintenance mode).

import { ClockIcon } from '@heroicons/vue/24/outline'
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-6">
        <div class="text-center max-w-md w-full">
            <!-- Icon -->
            <div class="flex justify-center mb-6">
                <span
                    class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-blue-100 dark:bg-blue-950/60"
                >
                    <ClockIcon
                        class="w-10 h-10 text-blue-500 dark:text-blue-400"
                        aria-hidden="true"
                    />
                </span>
            </div>

            <!-- Error code -->
            <p
                class="text-sm font-semibold text-blue-500 dark:text-blue-400 uppercase tracking-widest mb-2"
            >
                503 — Service Unavailable
            </p>

            <!-- Heading -->
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-3">Back Soon</h1>

            <!-- Message -->
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-8">
                The service is temporarily unavailable. We may be performing maintenance. Please try
                again in a few minutes.
            </p>

            <!-- Action -->
            <button
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-700 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                aria-label="Refresh the page"
                @click="() => window.location.reload()"
            >
                Refresh
            </button>
        </div>
    </div>
</template>
