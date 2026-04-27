<!-- ItAdmin/StateConfig.vue -->
<!-- State Medicaid configuration CRUD page for IT Admins. Each state config defines
     submission format, clearinghouse details, and contact info for state-specific
     837 Medicaid encounter submissions (DEBT-038). Finance can view; IT Admin can edit. -->

<script setup lang="ts">
// ─── ItAdmin/StateConfig ────────────────────────────────────────────────────
// Per-state Medicaid configuration: submission format, clearinghouse routing,
// state Medicaid contact info: needed because each state's 837 (X12 EDI
// claim) Medicaid encounter submission has its own conventions.
//
// Audience: IT Admin edits; Finance reads.
//
// Notable rules:
//   - PAYWALL-DEFERRED: real per-state Medicaid integration depends on
//     each state's portal/SFTP credentials; current state is configuration
//     scaffolding only (per Phase 15.9 / DEBT-038).
//   - Changes affect downstream EDI batch generation: coordinate with
//     Finance before flipping a live submission format.
// ────────────────────────────────────────────────────────────────────────────
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import { MapPinIcon, PlusIcon } from '@heroicons/vue/24/outline'

interface StateMedicaidConfig {
    id: number
    state_code: string
    state_name: string
    submission_format: string
    companion_guide_notes: string | null
    submission_endpoint: string | null
    clearinghouse_name: string | null
    days_to_submit: number | null
    effective_date: string | null
    contact_name: string | null
    contact_phone: string | null
    contact_email: string | null
    is_active: boolean
}

interface Props {
    configs: StateMedicaidConfig[]
    submissionFormats: Record<string, string>
}

const props = defineProps<Props>()

const BLANK_FORM = (): StateMedicaidConfig => ({
    id: 0,
    state_code: '',
    state_name: '',
    submission_format: '837P',
    companion_guide_notes: '',
    submission_endpoint: '',
    clearinghouse_name: '',
    days_to_submit: 30,
    effective_date: '',
    contact_name: '',
    contact_phone: '',
    contact_email: '',
    is_active: true,
})

const showModal = ref(false)
const modalMode = ref<'add' | 'edit'>('add')
const editingId = ref<number | null>(null)
const form = ref<StateMedicaidConfig>(BLANK_FORM())
const submitting = ref(false)
const deleteConfirmId = ref<number | null>(null)

const openAdd = () => {
    modalMode.value = 'add'
    editingId.value = null
    form.value = BLANK_FORM()
    showModal.value = true
}

const openEdit = (c: StateMedicaidConfig) => {
    modalMode.value = 'edit'
    editingId.value = c.id
    form.value = { ...c }
    showModal.value = true
}

const submit = async () => {
    submitting.value = true
    try {
        if (modalMode.value === 'add') {
            await axios.post('/it-admin/state-config', form.value)
        } else {
            await axios.put(`/it-admin/state-config/${editingId.value}`, form.value)
        }
        showModal.value = false
        router.reload({ only: ['configs'] })
    } catch {
        // silently handle
    } finally {
        submitting.value = false
    }
}

const deactivate = async (id: number) => {
    try {
        await axios.delete(`/it-admin/state-config/${id}`)
        deleteConfirmId.value = null
        router.reload({ only: ['configs'] })
    } catch {
        deleteConfirmId.value = null
    }
}

const formatDate = (iso: string | null) => iso
    ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'short' })
    : '-'
</script>

