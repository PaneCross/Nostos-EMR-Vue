<script setup lang="ts">
// ─── ItAdmin/StaffCredentials ───────────────────────────────────────────────
// Personnel credentials + annual training hours: licensure (RN, MD, PT, OT,
// SW etc.), DEA, BLS / CPR, TB clearance, and required PACE training hours
// per role.
//
// Audience: IT Admin / HR / Center Manager.
//
// Notable rules:
//   - 42 CFR §460.64-71: staff qualification + training requirements;
//     CMS surveyors will pull credentials per personnel audit protocol.
//   - Daily expiration alert job warns at T-30/T-7 and escalates after lapse.
//   - Append-only credential history; superseded rows retained.
// ────────────────────────────────────────────────────────────────────────────

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
    PencilSquareIcon,
    DocumentArrowUpIcon,
    DocumentArrowDownIcon,
    ShieldCheckIcon,
    XMarkIcon,
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
    status: string
    verified_at: string | null
    notes: string | null
    document_url?: string | null
    credential_definition_id?: number | null
    verification_source?: string | null
    cms_status?: string | null
}
interface ApplicableDefinition {
    id: number
    code: string
    title: string
    credential_type: string
    requires_psv: boolean
    is_cms_mandatory: boolean
    default_doc_required: boolean
}
interface MissingDefinition {
    id: number
    code: string
    title: string
    is_cms_mandatory: boolean
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
    staff: { id: number; first_name: string; last_name: string; email: string; department: string; role: string; job_title: string | null; is_active: boolean }
    credentials: Credential[]
    training: Training[]
    hoursByCategory: Record<string, number>
    totalHours12mo: number
    credentialTypes: Record<string, string>
    trainingCategories: Record<string, string>
    applicableDefinitions?: ApplicableDefinition[]
    missingDefinitions?: MissingDefinition[]
    verificationSources?: Record<string, string>
    cmsStatuses?: Record<string, string>
}>()

// ── Credential form ──────────────────────────────────────────────────────────

const showCredForm = ref(false)
const credSaving = ref(false)
const editingCred = ref<Credential | null>(null)
const credFile = ref<File | null>(null)
const credForm = ref({
    credential_definition_id: '' as string | number,
    credential_type: 'license',
    title: '',
    license_state: '',
    license_number: '',
    issued_at: '',
    expires_at: '',
    verification_source: '',
    cms_status: 'active',
    notes: '',
})

function resetCred() {
    credForm.value = {
        credential_definition_id: '',
        credential_type: 'license',
        title: '',
        license_state: '',
        license_number: '',
        issued_at: '',
        expires_at: '',
        verification_source: '',
        cms_status: 'active',
        notes: '',
    }
    credFile.value = null
}

// When user picks a definition from the dropdown, prefill type + title
function onDefinitionChange() {
    const id = Number(credForm.value.credential_definition_id)
    if (!id) return
    const def = props.applicableDefinitions?.find(d => d.id === id)
    if (def) {
        credForm.value.credential_type = def.credential_type
        credForm.value.title = def.title
        if (def.requires_psv && !credForm.value.verification_source) {
            credForm.value.verification_source = 'state_board'
        }
    }
}

function buildFormData(form: any): FormData {
    const fd = new FormData()
    Object.entries(form).forEach(([k, v]) => {
        if (v === null || v === undefined || v === '') return
        fd.append(k, String(v))
    })
    if (credFile.value) fd.append('document', credFile.value)
    return fd
}

async function submitCred() {
    if (!credForm.value.title.trim()) { alert('Title is required'); return }
    credSaving.value = true
    try {
        await axios.post(
            `/it-admin/users/${props.staff.id}/credentials`,
            buildFormData(credForm.value),
            { headers: { 'Content-Type': 'multipart/form-data' } }
        )
        showCredForm.value = false
        resetCred()
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to save credential.')
    } finally {
        credSaving.value = false
    }
}

function startEditCred(c: Credential) {
    editingCred.value = { ...c }
    credFile.value = null
}

