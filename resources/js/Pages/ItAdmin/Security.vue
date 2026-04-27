<!-- ItAdmin/Security.vue -->
<!-- IT Admin security and compliance page with three tabs: BAA Records (Business Associate
     Agreements), SRA Records (Security Risk Assessments), and Encryption Status checklist.
     IT Admins can add and edit BAA/SRA records and view runtime encryption posture. -->

<script setup lang="ts">
// ─── ItAdmin/Security ───────────────────────────────────────────────────────
// HIPAA security posture dashboard: three tabs:
//   - BAA (Business Associate Agreement) records: signed contracts with
//     vendors that touch PHI on the org's behalf.
//   - SRA (Security Risk Analysis) records: annual self-audit per HIPAA.
//   - Encryption status: at-rest + in-transit checklist (runtime probed).
//
// Audience: IT Admin / Privacy Officer.
//
// Notable rules:
//   - HIPAA §164.308(a)(1)(ii)(A): periodic risk analysis required.
//   - HIPAA §164.308(b): written BAA required before any PHI sharing.
//   - Per-row append-only history; superseded BAAs/SRAs are retained.
// ────────────────────────────────────────────────────────────────────────────
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    ShieldCheckIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    PlusIcon,
} from '@heroicons/vue/24/outline'

interface BaaRecord {
    id: number
    vendor_name: string
    vendor_type: string
    phi_accessed: boolean
    baa_signed_date: string | null
    baa_expiration_date: string | null
    status: string
    contact_name: string | null
    contact_email: string | null
    notes: string | null
}

interface SraRecord {
    id: number
    sra_date: string
    conducted_by: string
    scope_description: string
    risk_level: string
    findings_summary: string | null
    next_sra_due: string | null
    status: string
}

interface EncryptionCheck {
    status: 'pass' | 'warn' | 'fail'
    label: string
    detail: string
}

interface PostureInfo {
    expired_baa_count: number
    expiring_soon_count: number
    sra_overdue: boolean
    session_encrypted: boolean
    db_ssl_enforced: boolean
    field_encryption: boolean
    latest_sra_date: string | null
}

interface Props {
    baaRecords: BaaRecord[]
    sraRecords: SraRecord[]
    encryptionStatus: { checks: Record<string, EncryptionCheck> }
    vendorTypes: string[]
    baaStatuses: string[]
    sraRiskLevels: string[]
    sraStatuses: string[]
    posture: PostureInfo
}

const props = defineProps<Props>()

const activeTab = ref<'baa' | 'sra' | 'encryption'>('baa')

// BAA modal state
const showBaaModal = ref(false)
const baaModalMode = ref<'add' | 'edit'>('add')
const baaSubmitting = ref(false)
const editingBaaId = ref<number | null>(null)
const baaForm = ref({
    vendor_name: '',
    vendor_type: '',
    phi_accessed: false,
    baa_signed_date: '',
    baa_expiration_date: '',
    status: 'active',
    contact_name: '',
    contact_email: '',
    notes: '',
})

// SRA modal state
const showSraModal = ref(false)
const sraModalMode = ref<'add' | 'edit'>('add')
const sraSubmitting = ref(false)
const editingSraId = ref<number | null>(null)
const sraForm = ref({
    sra_date: '',
    conducted_by: '',
    scope_description: '',
    risk_level: 'moderate',
    findings_summary: '',
    next_sra_due: '',
    status: 'in_progress',
})

const openAddBaa = () => {
    baaModalMode.value = 'add'
    editingBaaId.value = null
    baaForm.value = { vendor_name: '', vendor_type: '', phi_accessed: false, baa_signed_date: '', baa_expiration_date: '', status: 'active', contact_name: '', contact_email: '', notes: '' }
    showBaaModal.value = true
}

const openEditBaa = (r: BaaRecord) => {
    baaModalMode.value = 'edit'
    editingBaaId.value = r.id
    baaForm.value = {
        vendor_name: r.vendor_name, vendor_type: r.vendor_type, phi_accessed: r.phi_accessed,
        baa_signed_date: r.baa_signed_date ?? '', baa_expiration_date: r.baa_expiration_date ?? '',
        status: r.status, contact_name: r.contact_name ?? '', contact_email: r.contact_email ?? '', notes: r.notes ?? '',
    }
    showBaaModal.value = true
}

