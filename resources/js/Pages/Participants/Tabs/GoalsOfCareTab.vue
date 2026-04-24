<script setup lang="ts">
// ─── GoalsOfCareTab.vue — Phase J4 ───────────────────────────────────────────
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { PlusIcon } from '@heroicons/vue/24/outline'

interface Participant { id: number }
const props = defineProps<{ participant: Participant }>()

const loading = ref(true)
const conversations = ref<any[]>([])
const showForm = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const form = ref({
  conversation_date: new Date().toISOString().slice(0, 10),
  participants_present: '',
  discussion_summary: '',
  decisions_made: '',
  next_steps: '',
})

function refresh() {
  loading.value = true
  axios.get(`/participants/${props.participant.id}/goals-of-care`)
    .then(r => conversations.value = r.data.conversations ?? [])
    .finally(() => loading.value = false)
}
onMounted(refresh)

async function submit() {
  saving.value = true
  error.value = null
  try {
    const p: any = { ...form.value }
    for (const k of ['participants_present', 'decisions_made', 'next_steps']) {
      if (!p[k]) delete p[k]
    }
    await axios.post(`/participants/${props.participant.id}/goals-of-care`, p)
    showForm.value = false
    form.value = {
      conversation_date: new Date().toISOString().slice(0, 10),
      participants_present: '', discussion_summary: '', decisions_made: '', next_steps: '',
    }
    refresh()
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Save failed'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Goals of Care Conversations</h2>
      <button type="button" class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" @click="showForm = !showForm">
        <PlusIcon class="h-4 w-4" />
        {{ showForm ? 'Cancel' : 'Record conversation' }}
      </button>
    </div>

    <div v-if="showForm" class="rounded border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-950/40 p-4 space-y-3">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs mb-1">Conversation date</label>
          <input type="date" v-model="form.conversation_date" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
        <div>
          <label class="block text-xs mb-1">Participants present</label>
          <input type="text" v-model="form.participants_present" class="w-full rounded border-gray-300 dark:border-slate-600 text-sm" />
        </div>
      </div>
      <textarea v-model="form.discussion_summary" rows="3" class="block w-full rounded border-gray-300 dark:border-slate-600 text-sm" placeholder="Discussion summary (required)" />
      <textarea v-model="form.decisions_made" rows="2" class="block w-full rounded border-gray-300 dark:border-slate-600 text-sm" placeholder="Decisions made (optional)" />
      <textarea v-model="form.next_steps" rows="2" class="block w-full rounded border-gray-300 dark:border-slate-600 text-sm" placeholder="Next steps (optional)" />
      <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>
      <div class="flex justify-end">
        <button :disabled="saving" class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="submit">
          {{ saving ? 'Saving…' : 'Save conversation' }}
        </button>
      </div>
    </div>

    <div class="space-y-3">
      <div v-for="c in conversations" :key="c.id" class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3">
        <div class="flex items-baseline justify-between">
          <div class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ c.conversation_date }}</div>
          <div class="text-xs text-gray-500 dark:text-slate-400">{{ c.participants_present ?? '' }}</div>
        </div>
        <p class="mt-1 text-sm text-gray-700 dark:text-slate-300 whitespace-pre-line">{{ c.discussion_summary }}</p>
        <div v-if="c.decisions_made" class="mt-2 text-sm"><span class="font-semibold">Decisions:</span> {{ c.decisions_made }}</div>
        <div v-if="c.next_steps" class="mt-1 text-sm"><span class="font-semibold">Next steps:</span> {{ c.next_steps }}</div>
      </div>
      <p v-if="!loading && conversations.length === 0" class="rounded border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 text-center text-sm text-gray-500 dark:text-slate-400">
        No conversations recorded yet.
      </p>
    </div>
  </div>
</template>
