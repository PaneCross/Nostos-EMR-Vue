<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import PortalShell from './PortalShell.vue'

const submitting = ref(false)
const message = ref<string | null>(null)
const error = ref<string | null>(null)

async function submit(type: string, payload: any = {}) {
  submitting.value = true; message.value = null; error.value = null
  try {
    await axios.post('/portal/requests', { request_type: type, payload })
    message.value = 'Request submitted. Your care team will follow up shortly.'
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Request failed.'
  } finally { submitting.value = false }
}

function recordsRequest() {
  const scope = prompt('What records would you like? (e.g. "Last 6 months of visits")') ?? ''
  if (!scope) return
  submit('records', { scope })
}

function contactUpdate() {
  const phone = prompt('New phone number (leave blank to skip)') ?? ''
  const address = prompt('New address (leave blank to skip)') ?? ''
  if (!phone && !address) return
  submit('contact_update', { phone, address })
}

function amendmentRequest() {
  const target = prompt('Which part of your record? (e.g. "demographics", "allergies", "problem list")')
  if (!target) return
  const change = prompt('Describe the change you are requesting:')
  if (!change) return
  const justification = prompt('Why? (optional)') ?? ''
  submit('amendment', {
    target_field_or_section: target,
    requested_change: change,
    justification,
  })
}
</script>

<template>
  <PortalShell title="Requests">
    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100 mb-3">Requests</h2>
    <div v-if="message" class="rounded bg-green-50 dark:bg-green-950/30 text-green-800 dark:text-green-200 text-sm px-3 py-2 mb-3">{{ message }}</div>
    <div v-if="error" class="rounded bg-red-50 dark:bg-red-950/30 text-red-800 dark:text-red-200 text-sm px-3 py-2 mb-3">{{ error }}</div>

    <div class="grid gap-4">
      <button :disabled="submitting" class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-4 text-left hover:border-blue-400 disabled:opacity-50" @click="recordsRequest">
        <div class="font-medium text-slate-900 dark:text-slate-100">Request medical records</div>
        <div class="text-xs text-slate-500 dark:text-slate-400">HIPAA-protected records release. 30-day response window.</div>
      </button>
      <button :disabled="submitting" class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-4 text-left hover:border-blue-400 disabled:opacity-50" @click="contactUpdate">
        <div class="font-medium text-slate-900 dark:text-slate-100">Update contact information</div>
        <div class="text-xs text-slate-500 dark:text-slate-400">Phone number, address, or emergency contact.</div>
      </button>
      <button :disabled="submitting" class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-4 text-left hover:border-blue-400 disabled:opacity-50" @click="amendmentRequest">
        <div class="font-medium text-slate-900 dark:text-slate-100">Request a record amendment</div>
        <div class="text-xs text-slate-500 dark:text-slate-400">HIPAA §164.526 — 60-day decision window.</div>
      </button>
    </div>
  </PortalShell>
</template>
