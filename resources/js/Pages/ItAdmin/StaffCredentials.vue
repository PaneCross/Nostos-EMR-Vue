<script setup lang="ts">
// ─── IT Admin / Staff Credentials ─────────────────────────────────────────────
// §460.64-71 staff credential + training hours tracking.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    IdentificationIcon,
    AcademicCapIcon,
    PlusIcon,
    ArrowLeftIcon,
    ExclamationTriangleIcon,
    CheckCircleIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline'

interface Credential {
    id: number
    credential_type: string
    type_label: string
    title: string
    license_state: string | null
    license_number: string | null
    issued_at: string | null
    expires_at: string | null
    days_remaining: number | null
    status: 'current' | 'due_60' | 'due_30' | 'due_14' | 'due_today' | 'expired' | 'no_expiry'
    verified_at: string | null
    notes: string | null
}

interface Training {
    id: number
    training_name: string
    category: string
    category_label: string
    training_hours: number
    completed_at: string | null
    verified_at: string | null
    notes: string | null
}

const props = defineProps<{
    staff: { id: number; first_name: string; last_name: string; email: string; department: string; role: string; is_active: boolean }
    credentials: Credential[]
    training: Training[]
    hoursByCategory: Record<string, number>
    totalHours12mo: number
    credentialTypes: Record<string, string>
    trainingCategories: Record<string, string>
}>()

// ── Credential form ──────────────────────────────────────────────────────────

const showCredForm = ref(false)
const credSaving = ref(false)
const credForm = ref({
    credential_type: 'license',
    title: '',
    license_state: '',
    license_number: '',
    issued_at: '',
    expires_at: '',
    notes: '',
})

function resetCred() {
    credForm.value = {
        credential_type: 'license',
        title: '',
        license_state: '',
        license_number: '',
        issued_at: '',
        expires_at: '',
        notes: '',
    }
}

async function submitCred() {
    if (!credForm.value.title.trim()) { alert('Title is required'); return }
    credSaving.value = true
    try {
        await axios.post(`/it-admin/users/${props.staff.id}/credentials`, credForm.value)
        showCredForm.value = false
        resetCred()
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to save credential.')
    } finally {
        credSaving.value = false
    }
}

async function deleteCred(c: Credential) {
    if (!confirm(`Remove credential "${c.title}"?`)) return
    await axios.delete(`/it-admin/staff-credentials/${c.id}`)
    router.reload()
}

// ── Training form ────────────────────────────────────────────────────────────

const showTrainingForm = ref(false)
const trainingSaving = ref(false)
const trainingForm = ref({
    training_name: '',
    category: 'direct_care',
    training_hours: 1,
    completed_at: new Date().toISOString().slice(0, 10),
    notes: '',
})

function resetTraining() {
    trainingForm.value = {
        training_name: '',
        category: 'direct_care',
        training_hours: 1,
        completed_at: new Date().toISOString().slice(0, 10),
        notes: '',
    }
}

async function submitTraining() {
    if (!trainingForm.value.training_name.trim()) { alert('Training name is required'); return }
    trainingSaving.value = true
    try {
        await axios.post(`/it-admin/users/${props.staff.id}/training`, trainingForm.value)
        showTrainingForm.value = false
        resetTraining()
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to save training.')
    } finally {
        trainingSaving.value = false
    }
}

async function deleteTraining(t: Training) {
    if (!confirm(`Remove training "${t.training_name}"?`)) return
    await axios.delete(`/it-admin/staff-training/${t.id}`)
    router.reload()
}

// ── Status helpers ───────────────────────────────────────────────────────────

const STATUS_LABELS: Record<string, string> = {
    current: 'Current',
    due_60: 'Due in 60d',
    due_30: 'Due in 30d',
    due_14: 'Due in 14d',
    due_today: 'Due today',
    expired: 'EXPIRED',
    no_expiry: 'No expiry',
}

const STATUS_CLASSES: Record<string, string> = {
    current:   'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    no_expiry: 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    due_60:    'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    due_30:    'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    due_14:    'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300',
    due_today: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    expired:   'bg-red-600 text-white',
}

const expiringCount = computed(() =>
    props.credentials.filter(c => ['expired', 'due_today', 'due_14', 'due_30', 'due_60'].includes(c.status)).length
)
</script>