async function submitEditCred() {
    if (!editingCred.value) return
    credSaving.value = true
    try {
        const payload = {
            credential_definition_id: editingCred.value.credential_definition_id ?? '',
            title: editingCred.value.title,
            license_state: editingCred.value.license_state ?? '',
            license_number: editingCred.value.license_number ?? '',
            issued_at: editingCred.value.issued_at ?? '',
            expires_at: editingCred.value.expires_at ?? '',
            verification_source: editingCred.value.verification_source ?? '',
            cms_status: editingCred.value.cms_status ?? 'active',
            notes: editingCred.value.notes ?? '',
        }
        const fd = new FormData()
        Object.entries(payload).forEach(([k, v]) => {
            if (v === null || v === undefined || v === '') return
            fd.append(k, String(v))
        })
        if (credFile.value) fd.append('document', credFile.value)
        // Laravel needs _method=PATCH for multipart on POST
        fd.append('_method', 'PATCH')
        await axios.post(
            `/it-admin/staff-credentials/${editingCred.value.id}`,
            fd,
            { headers: { 'Content-Type': 'multipart/form-data' } }
        )
        editingCred.value = null
        router.reload()
    } catch (err: any) {
        alert(err?.response?.data?.message ?? 'Failed to update credential.')
    } finally {
        credSaving.value = false
    }
}

async function deleteCred(c: Credential) {
    if (!confirm(`Remove credential "${c.title}"?`)) return
    await axios.delete(`/it-admin/staff-credentials/${c.id}`)
    router.reload()
}

function onFilePick(e: Event) {
    const input = e.target as HTMLInputElement
    credFile.value = input.files?.[0] ?? null
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
    suspended: 'SUSPENDED',
    revoked: 'REVOKED',
    pending: 'Pending verify',
}

const STATUS_CLASSES: Record<string, string> = {
    current:   'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    no_expiry: 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    due_60:    'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    due_30:    'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    due_14:    'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300',
    due_today: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    expired:   'bg-red-600 text-white',
    suspended: 'bg-rose-700 text-white',
    revoked:   'bg-rose-900 text-white',
    pending:   'bg-amber-200 dark:bg-amber-900/60 text-amber-800 dark:text-amber-200',
}

const expiringCount = computed(() =>
    props.credentials.filter(c => ['expired', 'due_today', 'due_14', 'due_30', 'due_60'].includes(c.status)).length
)
</script>