<template>
    <AppShell>
        <Head title="IT Admin: State Medicaid Config" />

        <div class="max-w-5xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <MapPinIcon class="w-7 h-7 text-blue-600 dark:text-blue-400" />
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">State Medicaid Configurations</h1>
                        <p class="text-sm text-gray-500 dark:text-slate-400">State-specific 837 encounter submission settings</p>
                    </div>
                </div>
                <button @click="openAdd"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition-colors"
                    aria-label="Add state config">
                    <PlusIcon class="w-4 h-4" /> Add State
                </button>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">State</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Format</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Clearinghouse</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Days to Submit</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Status</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr v-for="c in props.configs" :key="c.id"
                            :class="!c.is_active ? 'opacity-60' : 'hover:bg-gray-50 dark:hover:bg-slate-700/50'">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-slate-100">{{ c.state_code }}</div>
                                <div class="text-xs text-gray-500 dark:text-slate-400">{{ c.state_name }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">
                                {{ props.submissionFormats[c.submission_format] ?? c.submission_format }}
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ c.clearinghouse_name ?? '-' }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ c.days_to_submit ?? '-' }}</td>
                            <td class="px-4 py-3">
                                <span :class="c.is_active
                                    ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300'
                                    : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'"
                                    class="inline-block px-2 py-0.5 rounded-full text-xs font-medium">
                                    {{ c.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button @click="openEdit(c)"
                                        class="text-xs px-2 py-1 rounded border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 transition-colors"
                                        :aria-label="`Edit config for ${c.state_code}`">Edit</button>
                                    <button v-if="c.is_active"
                                        @click="deleteConfirmId = c.id"
                                        class="text-xs px-2 py-1 rounded border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                                        :aria-label="`Deactivate config for ${c.state_code}`">Deactivate</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="props.configs.length === 0">
                            <td colspan="6" class="py-12 text-center text-gray-500 dark:text-slate-400">No state configurations.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg mx-4 p-6 max-h-screen overflow-y-auto">
                <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100 mb-4">
                    {{ modalMode === 'add' ? 'Add State Configuration' : 'Edit State Configuration' }}
                </h2>
                <form @submit.prevent="submit" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sc-code">State Code</label>
                            <input id="sc-code" v-model="form.state_code" required maxlength="2" type="text" placeholder="CA"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm uppercase" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sc-name">State Name</label>
                            <input id="sc-name" v-model="form.state_name" required type="text" placeholder="California"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sc-format">Submission Format</label>
                            <select id="sc-format" v-model="form.submission_format"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm text-gray-700 dark:text-slate-300">
                                <option v-for="(label, key) in props.submissionFormats" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sc-days">Days to Submit</label>
                            <input id="sc-days" v-model.number="form.days_to_submit" type="number" min="1"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sc-ch">Clearinghouse Name</label>
                        <input id="sc-ch" v-model="form.clearinghouse_name" type="text"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sc-endpoint">Submission Endpoint</label>
                        <input id="sc-endpoint" v-model="form.submission_endpoint" type="text" placeholder="https://"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sc-notes">Companion Guide Notes</label>
                        <textarea id="sc-notes" v-model="form.companion_guide_notes" rows="2"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"></textarea>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sc-contact">Contact Name</label>
                            <input id="sc-contact" v-model="form.contact_name" type="text"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sc-phone">Phone</label>
                            <input id="sc-phone" v-model="form.contact_phone" type="text"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sc-email">Email</label>
                            <input id="sc-email" v-model="form.contact_email" type="email"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <input id="sc-active" v-model="form.is_active" type="checkbox" class="rounded border-gray-300 dark:border-slate-600" />
                        <label for="sc-active" class="text-sm text-gray-700 dark:text-slate-300">Active</label>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="showModal = false"
                            class="px-4 py-2 text-sm rounded-lg text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 transition-colors">Cancel</button>
                        <button type="submit" :disabled="submitting"
                            class="px-4 py-2 text-sm rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium disabled:opacity-50 transition-colors">
                            {{ submitting ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Deactivate Confirm Modal -->
        <div v-if="deleteConfirmId" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
                <h3 class="text-lg font-bold text-gray-900 dark:text-slate-100 mb-2">Deactivate Configuration</h3>
                <p class="text-sm text-gray-600 dark:text-slate-400 mb-4">This will mark the state config as inactive. It can be re-activated later.</p>
                <div class="flex justify-end gap-3">
                    <button @click="deleteConfirmId = null"
                        class="px-4 py-2 text-sm rounded-lg text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 transition-colors">Cancel</button>
                    <button @click="deactivate(deleteConfirmId!)"
                        class="px-4 py-2 text-sm rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium transition-colors">Deactivate</button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
