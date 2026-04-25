<script setup lang="ts">
// ─── Toaster.vue — Phase V5 ────────────────────────────────────────────────
// Global toast surface for the axios response interceptor + ad-hoc emits.
// Mounted once in AppShell.vue. Listens for window 'nostos:toast' CustomEvents
// with detail {message, severity?: 'info'|'warning'|'error', timeout?: ms}.
//
// Severity defaults to 'error'. Toasts auto-dismiss after their timeout
// (default 6000ms for error, 4000ms for info/warning). Click to dismiss.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onMounted, onBeforeUnmount } from 'vue'

interface Toast {
  id: number
  message: string
  severity: 'info' | 'warning' | 'error'
}

const toasts = ref<Toast[]>([])
let nextId = 1

function pushToast(message: string, severity: 'info' | 'warning' | 'error' = 'error', timeout = 6000) {
  const id = nextId++
  toasts.value.push({ id, message, severity })
  setTimeout(() => dismiss(id), timeout)
}

function dismiss(id: number) {
  toasts.value = toasts.value.filter(t => t.id !== id)
}

function onToastEvent(e: Event) {
  const detail = (e as CustomEvent).detail ?? {}
  const message = detail.message
  if (typeof message !== 'string' || ! message) return
  const severity: 'info' | 'warning' | 'error' = ['info', 'warning', 'error'].includes(detail.severity)
    ? detail.severity : 'error'
  const timeout = typeof detail.timeout === 'number' ? detail.timeout
    : (severity === 'error' ? 6000 : 4000)
  pushToast(message, severity, timeout)
}

onMounted(() => window.addEventListener('nostos:toast', onToastEvent))
onBeforeUnmount(() => window.removeEventListener('nostos:toast', onToastEvent))
</script>

<template>
  <div aria-live="polite" aria-atomic="true"
       class="fixed bottom-4 right-4 z-[10000] flex flex-col gap-2 max-w-sm pointer-events-none"
       data-testid="toaster">
    <transition-group name="toast" tag="div" class="space-y-2 pointer-events-auto">
      <div v-for="t in toasts" :key="t.id"
           role="alert"
           @click="dismiss(t.id)"
           :class="[
             'cursor-pointer rounded-lg shadow-lg px-4 py-3 text-sm border',
             t.severity === 'error' ? 'bg-red-50 dark:bg-red-900/40 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200' :
             t.severity === 'warning' ? 'bg-amber-50 dark:bg-amber-900/40 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200' :
             'bg-blue-50 dark:bg-blue-900/40 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-200'
           ]">
        {{ t.message }}
        <div class="text-xs mt-1 opacity-60">click to dismiss</div>
      </div>
    </transition-group>
  </div>
</template>

<style scoped>
.toast-enter-active, .toast-leave-active { transition: all 200ms ease; }
.toast-enter-from { transform: translateX(20px); opacity: 0; }
.toast-leave-to   { transform: translateX(20px); opacity: 0; }
</style>
