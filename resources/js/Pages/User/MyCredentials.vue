<script setup lang="ts">
// ─── User / My Credentials ───────────────────────────────────────────────────
// Self-service view for the currently authenticated staff member. Read-only
// list with status badges, missing-required banner, and a "Upload renewal"
// action per credential. Renewals set cms_status=pending until IT Admin
// re-verifies (so users can't silently mark themselves valid).
//
// Lives behind /my-credentials. Linked from the user dropdown in the header
// next to Notification Preferences.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    IdentificationIcon, ExclamationTriangleIcon, CheckCircleIcon,
    DocumentArrowUpIcon, DocumentArrowDownIcon, XMarkIcon, ClockIcon,
} from '@heroicons/vue/24/outline'

interface Credential {
    id: number
    credential_definition_id: number | null
    credential_type: string
    type_label: string
    title: string
    license_state: string | null
    license_number: string | null
    issued_at: string | null
    expires_at: string | null
    days_remaining: number | null
    status: string
    cms_status: string
    has_document: boolean
    document_filename: string | null
    is_superseded?: boolean
    ceu_hours_logged?: number
    ceu_hours_required?: number
}
interface MissingItem {
    id: number
    title: string
    is_cms_mandatory: boolean
    credential_type_label: string
}

const props = defineProps<{
    credentials: Credential[]
    missing: MissingItem[]
    me: { id: number, first_name: string, last_name: string, department: string, job_title: string | null }
}>()

const showAuditHistory = ref(false)
const visibleCredentials = computed(() =>
    props.credentials.filter(c => showAuditHistory.value || !c.is_superseded)
)
const supersededCount = computed(() => props.credentials.filter(c => c.is_superseded).length)

// G3 : credentials where CEU progress is fully met and the cycle is approaching
// expiry (within 60 days). The user can renew now since they've satisfied the
// continuing-ed requirement.
const ceuReadyForRenewal = computed(() => {
    return props.credentials.filter(c => {
        if (c.is_superseded) return false
        if ((c.ceu_hours_required ?? 0) === 0) return false
        if ((c.ceu_hours_logged ?? 0) < (c.ceu_hours_required ?? 0)) return false
        return ['due_60', 'due_30', 'due_14', 'due_today'].includes(c.status)
    })
})

const renewing = ref<Credential | null>(null)
const renewalForm = ref({ issued_at: '', expires_at: '' })
const renewalFile = ref<File | null>(null)
const submitting = ref(false)
const flash = ref<{ msg: string, kind: 'success'|'error' } | null>(null)

// D11 : report incorrect role assignment
const showAssignmentDispute = ref(false)
const disputeNote = ref('')
const submittingDispute = ref(false)
async function submitDispute() {
    if (disputeNote.value.trim().length < 10) {
        flash.value = { msg: 'Please describe the issue (min 10 characters).', kind: 'error' }
        return
    }
    submittingDispute.value = true
    try {
        const { data } = await axios.post('/my-credentials/report-assignment', { note: disputeNote.value })
        flash.value = { msg: data.message, kind: 'success' }
        showAssignmentDispute.value = false
        disputeNote.value = ''
    } catch (e: any) {
        flash.value = { msg: e?.response?.data?.message ?? 'Could not submit. Try again.', kind: 'error' }
    } finally {
        submittingDispute.value = false
    }
}