<template>
    <AppShell>
        <Head :title="`Credentials — ${staff.first_name} ${staff.last_name}`" />

        <div class="px-6 py-6 max-w-6xl mx-auto space-y-6">
            <!-- Header -->
            <div>
                <Link href="/it-admin/users" class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:underline mb-3">
                    <ArrowLeftIcon class="w-4 h-4" /> Back to Users
                </Link>
                <div class="flex items-start gap-3">
                    <IdentificationIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">
                            {{ staff.last_name }}, {{ staff.first_name }}
                        </h1>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Staff credentials &amp; training — 42 CFR §460.64-71
                            · <span class="capitalize">{{ staff.department.replace('_', ' ') }}</span>
                            · <span class="capitalize">{{ staff.role }}</span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Summary -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Credentials on file</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-slate-100 tabular-nums">{{ credentials.length }}</p>
                </div>
                <div :class="[
                    'rounded-xl border p-4',
                    expiringCount > 0
                        ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-300 dark:border-amber-700 text-amber-900 dark:text-amber-100'
                        : 'bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700'
                ]">
                    <p class="text-xs uppercase tracking-wide opacity-80">Expiring / expired (60d)</p>
                    <p class="text-2xl font-bold tabular-nums">{{ expiringCount }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Training hours (past 12mo)</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-slate-100 tabular-nums">{{ totalHours12mo }}</p>
                </div>
            </div>

            <!-- Credentials -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-2">
                        <IdentificationIcon class="w-5 h-5 text-slate-500" />
                        <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wide">Credentials</h2>
                    </div>
                    <button @click="showCredForm = !showCredForm"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-medium hover:bg-indigo-700">
                        <PlusIcon class="w-4 h-4" /> {{ showCredForm ? 'Cancel' : 'Add' }}
                    </button>
                </div>

                <!-- Add form -->
                <div v-if="showCredForm" class="px-5 py-4 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/30 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Type</label>
                            <select v-model="credForm.credential_type" class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2">
                                <option v-for="(label, key) in credentialTypes" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Title</label>
                            <input v-model="credForm.title" placeholder="e.g. RN License — CA"
                                class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">License State</label>
                            <input v-model="credForm.license_state" maxlength="2" placeholder="CA"
                                class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">License Number</label>
                            <input v-model="credForm.license_number"
                                class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Issued</label>
                            <input v-model="credForm.issued_at" type="date"
                                class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Expires</label>
                            <input v-model="credForm.expires_at" type="date"
                                class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Notes</label>
                        <textarea v-model="credForm.notes" rows="2"
                            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                    </div>
                    <div class="flex justify-end">
                        <button :disabled="credSaving" @click="submitCred"
                            class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
                            {{ credSaving ? 'Saving...' : 'Add Credential' }}
                        </button>
                    </div>
                </div>

                <!-- Credentials table -->
                <div v-if="credentials.length === 0" class="py-10 text-center text-sm text-slate-400">
                    No credentials on file.
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/30 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-5 py-3 text-left">Type</th>
                            <th class="px-5 py-3 text-left">Title</th>
                            <th class="px-5 py-3 text-left">License</th>
                            <th class="px-5 py-3 text-left">Issued</th>
                            <th class="px-5 py-3 text-left">Expires</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr v-for="c in credentials" :key="c.id">
                            <td class="px-5 py-3 text-slate-700 dark:text-slate-200">{{ c.type_label }}</td>
                            <td class="px-5 py-3 font-medium text-slate-800 dark:text-slate-100">{{ c.title }}</td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400 text-xs">
                                <template v-if="c.license_state || c.license_number">
                                    {{ c.license_state ?? '' }} {{ c.license_number ?? '' }}
                                </template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ c.issued_at ?? '—' }}</td>
                            <td class="px-5 py-3 text-slate-700 dark:text-slate-200 text-xs">
                                {{ c.expires_at ?? '—' }}
                                <span v-if="c.days_remaining !== null" class="block text-slate-400">
                                    {{ c.days_remaining < 0
                                        ? `${Math.abs(c.days_remaining)}d overdue`
                                        : c.days_remaining === 0 ? 'today' : `in ${c.days_remaining}d` }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', STATUS_CLASSES[c.status]]">
                                    <ExclamationTriangleIcon v-if="c.status === 'expired' || c.status === 'due_today'" class="w-3 h-3 mr-1" />
                                    {{ STATUS_LABELS[c.status] }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <button @click="deleteCred(c)" class="text-slate-400 hover:text-red-600 dark:hover:text-red-400" title="Remove">
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Training -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-2">
                        <AcademicCapIcon class="w-5 h-5 text-slate-500" />
                        <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wide">Training Records</h2>
                    </div>
                    <button @click="showTrainingForm = !showTrainingForm"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-medium hover:bg-indigo-700">
                        <PlusIcon class="w-4 h-4" /> {{ showTrainingForm ? 'Cancel' : 'Add' }}
                    </button>
                </div>

                <!-- Add form -->
                <div v-if="showTrainingForm" class="px-5 py-4 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/30 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Training Name</label>
                            <input v-model="trainingForm.training_name" placeholder="e.g. Annual HIPAA Refresher"
                                class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Category</label>
                            <select v-model="trainingForm.category" class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2">
                                <option v-for="(label, key) in trainingCategories" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Hours</label>
                            <input v-model.number="trainingForm.training_hours" type="number" min="0" max="99" step="0.25"
                                class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Completed</label>
                            <input v-model="trainingForm.completed_at" type="date"
                                class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Notes</label>
                        <textarea v-model="trainingForm.notes" rows="2"
                            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                    </div>
                    <div class="flex justify-end">
                        <button :disabled="trainingSaving" @click="submitTraining"
                            class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
                            {{ trainingSaving ? 'Saving...' : 'Add Training' }}
                        </button>
                    </div>
                </div>

                <!-- Hours-by-category chips -->
                <div v-if="Object.keys(hoursByCategory).length > 0" class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 flex flex-wrap gap-2 text-xs">
                    <span v-for="(hrs, cat) in hoursByCategory" :key="cat"
                        class="inline-flex items-center px-2 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 font-medium">
                        {{ trainingCategories[cat] ?? cat }}: {{ hrs }}h
                    </span>
                </div>

                <!-- Training table -->
                <div v-if="training.length === 0" class="py-10 text-center text-sm text-slate-400">
                    No training records.
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/30 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-5 py-3 text-left">Training</th>
                            <th class="px-5 py-3 text-left">Category</th>
                            <th class="px-5 py-3 text-left">Hours</th>
                            <th class="px-5 py-3 text-left">Completed</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr v-for="t in training" :key="t.id">
                            <td class="px-5 py-3 font-medium text-slate-800 dark:text-slate-100">{{ t.training_name }}</td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300 text-xs">{{ t.category_label }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ t.training_hours }}</td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ t.completed_at ?? '—' }}</td>
                            <td class="px-5 py-3 text-right">
                                <button @click="deleteTraining(t)" class="text-slate-400 hover:text-red-600 dark:hover:text-red-400">
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
