<script setup lang="ts">
// ─── Mobile/Index.vue — Phase M5 (home-care day list) ───────────────────────
import { ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import VoiceNoteButton from '@/Components/Voice/VoiceNoteButton.vue'

const props = defineProps<{ today: any[] }>()
const draftNote = ref('')
function onTranscript(t: string) { draftNote.value += t }
</script>

<template>
  <Head title="Mobile — Today's visits" />
  <div class="min-h-screen bg-slate-50 dark:bg-slate-950">
    <header class="bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 px-4 py-3">
      <h1 class="text-base font-semibold text-slate-900 dark:text-slate-100">Today's home visits</h1>
      <p class="text-xs text-slate-500 dark:text-slate-400">{{ today?.length ?? 0 }} scheduled</p>
    </header>

    <main class="max-w-xl mx-auto p-4 space-y-4">
      <div v-if="!today?.length" class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-4 text-center text-sm text-slate-500">
        No visits scheduled today.
      </div>

      <div
        v-for="v in today ?? []"
        :key="v.id"
        class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-4 space-y-2"
      >
        <div class="flex items-baseline justify-between">
          <div class="font-medium text-slate-900 dark:text-slate-100">{{ v.participant?.name ?? '—' }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400">
            {{ String(v.scheduled_start ?? '').slice(11, 16) }}
          </div>
        </div>
        <div class="text-xs text-slate-500 dark:text-slate-400">
          MRN {{ v.participant?.mrn ?? '—' }} · {{ v.appointment_type ?? 'Home visit' }} · {{ v.status }}
        </div>
        <div class="flex flex-wrap gap-2 pt-2">
          <Link
            v-if="v.participant?.id"
            :href="`/participants/${v.participant.id}?tab=vitals`"
            class="rounded bg-blue-600 text-white text-xs px-2 py-1"
          >Vitals</Link>
          <Link
            v-if="v.participant?.id"
            :href="`/participants/${v.participant.id}?tab=adl`"
            class="rounded bg-green-600 text-white text-xs px-2 py-1"
          >ADL</Link>
          <Link
            v-if="v.participant?.id"
            :href="`/participants/${v.participant.id}?tab=chart`"
            class="rounded bg-slate-600 text-white text-xs px-2 py-1"
          >Note</Link>
          <Link
            v-if="v.participant?.id"
            :href="`/participants/${v.participant.id}?tab=wounds`"
            class="rounded bg-amber-600 text-white text-xs px-2 py-1"
          >Wound photo</Link>
        </div>
      </div>

      <div class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-4">
        <div class="flex items-center justify-between mb-2">
          <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Dictation scratchpad</h2>
          <VoiceNoteButton @transcript="onTranscript" />
        </div>
        <textarea
          v-model="draftNote"
          rows="6"
          class="w-full rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 p-2 text-sm"
          placeholder="Dictate then paste into a clinical note…"
        ></textarea>
      </div>
    </main>
  </div>
</template>