const STATUS_LABELS: Record<string, string> = {
    current: 'Current', no_expiry: 'No expiry',
    due_60: 'Due in 60d', due_30: 'Due in 30d', due_14: 'Due in 14d',
    due_today: 'Due today', expired: 'EXPIRED',
    suspended: 'SUSPENDED', revoked: 'REVOKED', pending: 'Pending verify',
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

function startRenewal(c: Credential) {
    renewing.value = c
    renewalForm.value = {
        issued_at: c.issued_at ?? new Date().toISOString().slice(0, 10),
        expires_at: c.expires_at ?? '',
    }
    renewalFile.value = null
}

function onFilePick(e: Event) {
    const input = e.target as HTMLInputElement
    renewalFile.value = input.files?.[0] ?? null
}

async function submitRenewal() {
    if (!renewing.value) return
    if (!renewalFile.value) {
        flash.value = { msg: 'Please attach a renewal document.', kind: 'error' }
        return
    }
    submitting.value = true
    try {
        const fd = new FormData()
        if (renewalForm.value.issued_at)  fd.append('issued_at',  renewalForm.value.issued_at)
        if (renewalForm.value.expires_at) fd.append('expires_at', renewalForm.value.expires_at)
        fd.append('document', renewalFile.value)

        await axios.post(`/my-credentials/${renewing.value.id}/renewal`, fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        flash.value = { msg: 'Renewal uploaded. IT Admin will verify and mark active.', kind: 'success' }
        renewing.value = null
        router.reload()
    } catch (e: any) {
        flash.value = { msg: e?.response?.data?.message ?? 'Could not upload renewal.', kind: 'error' }
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <AppShell>
        <Head title="My Credentials" />

        <div class="max-w-4xl mx-auto px-6 py-8">
            <div class="flex items-start gap-3 mb-6">
                <IdentificationIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                <div>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">My Credentials</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ me.first_name }} {{ me.last_name }} · {{ me.department.replace('_', ' ') }}<span v-if="me.job_title"> · {{ me.job_title }}</span>
                    </p>
                </div>
            </div>

            <!-- Flash -->
            <div v-if="flash" :class="[
                'rounded-lg border px-4 py-3 mb-4 text-sm flex items-start justify-between gap-3',
                flash.kind === 'success'
                    ? 'bg-emerald-50 dark:bg-emerald-950/30 border-emerald-200 dark:border-emerald-900/40 text-emerald-800 dark:text-emerald-200'
                    : 'bg-rose-50 dark:bg-rose-950/30 border-rose-200 dark:border-rose-900/40 text-rose-800 dark:text-rose-200'
            ]">
                <div class="flex items-center gap-2">
                    <CheckCircleIcon v-if="flash.kind === 'success'" class="w-4 h-4" />
                    <ExclamationTriangleIcon v-else class="w-4 h-4" />
                    {{ flash.msg }}
                </div>
                <button @click="flash = null" class="text-current/60 hover:text-current"><XMarkIcon class="w-4 h-4" /></button>
            </div>

            <!-- G3 : CEU-complete-ready-to-renew banner -->
            <div v-if="ceuReadyForRenewal.length > 0"
                 class="rounded-xl border border-emerald-300 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-950/30 px-5 py-4 text-sm mb-6">
                <div class="flex items-start gap-3">
                    <CheckCircleIcon class="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                    <div class="text-emerald-800 dark:text-emerald-200">
                        <p class="font-semibold mb-1">{{ ceuReadyForRenewal.length }} credential(s) ready for renewal</p>
                        <p class="mb-1 text-xs">You've completed the CEU hour requirement and the cycle is within 60 days of expiring. You can submit your renewal documentation now :</p>
                        <ul class="list-disc list-outside ml-5 space-y-0.5">
                            <li v-for="c in ceuReadyForRenewal" :key="c.id">
                                <strong>{{ c.title }}</strong> — {{ c.ceu_hours_logged }} / {{ c.ceu_hours_required }} CEU hours logged
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Missing -->
            <div v-if="missing.length > 0"
                 class="rounded-xl border border-rose-300 dark:border-rose-700 bg-rose-50 dark:bg-rose-950/30 px-5 py-4 text-sm mb-6">
                <div class="flex items-start gap-3">
                    <ExclamationTriangleIcon class="w-5 h-5 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
                    <div class="text-rose-800 dark:text-rose-200">
                        <p class="font-semibold mb-1">{{ missing.length }} required credential(s) not on file</p>
                        <ul class="list-disc list-outside ml-5 space-y-0.5">
                            <li v-for="m in missing" :key="m.id">
                                {{ m.title }} <span class="text-xs text-rose-600 dark:text-rose-400">({{ m.credential_type_label }})</span>
                                <span v-if="m.is_cms_mandatory" class="ml-1 inline-block px-1.5 py-0.5 rounded text-xs bg-rose-200 dark:bg-rose-900/60 text-rose-800 dark:text-rose-200">CMS-mandatory</span>
                            </li>
                        </ul>
                        <p class="mt-2 text-xs text-rose-700 dark:text-rose-300">Contact IT Admin or your supervisor to add these to your record.</p>
                    </div>
                </div>
            </div>

            <!-- Credentials table -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wide">
                        On file ({{ visibleCredentials.length }})
                    </h2>
                    <label v-if="supersededCount > 0" class="inline-flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400 cursor-pointer">
                        <input type="checkbox" v-model="showAuditHistory" class="rounded text-indigo-600" />
                        Show audit history ({{ supersededCount }})
                    </label>
                </div>
                <div v-if="visibleCredentials.length === 0" class="py-10 text-center text-sm text-slate-400">
                    No credentials on file yet.
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/30 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-5 py-3 text-left">Title</th>
                            <th class="px-5 py-3 text-left">Type</th>
                            <th class="px-5 py-3 text-left">Expires</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr v-for="c in visibleCredentials" :key="c.id" :class="c.is_superseded ? 'opacity-50 italic' : ''">
                            <td class="px-5 py-3 font-medium text-slate-800 dark:text-slate-100">
                                {{ c.title }}
                                <span v-if="c.is_superseded" class="ml-1.5 inline-block px-1.5 py-0.5 rounded text-[10px] bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 not-italic">Replaced ↗</span>
                            </td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ c.type_label }}</td>
                            <td class="px-5 py-3 text-slate-700 dark:text-slate-200 text-xs">
                                {{ c.expires_at ?? '-' }}
                                <span v-if="c.days_remaining !== null" class="block text-slate-400">
                                    {{ c.days_remaining < 0 ? `${Math.abs(c.days_remaining)}d overdue` : c.days_remaining === 0 ? 'today' : `in ${c.days_remaining}d` }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', STATUS_CLASSES[c.status]]">
                                    {{ STATUS_LABELS[c.status] ?? c.status }}
                                </span>
                                <span v-if="(c.ceu_hours_required ?? 0) > 0"
                                      class="block mt-1 text-xs"
                                      :class="(c.ceu_hours_logged ?? 0) >= (c.ceu_hours_required ?? 0)
                                          ? 'text-emerald-600 dark:text-emerald-400'
                                          : 'text-amber-600 dark:text-amber-400'">
                                    {{ c.ceu_hours_logged ?? 0 }} / {{ c.ceu_hours_required }} CEU hrs
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <a v-if="c.has_document" :href="`/staff-credentials/${c.id}/document`" target="_blank"
                                   class="text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 inline-block mr-2" title="View doc">
                                    <DocumentArrowDownIcon class="w-4 h-4" />
                                </a>
                                <button v-if="!c.is_superseded && c.cms_status !== 'pending'"
                                    @click="startRenewal(c)"
                                    class="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                    <DocumentArrowUpIcon class="w-3.5 h-3.5" /> Upload renewal
                                </button>
                                <span v-else-if="c.cms_status === 'pending'" class="text-xs text-amber-600 dark:text-amber-400">
                                    Awaiting verification
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400 mt-4 flex items-start gap-1.5">
                <ClockIcon class="w-3.5 h-3.5 mt-0.5 shrink-0" />
                Uploads via this page are flagged as self-attested and require IT Admin verification before becoming active.
            </p>
            <div class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                Looks wrong? <button @click="showAssignmentDispute = true" class="text-indigo-600 dark:text-indigo-400 hover:underline">Report incorrect job-title or supervisor assignment</button>
            </div>

            <!-- D11 : assignment-dispute modal -->
            <div v-if="showAssignmentDispute" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4" @click.self="showAssignmentDispute = false">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 max-w-md w-full p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Report assignment issue</h2>
                        <button @click="showAssignmentDispute = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"><XMarkIcon class="w-5 h-5" /></button>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">
                        Describe what's incorrect (job title, supervisor, or both). IT Admin will review and update your record.
                    </p>
                    <textarea v-model="disputeNote" rows="4" placeholder="e.g. My job title says LPN but I'm an RN..."
                        class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm" />
                    <div class="flex justify-end gap-2 mt-4">
                        <button @click="showAssignmentDispute = false" class="px-4 py-2 rounded-lg text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">Cancel</button>
                        <button @click="submitDispute" :disabled="submittingDispute" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium disabled:opacity-50">
                            {{ submittingDispute ? 'Sending...' : 'Send to IT Admin' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Renewal modal -->
            <div v-if="renewing" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4" @click.self="renewing = null">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 max-w-md w-full p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Upload renewal</h2>
                        <button @click="renewing = null" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"><XMarkIcon class="w-5 h-5" /></button>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300 mb-4">
                        Renewing : <strong>{{ renewing.title }}</strong>
                    </p>
                    <div class="space-y-3">
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">New issue date</span>
                            <input v-model="renewalForm.issued_at" type="date" class="w-full mt-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">New expiration date <span class="text-rose-500">*</span></span>
                            <input v-model="renewalForm.expires_at" type="date" :min="new Date().toISOString().slice(0,10)" required
                                class="w-full mt-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-sm" />
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Must be a future date.</p>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300 flex items-center gap-1">
                                <DocumentArrowUpIcon class="w-3.5 h-3.5" /> New document (PDF/JPG/PNG, max 10 MB)
                            </span>
                            <input type="file" accept="application/pdf,image/jpeg,image/png" @change="onFilePick"
                                   class="mt-1 block w-full text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-indigo-900/40 dark:file:text-indigo-300" />
                            <p v-if="renewalFile" class="text-xs text-slate-600 dark:text-slate-300 mt-1">{{ renewalFile.name }}</p>
                        </label>
                    </div>
                    <div class="flex justify-end gap-2 mt-6">
                        <button @click="renewing = null" class="px-4 py-2 rounded-lg text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">Cancel</button>
                        <button :disabled="submitting" @click="submitRenewal" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium disabled:opacity-50">
                            {{ submitting ? 'Uploading...' : 'Submit renewal' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