<template>
    <AppShell>
        <Head :title="`Credentials: ${staff.first_name} ${staff.last_name}`" />

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
                            Staff credentials &amp; training: 42 CFR §460.64-71
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

            <!-- Missing-required banner -->
            <div v-if="(missingDefinitions?.length ?? 0) > 0"
                 class="rounded-xl border border-rose-300 dark:border-rose-700 bg-rose-50 dark:bg-rose-950/30 px-5 py-4 text-sm">
                <div class="flex items-start gap-3">
                    <ExclamationTriangleIcon class="w-5 h-5 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
                    <div class="text-rose-800 dark:text-rose-200">
                        <p class="font-semibold mb-1">{{ missingDefinitions!.length }} required credential(s) missing for this user.</p>
                        <ul class="list-disc list-outside ml-5 space-y-0.5">
                            <li v-for="m in missingDefinitions" :key="m.id">
                                {{ m.title }}
                                <span v-if="m.is_cms_mandatory" class="ml-1 inline-block px-1.5 py-0.5 rounded text-xs bg-rose-200 dark:bg-rose-900/60 text-rose-800 dark:text-rose-200">CMS-mandatory</span>
                            </li>
                        </ul>
                    </div>
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
                    <!-- Definition picker (prefills type+title from catalog) -->
                    <div v-if="(applicableDefinitions?.length ?? 0) > 0">
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Pick from catalog (recommended)</label>
                        <select v-model="credForm.credential_definition_id" @change="onDefinitionChange"
                            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2">
                            <option value="">Custom (free-form, not linked to catalog)</option>
                            <option v-for="d in applicableDefinitions" :key="d.id" :value="d.id">
                                {{ d.title }}{{ d.is_cms_mandatory ? ' [CMS-mandatory]' : '' }}{{ d.requires_psv ? ' [PSV]' : '' }}
                            </option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Type</label>
                            <select v-model="credForm.credential_type" class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2">
                                <option v-for="(label, key) in credentialTypes" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Title</label>
                            <input v-model="credForm.title" placeholder="e.g. RN License: CA"
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
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1 flex items-center gap-1">
                                <ShieldCheckIcon class="w-3.5 h-3.5" /> Verification source (PSV)
                            </label>
                            <select v-model="credForm.verification_source" class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2">
                                <option value="">- not yet verified -</option>
                                <option v-for="(label, key) in verificationSources ?? {}" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Status</label>
                            <select v-model="credForm.cms_status" class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2">
                                <option v-for="(label, key) in cmsStatuses ?? {}" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1 flex items-center gap-1">
                            <DocumentArrowUpIcon class="w-3.5 h-3.5" /> Supporting document (PDF / JPG / PNG, max 10 MB)
                        </label>
                        <input type="file" accept="application/pdf,image/jpeg,image/png" @change="onFilePick"
                               class="block w-full text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-indigo-900/40 dark:file:text-indigo-300" />
                        <p v-if="credFile" class="mt-1 text-xs text-slate-600 dark:text-slate-300">Selected: {{ credFile.name }}</p>
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
                                <span v-else class="text-slate-400">-</span>
                            </td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ c.issued_at ?? '-' }}</td>
                            <td class="px-5 py-3 text-slate-700 dark:text-slate-200 text-xs">
                                {{ c.expires_at ?? '-' }}
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
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <a v-if="c.document_url" :href="c.document_url" target="_blank"
                                    class="text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 inline-block mr-1" title="Open document">
                                    <DocumentArrowDownIcon class="w-4 h-4" />
                                </a>
                                <button @click="startEditCred(c)" class="text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 mr-1" title="Edit">
                                    <PencilSquareIcon class="w-4 h-4" />
                                </button>
                                <button @click="deleteCred(c)" class="text-slate-400 hover:text-red-600 dark:hover:text-red-400" title="Remove">
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Edit modal -->
            <div v-if="editingCred" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4 overflow-y-auto" @click.self="editingCred = null">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 max-w-2xl w-full p-6 my-8 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Edit credential</h2>
                        <button @click="editingCred = null" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"><XMarkIcon class="w-5 h-5" /></button>
                    </div>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <label class="block col-span-2">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Title</span>
                            <input v-model="editingCred.title" class="w-full mt-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">License State</span>
                            <input v-model="editingCred.license_state" maxlength="2" class="w-full mt-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">License Number</span>
                            <input v-model="editingCred.license_number" class="w-full mt-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Issued</span>
                            <input v-model="editingCred.issued_at" type="date" class="w-full mt-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Expires</span>
                            <input v-model="editingCred.expires_at" type="date" class="w-full mt-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Verification source</span>
                            <select v-model="editingCred.verification_source" class="w-full mt-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-sm">
                                <option value="">- not verified -</option>
                                <option v-for="(label, key) in verificationSources ?? {}" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Status</span>
                            <select v-model="editingCred.cms_status" class="w-full mt-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-sm">
                                <option v-for="(label, key) in cmsStatuses ?? {}" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </label>
                        <label class="block col-span-2">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300 flex items-center gap-1">
                                <DocumentArrowUpIcon class="w-3.5 h-3.5" /> Replace document (optional)
                            </span>
                            <input type="file" accept="application/pdf,image/jpeg,image/png" @change="onFilePick"
                                   class="mt-1 block w-full text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-indigo-900/40 dark:file:text-indigo-300" />
                            <a v-if="editingCred.document_url" :href="editingCred.document_url" target="_blank" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline mt-1 inline-block">View current document</a>
                        </label>
                        <label class="block col-span-2">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Notes</span>
                            <textarea v-model="editingCred.notes" rows="2" class="w-full mt-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-sm"></textarea>
                        </label>
                    </div>
                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                        <button @click="editingCred = null" class="px-4 py-2 rounded-lg text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">Cancel</button>
                        <button @click="submitEditCred" :disabled="credSaving" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium disabled:opacity-50">
                            {{ credSaving ? 'Saving...' : 'Save changes' }}
                        </button>
                    </div>
                </div>
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
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ t.completed_at ?? '-' }}</td>
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