const submitBaa = async () => {
    baaSubmitting.value = true
    try {
        if (baaModalMode.value === 'add') {
            await axios.post('/it-admin/baa', baaForm.value)
        } else {
            await axios.put(`/it-admin/baa/${editingBaaId.value}`, baaForm.value)
        }
        showBaaModal.value = false
        router.reload({ only: ['baaRecords', 'posture'] })
    } catch {
        // silently handle
    } finally {
        baaSubmitting.value = false
    }
}

const openAddSra = () => {
    sraModalMode.value = 'add'
    editingSraId.value = null
    sraForm.value = { sra_date: '', conducted_by: '', scope_description: '', risk_level: 'moderate', findings_summary: '', next_sra_due: '', status: 'in_progress' }
    showSraModal.value = true
}

const openEditSra = (r: SraRecord) => {
    sraModalMode.value = 'edit'
    editingSraId.value = r.id
    sraForm.value = {
        sra_date: r.sra_date, conducted_by: r.conducted_by, scope_description: r.scope_description,
        risk_level: r.risk_level, findings_summary: r.findings_summary ?? '',
        next_sra_due: r.next_sra_due ?? '', status: r.status,
    }
    showSraModal.value = true
}

const submitSra = async () => {
    sraSubmitting.value = true
    try {
        if (sraModalMode.value === 'add') {
            await axios.post('/it-admin/sra', sraForm.value)
        } else {
            await axios.put(`/it-admin/sra/${editingSraId.value}`, sraForm.value)
        }
        showSraModal.value = false
        router.reload({ only: ['sraRecords', 'posture'] })
    } catch {
        // silently handle
    } finally {
        sraSubmitting.value = false
    }
}

const statusIcon = (status: 'pass' | 'warn' | 'fail') => {
    if (status === 'pass') return CheckCircleIcon
    if (status === 'warn') return ExclamationTriangleIcon
    return XCircleIcon
}

const statusClass = (status: 'pass' | 'warn' | 'fail') => {
    if (status === 'pass') return 'text-green-600 dark:text-green-400'
    if (status === 'warn') return 'text-amber-600 dark:text-amber-400'
    return 'text-red-600 dark:text-red-400'
}

const baaStatusBg = (status: string) => {
    if (status === 'active') return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300'
    if (status === 'expired') return 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300'
    if (status === 'expiring_soon') return 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'
    return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'
}

const riskBg = (level: string) => {
    if (level === 'low') return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300'
    if (level === 'moderate') return 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300'
    if (level === 'high') return 'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300'
    if (level === 'critical') return 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300'
    return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'
}

const formatDate = (iso: string | null) => iso
    ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'short' })
    : '-'
</script>

