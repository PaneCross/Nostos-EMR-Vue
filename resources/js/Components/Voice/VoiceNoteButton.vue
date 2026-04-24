<script setup lang="ts">
// ─── VoiceNoteButton.vue — Phase M5 ─────────────────────────────────────────
// Uses the browser SpeechRecognition API (free, built-in on Chrome/Edge/Safari).
// Emits `transcript` on each final recognition result. Graceful no-op when the
// API isn't available.
// ─────────────────────────────────────────────────────────────────────────────
import { ref, onBeforeUnmount } from 'vue'
import { MicrophoneIcon, StopIcon } from '@heroicons/vue/24/outline'

const emit = defineEmits<{ (e: 'transcript', text: string): void }>()
const recording = ref(false)
const supported = ref(false)
const error = ref<string | null>(null)
let rec: any = null

// Vendor-prefixed API
const W: any = window
const API = W.SpeechRecognition || W.webkitSpeechRecognition
supported.value = typeof API === 'function'

function start() {
  if (!supported.value) {
    error.value = 'Speech recognition not supported in this browser.'
    return
  }
  rec = new API()
  rec.continuous = true
  rec.interimResults = false
  rec.lang = 'en-US'
  rec.onresult = (e: any) => {
    for (let i = e.resultIndex; i < e.results.length; i++) {
      if (e.results[i].isFinal) {
        emit('transcript', e.results[i][0].transcript.trim() + ' ')
      }
    }
  }
  rec.onerror = (e: any) => { error.value = e?.error ?? 'Recognition error' }
  rec.onend = () => { recording.value = false }
  rec.start()
  recording.value = true
  error.value = null
}

function stop() {
  try { rec?.stop() } catch {}
  recording.value = false
}

onBeforeUnmount(stop)
</script>

<template>
  <button
    type="button"
    :disabled="!supported"
    class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs"
    :class="recording ? 'bg-red-600 text-white' : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'"
    @click="recording ? stop() : start()"
    :title="supported ? 'Dictate' : 'Voice not supported in this browser'"
  >
    <StopIcon v-if="recording" class="h-4 w-4" />
    <MicrophoneIcon v-else class="h-4 w-4" />
    {{ recording ? 'Stop' : 'Dictate' }}
    <span v-if="error" class="sr-only">{{ error }}</span>
  </button>
</template>
