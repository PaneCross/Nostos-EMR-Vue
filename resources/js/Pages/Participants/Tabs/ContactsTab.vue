<script setup lang="ts">
// ─── Tabs/ContactsTab.vue ─────────────────────────────────────────────────────
// Participant contacts list (emergency contacts, caregivers, etc.). Loaded from
// Inertia prop and accepts new contacts via an Add Contact modal. Contact type,
// relationship, phone, email, and role flags are captured.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { PlusIcon, PhoneIcon, EnvelopeIcon } from '@heroicons/vue/24/outline'

interface Contact {
  id: number; contact_type: string; full_name: string; relationship: string | null
  phone_primary: string | null; email: string | null
  is_emergency_contact: boolean; is_primary_caregiver: boolean
}

const props = defineProps<{
  participantId: number
  initialContacts: Contact[]
}>()

const contacts = ref<Contact[]>(props.initialContacts)
watch(() => props.initialContacts, v => { contacts.value = v })

const showModal   = ref(false)
const submitting  = ref(false)
const formError   = ref<string | null>(null)

const form = ref({
  contact_type: 'family',
  full_name: '',
  relationship: '',
  phone_primary: '',
  email: '',
  is_emergency_contact: false,
  is_primary_caregiver: false,
})

function resetForm() {
  form.value = {
    contact_type: 'family', full_name: '', relationship: '',
    phone_primary: '', email: '',
    is_emergency_contact: false, is_primary_caregiver: false,
  }
  formError.value = null
}

const CONTACT_TYPE_LABELS: Record<string, string> = {
  family: 'Family', friend: 'Friend', guardian: 'Guardian',
  healthcare_proxy: 'Healthcare Proxy', caregiver: 'Caregiver', other: 'Other',
}

function submitContact() {
  if (!form.value.full_name.trim()) { formError.value = 'Full name is required.'; return }
  submitting.value = true
  formError.value = null
  router.post(`/participants/${props.participantId}/contacts`, form.value, {
    preserveScroll: true,
    onSuccess: () => { showModal.value = false; resetForm() },
    onError: (e: Record<string, string>) => {
      formError.value = Object.values(e)[0] ?? 'Failed to save contact.'
    },
    onFinish: () => { submitting.value = false },
  })
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Contacts ({{ contacts.length }})</h3>
      <button
        class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-1.5"
        aria-label="Add new contact"
        @click="showModal = true"
      >
        <PlusIcon class="w-4 h-4" />
        Add Contact
      </button>
    </div>

    <p v-if="contacts.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">No contacts on file.</p>
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div
        v-for="c in contacts"
        :key="c.id"
        class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-4"
      >
        <div class="flex items-start justify-between mb-2">
          <div>
            <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ c.full_name }}</p>
            <p class="text-xs text-gray-500 dark:text-slate-400">
              {{ CONTACT_TYPE_LABELS[c.contact_type] ?? c.contact_type }}
              <span v-if="c.relationship"> - {{ c.relationship }}</span>
            </p>
          </div>
          <div class="flex gap-1.5">
            <span v-if="c.is_emergency_contact" class="text-xs px-1.5 py-0.5 rounded-full bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300">Emergency</span>
            <span v-if="c.is_primary_caregiver" class="text-xs px-1.5 py-0.5 rounded-full bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300">Caregiver</span>
          </div>
        </div>
        <div class="space-y-1">
          <div v-if="c.phone_primary" class="flex items-center gap-2 text-xs text-gray-600 dark:text-slate-400">
            <PhoneIcon class="w-3.5 h-3.5" />
            <span class="font-mono">{{ c.phone_primary }}</span>
          </div>
          <div v-if="c.email" class="flex items-center gap-2 text-xs text-gray-600 dark:text-slate-400">
            <EnvelopeIcon class="w-3.5 h-3.5" />
            <span>{{ c.email }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Contact modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md p-6">
        <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-4">Add Contact</h3>
        <p v-if="formError" class="text-sm text-red-600 dark:text-red-400 mb-3">{{ formError }}</p>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Full Name *</label>
            <input v-model="form.full_name" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Type</label>
              <select v-model="form.contact_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100">
                <option v-for="(label, key) in CONTACT_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Relationship</label>
              <input v-model="form.relationship" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100" placeholder="e.g. Spouse" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Phone</label>
              <input v-model="form.phone_primary" type="tel" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Email</label>
              <input v-model="form.email" type="email" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100" />
            </div>
          </div>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300 cursor-pointer">
              <input v-model="form.is_emergency_contact" type="checkbox" class="rounded" />
              Emergency Contact
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300 cursor-pointer">
              <input v-model="form.is_primary_caregiver" type="checkbox" class="rounded" />
              Primary Caregiver
            </label>
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-4">
          <button class="text-sm px-4 py-2 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-200 rounded-lg hover:bg-gray-200 dark:hover:bg-slate-600 transition-colors" @click="showModal = false; resetForm()">Cancel</button>
          <button
            :disabled="submitting"
            class="text-sm px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg transition-colors"
            @click="submitContact"
          >{{ submitting ? 'Saving...' : 'Save Contact' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
