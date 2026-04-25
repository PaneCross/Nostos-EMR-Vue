<script setup lang="ts">
// Errors/500.vue
// Full-page 500 Server Error screen. Shown when an unhandled exception or
// unexpected server-side error occurs. No AppShell — renders as a standalone
// centered layout. Provides a Reload button and a Go to Dashboard fallback.
// Route: rendered by Inertia on 500 responses.

import { router } from '@inertiajs/vue3'
import { ExclamationCircleIcon } from '@heroicons/vue/24/outline'
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-6">
        <div class="text-center max-w-md w-full">

            <!-- Icon -->
            <div class="flex justify-center mb-6">
                <span class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-amber-100 dark:bg-amber-950/60">
                    <ExclamationCircleIcon class="w-10 h-10 text-amber-500 dark:text-amber-400" aria-hidden="true" />
                </span>
            </div>

            <!-- Error code -->
            <p class="text-sm font-semibold text-amber-500 dark:text-amber-400 uppercase tracking-widest mb-2">
                500 — Server Error
            </p>

            <!-- Heading -->
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-3">
                Something Went Wrong
            </h1>

            <!-- Message -->
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-8">
                Something went wrong on our end. Please try again or contact support
                if the problem continues.
            </p>

            <!-- Actions -->
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                <button
                    @click="() => window.location.reload()"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-900 dark:bg-slate-100 px-5 py-2.5 text-sm font-medium text-white dark:text-slate-900 hover:bg-slate-700 dark:hover:bg-slate-200 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-slate-500"
                    aria-label="Reload the page"
                >
                    Try Again
                </button>
                <button
                    @click="router.visit('/')"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 dark:border-slate-700 px-5 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    aria-label="Go to dashboard"
                >
                    Dashboard
                </button>
            </div>

        </div>
    </div>
</template>
