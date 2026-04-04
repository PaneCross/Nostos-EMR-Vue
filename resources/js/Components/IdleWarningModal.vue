<script setup lang="ts">
// ─── IdleWarningModal.vue ─────────────────────────────────────────────────────
// HIPAA-required idle timeout warning. Shown 60 seconds before the session is
// automatically terminated due to inactivity. Traps focus while open and closes
// on Escape (which acts as "Stay Logged In"). The parent component owns the
// countdown value and auto-logout logic; this component only handles display.
// ─────────────────────────────────────────────────────────────────────────────

import { onMounted, onUnmounted } from 'vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

defineProps<{
    countdown: number
}>()

const emit = defineEmits<{
    stayLoggedIn: []
}>()

// Trap focus inside the modal and let Escape trigger "Stay Logged In"
function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        emit('stayLoggedIn')
    }
}

function logout() {
    window.location.href = '/auth/logout'
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
    <!-- Backdrop -->
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="idle-title"
        aria-describedby="idle-desc"
    >
        <div
            class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden"
        >
            <!-- Amber warning header -->
            <div class="bg-amber-500 px-6 py-4 flex items-center gap-3">
                <ExclamationTriangleIcon class="w-6 h-6 text-white shrink-0" aria-hidden="true" />
                <h2 id="idle-title" class="text-white font-semibold text-lg">
                    Session Expiring Soon
                </h2>
            </div>

            <div class="p-6">
                <p id="idle-desc" class="text-slate-600 dark:text-slate-300 mb-4">
                    You will be automatically logged out due to inactivity in:
                </p>

                <!-- Countdown ring -->
                <div
                    class="flex items-center justify-center my-6"
                    aria-live="polite"
                    aria-atomic="true"
                >
                    <div
                        class="w-24 h-24 rounded-full border-4 border-amber-400 flex items-center justify-center"
                    >
                        <span
                            class="text-4xl font-bold text-amber-600 dark:text-amber-400 tabular-nums"
                        >
                            {{ countdown }}
                        </span>
                    </div>
                </div>

                <p class="text-sm text-slate-500 dark:text-slate-400 text-center mb-6">seconds</p>

                <div class="flex gap-3">
                    <button
                        type="button"
                        class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 px-4 text-sm font-semibold hover:bg-blue-700 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                        autofocus
                        @click="emit('stayLoggedIn')"
                    >
                        Stay Logged In
                    </button>
                    <button
                        type="button"
                        class="flex-1 border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded-lg py-2.5 px-4 text-sm font-semibold hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2"
                        @click="logout"
                    >
                        Log Out Now
                    </button>
                </div>

                <p class="text-xs text-slate-400 text-center mt-4">
                    This system contains PHI. Unauthorized access is prohibited.
                </p>
            </div>
        </div>
    </div>
</template>
