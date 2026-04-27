<script setup lang="ts">
// Errors/404.vue
// Full-page 404 Not Found error screen. Shown when the requested URL does not
// match any route or the requested resource does not exist. No AppShell:
// renders as a standalone centered layout. Provides a Go to Dashboard button
// that navigates to /dashboard via router.visit().
// Route: rendered by Inertia on 404 responses.

import { router } from '@inertiajs/vue3'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-6">
        <div class="text-center max-w-md w-full">

            <!-- Icon -->
            <div class="flex justify-center mb-6">
                <span class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-slate-100 dark:bg-slate-800">
                    <MagnifyingGlassIcon class="w-10 h-10 text-slate-400 dark:text-slate-500" aria-hidden="true" />
                </span>
            </div>

            <!-- Error code -->
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">
                404: Page Not Found
            </p>

            <!-- Heading -->
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-3">
                This Page Doesn't Exist
            </h1>

            <!-- Message -->
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-8">
                The page you're looking for doesn't exist or may have been moved.
                Check the URL or return to the dashboard.
            </p>

            <!-- Action -->
            <button
                @click="router.visit('/')"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-700 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                aria-label="Go to dashboard"
            >
                Go to Dashboard
            </button>

        </div>
    </div>
</template>