<template>
    <AppShell>
        <Head title="IT Admin: Security Compliance" />

        <div class="max-w-5xl mx-auto px-6 py-8">
            <!-- Header with posture chips -->
            <div class="flex items-start justify-between mb-6">
                <div class="flex items-center gap-3">
                    <ShieldCheckIcon class="w-7 h-7 text-blue-600 dark:text-blue-400" />
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Security Compliance</h1>
                        <p class="text-sm text-gray-500 dark:text-slate-400">BAA/SRA tracking and encryption status</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 justify-end">
                    <span v-if="props.posture.expired_baa_count > 0"
                        class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300">
                        <XCircleIcon class="w-3.5 h-3.5" />
                        {{ props.posture.expired_baa_count }} BAA expired
                    </span>
                    <span v-if="props.posture.expiring_soon_count > 0"
                        class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                        <ExclamationTriangleIcon class="w-3.5 h-3.5" />
                        {{ props.posture.expiring_soon_count }} expiring soon
                    </span>
                    <span :class="props.posture.sra_overdue ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' : 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300'"
                        class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium">
                        <CheckCircleIcon v-if="!props.posture.sra_overdue" class="w-3.5 h-3.5" />
                        <ExclamationTriangleIcon v-else class="w-3.5 h-3.5" />
                        SRA {{ props.posture.sra_overdue ? 'Overdue' : 'Current' }}
                    </span>
                    <span :class="props.posture.field_encryption ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'"
                        class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium">
                        <CheckCircleIcon v-if="props.posture.field_encryption" class="w-3.5 h-3.5" />
                        <ExclamationTriangleIcon v-else class="w-3.5 h-3.5" />
                        Fields {{ props.posture.field_encryption ? 'Encrypted' : 'Plaintext' }}
                    </span>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-gray-200 dark:border-slate-700 mb-6">
                <nav class="flex gap-6">
                    <button v-for="tab in (['baa', 'sra', 'encryption'] as const)" :key="tab"
                        @click="activeTab = tab"
                        :class="activeTab === tab
                            ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400'
                            : 'text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200'"
                        class="pb-3 text-sm font-medium capitalize transition-colors"
                    >
                        {{ tab === 'baa' ? 'BAA Records' : tab === 'sra' ? 'SRA Records' : 'Encryption Status' }}
                    </button>
                </nav>
            </div>

            <!-- BAA Tab -->
            <div v-if="activeTab === 'baa'">
                <div class="flex justify-end mb-4">
                    <button @click="openAddBaa"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition-colors"
                        aria-label="Add BAA record">
                        <PlusIcon class="w-4 h-4" /> Add BAA
                    </button>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-700/50">
                            <tr>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Vendor</th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Type</th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Status</th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Expires</th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            <tr v-for="r in props.baaRecords" :key="r.id"
                                :class="r.status === 'expired' ? 'bg-red-50 dark:bg-red-900/10' : r.status === 'expiring_soon' ? 'bg-amber-50 dark:bg-amber-900/10' : 'hover:bg-gray-50 dark:hover:bg-slate-700/50'">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{{ r.vendor_name }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400 capitalize">{{ r.vendor_type.replace('_', ' ') }}</td>
                                <td class="px-4 py-3">
                                    <span :class="baaStatusBg(r.status)" class="inline-block px-2 py-0.5 rounded-full text-xs font-medium capitalize">
                                        {{ r.status.replace('_', ' ') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-slate-400">{{ formatDate(r.baa_expiration_date) }}</td>
                                <td class="px-4 py-3">
                                    <button @click="openEditBaa(r)"
                                        class="text-xs px-2 py-1 rounded border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 transition-colors"
                                        :aria-label="`Edit BAA for ${r.vendor_name}`">Edit</button>
                                </td>
                            </tr>
                            <tr v-if="props.baaRecords.length === 0">
                                <td colspan="5" class="py-12 text-center text-gray-500 dark:text-slate-400">No BAA records.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SRA Tab -->
            <div v-if="activeTab === 'sra'">
                <div class="flex justify-end mb-4">
                    <button @click="openAddSra"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition-colors"
                        aria-label="Add SRA record">
                        <PlusIcon class="w-4 h-4" /> Add SRA
                    </button>
                </div>
                <div class="space-y-4">
                    <div v-for="r in props.sraRecords" :key="r.id"
                        class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="font-medium text-gray-900 dark:text-slate-100">
                                    {{ formatDate(r.sra_date) }} - Conducted by {{ r.conducted_by }}
                                </div>
                                <div class="text-sm text-gray-500 dark:text-slate-400 mt-1">{{ r.scope_description }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span :class="riskBg(r.risk_level)" class="px-2 py-0.5 rounded-full text-xs font-medium capitalize">
                                    {{ r.risk_level }} risk
                                </span>
                                <button @click="openEditSra(r)"
                                    class="text-xs px-2 py-1 rounded border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 transition-colors"
                                    :aria-label="`Edit SRA from ${r.sra_date}`">Edit</button>
                            </div>
                        </div>
                        <div v-if="r.findings_summary" class="mt-2 text-sm text-gray-600 dark:text-slate-400 border-t border-gray-100 dark:border-slate-700 pt-2">
                            {{ r.findings_summary }}
                        </div>
                        <div class="mt-2 text-xs text-gray-400 dark:text-slate-500">
                            Next SRA due: {{ formatDate(r.next_sra_due) }}
                        </div>
                    </div>
                    <div v-if="props.sraRecords.length === 0" class="py-12 text-center text-gray-500 dark:text-slate-400">No SRA records.</div>
                </div>
            </div>

            <!-- Encryption Tab -->
            <div v-if="activeTab === 'encryption'">
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
                        <h3 class="font-semibold text-gray-700 dark:text-slate-300 text-sm">Runtime Encryption Checks (HIPAA 45 CFR 164.312)</h3>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-slate-700">
                        <div v-for="(check, key) in props.encryptionStatus.checks" :key="key"
                            class="flex items-center justify-between px-4 py-4">
                            <div>
                                <div class="font-medium text-gray-900 dark:text-slate-100 text-sm">{{ check.label }}</div>
                                <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ check.detail }}</div>
                            </div>
                            <component :is="statusIcon(check.status)"
                                class="w-5 h-5 flex-shrink-0"
                                :class="statusClass(check.status)"
                                :aria-label="check.status" />
                        </div>
                    </div>
                </div>
                <div class="mt-4 p-4 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                    <p class="text-sm text-blue-800 dark:text-blue-300 font-medium">Production Go-Live Requirements</p>
                    <ul class="mt-2 text-sm text-blue-700 dark:text-blue-400 space-y-1 list-disc list-inside">
                        <li>Set <code class="font-mono">SESSION_ENCRYPT=true</code> in production .env</li>
                        <li>Set <code class="font-mono">DB_SSLMODE=require</code> for encrypted DB connections</li>
                        <li>Configure <code class="font-mono">FILESYSTEM_DISK=s3</code> with SSE-KMS for documents</li>
                        <li>Set <code class="font-mono">REDIS_PASSWORD</code> to secure Redis connections</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- BAA Modal -->
        <div v-if="showBaaModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg mx-4 p-6 max-h-screen overflow-y-auto">
                <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100 mb-4">
                    {{ baaModalMode === 'add' ? 'Add BAA Record' : 'Edit BAA Record' }}
                </h2>
                <form @submit.prevent="submitBaa" class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="baa-vendor">Vendor Name</label>
                        <input id="baa-vendor" v-model="baaForm.vendor_name" required type="text"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="baa-type">Vendor Type</label>
                            <select id="baa-type" v-model="baaForm.vendor_type" required
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm text-gray-700 dark:text-slate-300">
                                <option value="">Select...</option>
                                <option v-for="t in props.vendorTypes" :key="t" :value="t">{{ t.replace('_', ' ') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="baa-status">Status</label>
                            <select id="baa-status" v-model="baaForm.status"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm text-gray-700 dark:text-slate-300">
                                <option v-for="s in props.baaStatuses" :key="s" :value="s">{{ s.replace('_', ' ') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="baa-signed">Signed Date</label>
                            <input id="baa-signed" v-model="baaForm.baa_signed_date" type="date"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="baa-expires">Expiration Date</label>
                            <input id="baa-expires" v-model="baaForm.baa_expiration_date" type="date"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <input id="baa-phi" v-model="baaForm.phi_accessed" type="checkbox" class="rounded border-gray-300 dark:border-slate-600" />
                        <label for="baa-phi" class="text-sm text-gray-700 dark:text-slate-300">Vendor accesses PHI</label>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="baa-notes">Notes</label>
                        <textarea id="baa-notes" v-model="baaForm.notes" rows="2"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="showBaaModal = false"
                            class="px-4 py-2 text-sm rounded-lg text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 transition-colors">Cancel</button>
                        <button type="submit" :disabled="baaSubmitting"
                            class="px-4 py-2 text-sm rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium disabled:opacity-50 transition-colors">
                            {{ baaSubmitting ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- SRA Modal -->
        <div v-if="showSraModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg mx-4 p-6 max-h-screen overflow-y-auto">
                <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100 mb-4">
                    {{ sraModalMode === 'add' ? 'Add SRA Record' : 'Edit SRA Record' }}
                </h2>
                <form @submit.prevent="submitSra" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sra-date">SRA Date</label>
                            <input id="sra-date" v-model="sraForm.sra_date" required type="date"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sra-conducted">Conducted By</label>
                            <input id="sra-conducted" v-model="sraForm.conducted_by" required type="text"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sra-scope">Scope Description</label>
                        <textarea id="sra-scope" v-model="sraForm.scope_description" required rows="2"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sra-risk">Risk Level</label>
                            <select id="sra-risk" v-model="sraForm.risk_level"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm text-gray-700 dark:text-slate-300">
                                <option v-for="l in props.sraRiskLevels" :key="l" :value="l">{{ l }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sra-status">Status</label>
                            <select id="sra-status" v-model="sraForm.status"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm text-gray-700 dark:text-slate-300">
                                <option v-for="s in props.sraStatuses" :key="s" :value="s">{{ s.replace('_', ' ') }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sra-findings">Findings Summary</label>
                        <textarea id="sra-findings" v-model="sraForm.findings_summary" rows="3"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="sra-next">Next SRA Due</label>
                        <input id="sra-next" v-model="sraForm.next_sra_due" type="date"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="showSraModal = false"
                            class="px-4 py-2 text-sm rounded-lg text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 transition-colors">Cancel</button>
                        <button type="submit" :disabled="sraSubmitting"
                            class="px-4 py-2 text-sm rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium disabled:opacity-50 transition-colors">
                            {{ sraSubmitting ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppShell>
</template>
