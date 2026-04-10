<script setup lang="ts">
// ─── ContactsTab.vue ──────────────────────────────────────────────────────────
// Emergency contacts and caregiver list. Add/edit contact form in a modal.
// Primary contact shown with badge. Relationship, phone, and email fields.
// Contact type: emergency_contact, caregiver, healthcare_proxy, legal_guardian.
// ─────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import axios from 'axios'
import { PlusIcon, UserIcon } from '@heroicons/vue/24/outline'

interface Contact {
  id: number; first_name: string; last_name: string; relationship: string
  contact_type: string; phone_primary: string | null; phone_secondary: string | null
  email: string | null; is_primary: boolean; is_active: boolean; notes: string | null
}

interface Participant { id: number }

const props = defineProps<{
  participant: Participant
  contacts: Contact[]
}>()

const CONTACT_TYPE_LABELS: Record<string, string> = {
  emergency_contact: 'Emergency Contact',
  caregiver:         'Caregiver',
  healthcare_proxy:  'Healthcare Proxy',
  legal_guardian:    'Legal Guardian',
  family_member:     'Family Member',
  other:             'Other',
}

const contacts = ref<Contact[]>(props.contacts)
const showModal = ref(false)
const saving = ref(false)
const error = ref('')
const editingId = ref<number | null>(null)

const blankForm = () => ({
  first_name: '', last_name: '', relationship: '', contact_type: 'emergency_contact',
  phone_primary: '', phone_secondary: '', email: '', is_primary: false, notes: '',
})
const form = ref(blankForm())

function openAdd() {
  editingId.value = null
  form.value = blankForm()
  error.value = ''
  showModal.value = true
}

function openEdit(contact: Contact) {
  editingId.value = contact.id
  form.value = {
    first_name: contact.first_name, last_name: contact.last_name,
    relationship: contact.relationship, contact_type: contact.contact_type,
    phone_primary: contact.phone_primary ?? '', phone_secondary: contact.phone_secondary ?? '',
    email: contact.email ?? '', is_primary: contact.is_primary, notes: contact.notes ?? '',
  }
  error.value = ''
  showModal.value = true
}

function closeModal() {
  showModal.value = false
  error.value = ''
}

async function submit() {
  if (!form.value.first_name.trim()) { error.value = 'First name is required.'; return }
  saving.value = true; error.value = ''
  const payload = {
    ...form.value,
    phone_primary: form.value.phone_primary || null,
    phone_secondary: form.value.phone_secondary || null,
    email: form.value.email || null,
    notes: form.value.notes || null,
  }
  try {
    if (editingId.value) {
      const res = await axios.put(`/participants/${props.participant.id}/contacts/${editingId.value}`, payload)
      const idx = contacts.value.findIndex(c => c.id === editingId.value)
      if (idx !== -1) contacts.value[idx] = res.data
    } else {
      const res = await axios.post(`/participants/${props.participant.id}/contacts`, payload)
      contacts.value.push(res.data)
    }
    showModal.value = false
    form.value = blankForm()
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    error.value = e.response?.data?.message ?? 'Failed to save contact.'
    saving.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Contacts</h2>
      <button
        class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        @click="openAdd"
      >
        <PlusIcon class="w-3 h-3" />
        Add Contact
      </button>
    </div>

    <div v-if="contacts.length === 0" class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm">No contacts on file.</div>
    <div v-else class="space-y-2">
      <div
        v-for="contact in contacts.filter(c => c.is_active)"
        :key="contact.id"
        class="flex items-start gap-3 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3"
      >
        <div class="shrink-0 w-8 h-8 rounded-full bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
          <UserIcon class="w-4 h-4 text-gray-400 dark:text-slate-500" />
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ contact.first_name }} {{ contact.last_name }}</span>
            <span v-if="contact.is_primary" class="text-xs bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded">Primary</span>
            <span class="text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-1.5 py-0.5 rounded">{{ CONTACT_TYPE_LABELS[contact.contact_type] ?? contact.contact_type }}</span>
          </div>
          <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ contact.relationship }}</div>
          <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5 flex gap-3 flex-wrap">
            <span v-if="contact.phone_primary">{{ contact.phone_primary }}</span>
            <span v-if="contact.phone_secondary">{{ contact.phone_secondary }}</span>
            <span v-if="contact.email">{{ contact.email }}</span>
          </div>
          <p v-if="contact.notes" class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">{{ contact.notes }}</p>
        </div>
        <button
          class="text-xs px-2 py-1 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 rounded hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors shrink-0"
          @click="openEdit(contact)"
        >
          Edit
        </button>
      </div>
    </div>

    <!-- Add/Edit modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-md w-full p-5 max-h-[90vh] overflow-y-auto">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">{{ editingId ? 'Edit Contact' : 'Add Contact' }}</h3>
        <div class="grid grid-cols-2 gap-3 mb-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">First Name *</label>
            <input v-model="form.first_name" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Last Name</label>
            <input v-model="form.last_name" type="text" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Contact Type</label>
            <select name="contact_type" v-model="form.contact_type" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700">
              <option v-for="(label, key) in CONTACT_TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Relationship</label>
            <input v-model="form.relationship" type="text" placeholder="e.g. Daughter" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Primary Phone</label>
            <input v-model="form.phone_primary" type="tel" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Secondary Phone</label>
            <input v-model="form.phone_secondary" type="tel" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
          <div class="col-span-2">
            <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Email</label>
            <input v-model="form.email" type="email" class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700" />
          </div>
        </div>
        <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-slate-300 mb-3 cursor-pointer">
          <input v-model="form.is_primary" type="checkbox" class="rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700" />
          Primary contact
        </label>
        <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
        <div class="flex gap-2">
          <button :disabled="saving" class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors" @click="submit">
            {{ saving ? 'Saving...' : 'Save' }}
          </button>
          <button class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors" @click="closeModal">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</template>
