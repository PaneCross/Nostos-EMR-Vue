<script setup lang="ts">
// Errors/403.vue
// Full-page 403 Access Denied error screen. Shown when the authenticated user
// lacks the required role or department permission for a page. No AppShell —
// renders as a standalone centered layout. Provides a Back button that returns
// the user to the previous page via router.go(-1).
// Route: rendered by Inertia on 403 responses (see HandleInertiaRequests or custom middleware).

import { router } from '@inertiajs/vue3'
import { ShieldExclamationIcon } from '@heroicons/vue/24/outline'
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-6">
        <div class="text-center max-w-md w-full">

            <!-- Icon -->
            <div class="flex justify-center mb-6">
                <span class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-red-100 dark:bg-red-950/60">
                    <ShieldExclamationIcon class="w-10 h-10 text-red-500 dark:text-red-400" aria-hidden="true" />
                </span>
            </div>

            <!-- Error code -->
            <p class="text-sm font-semibold text-red-500 dark:text-red-400 uppercase tracking-widest mb-2">
                403 — Access Denied
            </p>

            <!-- Heading -->
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-3">
                Permission Required
            </h1>

            <!-- Message -->
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-8">
                You don't have permission to view this page. If you believe this is a mistake,
                contact your system administrator.
            </p>

            <!-- Actions -->
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                <button
                    @click="router.go(-1)"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-900 dark:bg-slate-100 px-5 py-2.5 text-sm font-medium text-white dark:text-slate-900 hover:bg-slate-700 dark:hover:bg-slate-200 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-slate-500"
                    aria-label="Go back to previous page"
                >
                    Go Back
                </button>
                <button
                    @click="router.visit('/dashboard')"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 dark:border-slate-700 px-5 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    aria-label="Go to dashboard"
                >
                    Dashboard
                </button>
            </div>

        </div>
    </div>
</template>
