<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import PortalShell from './PortalShell.vue'
const rows = ref<any[]>([])
const loading = ref(true)
const showForm = ref(false)
const form = ref({ subject: '', body: '' })
const sending = ref(false)
const error = ref<string | null>(null)

function refresh() { axios.get('/portal/messages').then(r => rows.value = r.data.messages ?? []).finally(() => loading.value = false) }
onMounted(refresh)

async function send() {
  sending.value = true; error.value = null
  try {
    await axios.post('/portal/messages', form.value)
    showForm.value = false
    form.value = { subject: '', body: '' }
    refresh()
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Send failed'
  } finally { sending.value = false }
}
</script>
<template>
  <PortalShell title="Messages">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Messages</h2>
      <button class="rounded bg-blue-600 text-white text-xs px-3 py-1.5 hover:bg-blue-700" @click="showForm = !showForm">
        {{ showForm ? 'Cancel' : 'Compose' }}
      </button>
    </div>

    <div v-if="showForm" class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-4 mb-4 space-y-3">
      <input v-model="form.subject" placeholder="Subject" class="block w-full rounded border-slate-300 dark:border-slate-600 text-sm" />
      <textarea v-model="form.body" rows="5" placeholder="Message" class="block w-full rounded border-slate-300 dark:border-slate-600 text-sm"></textarea>
      <div v-if="error" class="text-xs text-red-600">{{ error }}</div>
      <div class="flex justify-end">
        <button :disabled="!form.subject || !form.body || sending" class="rounded bg-blue-600 text-white text-sm px-3 py-1.5 hover:bg-blue-700 disabled:opacity-50" @click="send">
          {{ sending ? 'Sending…' : 'Send' }}
        </button>
      </div>
    </div>

    <div class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden">
      <ul class="divide-y divide-slate-200 dark:divide-slate-700">
        <li v-for="m in rows" :key="m.id" class="px-4 py-3">
          <div class="flex items-baseline justify-between">
            <div class="font-medium text-slate-900 dark:text-slate-100">{{ m.subject }}</div>
            <div class="text-xs text-slate-500 dark:text-slate-400">{{ String(m.created_at).slice(0, 10) }}</div>
          </div>
          <div class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-line mt-1">{{ m.body }}</div>
        </li>
        <li v-if="!loading && rows.length === 0" class="px-4 py-6 text-center text-sm text-slate-500">No messages yet.</li>
      </ul>
    </div>
  </PortalShell>
</template>
