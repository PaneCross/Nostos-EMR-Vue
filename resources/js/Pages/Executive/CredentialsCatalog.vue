<script setup lang="ts">
// ─── Executive / Credentials Catalog ─────────────────────────────────────────
// Org-level catalog of credential definitions. CMS-mandatory rows (seeded from
// 42 CFR §460.71 + 45 CFR §164.530(b)) are locked : cannot be deleted, code/type
// immutable. Targeting via 3 OR'd dimensions (department / job_title /
// designation). Per-site disabled overrides (non-mandatory only).
// ─────────────────────────────────────────────────────────────────────────────

import { ref, onMounted, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import OrgSettingsTabBar from '@/Pages/Executive/components/OrgSettingsTabBar.vue'
import axios from 'axios'
import {
    AcademicCapIcon, PlusIcon, PencilSquareIcon, TrashIcon, LockClosedIcon,
    InformationCircleIcon, ShieldCheckIcon, DocumentArrowUpIcon,
    BuildingOfficeIcon, BriefcaseIcon, IdentificationIcon, CheckIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

interface Target { kind: 'department'|'job_title'|'designation', value: string }
interface SiteOverride { site_id: number, site_name: string, action: string }
interface Definition {
    id: number
    site_id: number | null
    code: string
    title: string
    credential_type: string
    credential_type_label: string
    description: string | null
    requires_psv: boolean
    is_cms_mandatory: boolean
    default_doc_required: boolean
    reminder_cadence_days: number[] | null
    ceu_hours_required: number
    is_active: boolean
    sort_order: number
    targets: Target[]
    site_overrides: SiteOverride[]
}

const definitions = ref<Definition[]>([])
const departments = ref<string[]>([])
const jobTitles = ref<{ code: string, label: string }[]>([])
const designations = ref<string[]>([])
const sites = ref<{ id: number, name: string }[]>([])
const credentialTypes = ref<Record<string, string>>({})
const loading = ref(true)
const successMessage = ref<string | null>(null)
const errorMessage = ref<string | null>(null)
const editing = ref<Definition | null>(null)
const isNew = ref(false)
const cadenceInput = ref('90,30,14,0')

// Standard cadence steps with descriptions of who gets notified at each.
// These mirror the layered-escalation logic in CredentialExpirationAlertJob :
// 90/60/30 → user only, 14/7 → user + supervisor, 0/negative → user +
// supervisor + QA Compliance.
interface CadenceStep { days: number; label: string; recipients: string }

const STANDARD_CADENCE_STEPS: CadenceStep[] = [
    { days: 120, label: '120 days before',  recipients: 'Email staff member only' },
    { days: 90,  label: '90 days before',   recipients: 'Email staff member only' },
    { days: 60,  label: '60 days before',   recipients: 'Email staff member only' },
    { days: 30,  label: '30 days before',   recipients: 'Email staff member only' },
    { days: 14,  label: '14 days before',   recipients: 'Email staff + supervisor' },
    { days: 7,   label: '7 days before',    recipients: 'Email staff + supervisor' },
    { days: 0,   label: 'Day of expiration',recipients: 'Email staff + supervisor + QA Compliance alert (REQUIRED)' },
    { days: -7,  label: '7 days overdue',   recipients: 'Re-alert all parties + QA Compliance escalation' },
    { days: -14, label: '14 days overdue',  recipients: 'Re-alert all parties + QA Compliance escalation' },
    { days: -30, label: '30 days overdue',  recipients: 'Re-alert all parties + QA Compliance escalation' },
]

// Modal-state for the "Add custom step" flow
const showCustomStepModal = ref(false)
const customStepDays = ref<number | ''>('')

/** Auto-derive recipient tier from day value, matching the alert-job logic. */
function recipientsForDays(days: number): string {
    if (days < 0)  return 'Re-alert staff + supervisor + QA Compliance escalation'
    if (days === 0) return 'Email staff + supervisor + QA Compliance alert (REQUIRED)'
    if (days <= 14) return 'Email staff + supervisor'
    return 'Email staff member only'
}

/** Custom steps : days currently in cadenceInput that aren't in the standard list. */
const customCadenceSteps = computed<CadenceStep[]>(() => {
    const standardDays = new Set(STANDARD_CADENCE_STEPS.map(s => s.days))
    return parseCadence()
        .filter(d => !standardDays.has(d))
        .sort((a, b) => b - a)
        .map(days => ({
            days,
            label: days < 0 ? `${Math.abs(days)} days overdue` : days === 0 ? 'Day of expiration' : `${days} days before`,
            recipients: recipientsForDays(days),
        }))
})

function isCadenceStepActive(days: number): boolean {
    return parseCadence().includes(days)
}

function toggleCadenceStep(days: number) {
    const current = new Set(parseCadence())
    if (current.has(days)) current.delete(days)
    else current.add(days)
    cadenceInput.value = Array.from(current).sort((a, b) => b - a).join(',')
}

function openCustomStepModal() {
    customStepDays.value = ''
    showCustomStepModal.value = true
}

function submitCustomStep() {
    const n = typeof customStepDays.value === 'number' ? customStepDays.value : parseInt(String(customStepDays.value), 10)
    if (isNaN(n) || n < -30 || n > 365) {
        // Reject silently with toast-ish feedback ; could be improved
        return
    }
    // Don't duplicate standard days
    const standardDays = new Set(STANDARD_CADENCE_STEPS.map(s => s.days))
    if (standardDays.has(n)) {
        // It's already a standard step ; just enable it
        const current = new Set(parseCadence())
        current.add(n)
        cadenceInput.value = Array.from(current).sort((a, b) => b - a).join(',')
        showCustomStepModal.value = false
        return
    }
    const current = new Set(parseCadence())
    current.add(n)
    cadenceInput.value = Array.from(current).sort((a, b) => b - a).join(',')
    showCustomStepModal.value = false
}

const customStepPreview = computed(() => {
    const n = typeof customStepDays.value === 'number' ? customStepDays.value : parseInt(String(customStepDays.value), 10)
    if (isNaN(n)) return null
    if (n < -30 || n > 365) return null
    return {
        label: n < 0 ? `${Math.abs(n)} days overdue` : n === 0 ? 'Day of expiration' : `${n} days before`,
        recipients: recipientsForDays(n),
    }
})

const sortedDefs = computed(() => [...definitions.value].sort((a, b) =>
    a.sort_order - b.sort_order || a.title.localeCompare(b.title)
))

async function load() {
    loading.value = true
    try {
        const { data } = await axios.get('/executive/credential-definitions')
        definitions.value = data.definitions
        departments.value = data.departments
        jobTitles.value = data.jobTitles
        designations.value = data.designations
        sites.value = data.sites
        credentialTypes.value = data.credentialTypes
    } finally {
        loading.value = false
    }
}

function startNew() {
    isNew.value = true
    editing.value = {
        id: 0, site_id: null, code: '', title: '', credential_type: 'training',
        credential_type_label: '', description: null,
        requires_psv: false, is_cms_mandatory: false, default_doc_required: false,
        reminder_cadence_days: [90, 30, 14, 0], ceu_hours_required: 0,
        is_active: true, sort_order: 100,
        targets: [], site_overrides: [],
    }
    cadenceInput.value = '90,30,14,0'
}

function startEdit(d: Definition) {
    isNew.value = false
    editing.value = JSON.parse(JSON.stringify(d))
    cadenceInput.value = (d.reminder_cadence_days ?? [90,30,14,0]).join(',')
}

function toggleTarget(kind: Target['kind'], value: string) {
    if (!editing.value) return
    const idx = editing.value.targets.findIndex(t => t.kind === kind && t.value === value)
    if (idx >= 0) editing.value.targets.splice(idx, 1)
    else editing.value.targets.push({ kind, value })
}

function isTargetSelected(kind: Target['kind'], value: string): boolean {
    return editing.value?.targets.some(t => t.kind === kind && t.value === value) ?? false
}

function parseCadence(): number[] {
    return cadenceInput.value
        .split(',')
        .map(s => parseInt(s.trim(), 10))
        .filter(n => !isNaN(n))
}

async function save() {
    if (!editing.value) return
    errorMessage.value = null
    const payload: any = {
        title: editing.value.title,
        credential_type: editing.value.credential_type,
        description: editing.value.description,
        requires_psv: editing.value.requires_psv,
        default_doc_required: editing.value.default_doc_required,
        reminder_cadence_days: parseCadence(),
        ceu_hours_required: editing.value.ceu_hours_required ?? 0,
        is_active: editing.value.is_active,
        sort_order: editing.value.sort_order,
        targets: editing.value.targets.map(t => ({ kind: t.kind, value: t.value })),
    }
    if (!editing.value.is_cms_mandatory) {
        payload.code = editing.value.code
    }

    try {
        if (isNew.value) {
            const { data } = await axios.post('/executive/credential-definitions', payload)
            definitions.value.push(data)
            flashSuccess(`Added "${data.title}".`)
        } else {
            const { data } = await axios.patch(`/executive/credential-definitions/${editing.value.id}`, payload)
            const idx = definitions.value.findIndex(d => d.id === data.id)
            if (idx >= 0) definitions.value[idx] = data
            flashSuccess(`Updated "${data.title}".`)
        }
        editing.value = null
    } catch (e: any) {
        errorMessage.value = e?.response?.data?.message ?? 'Could not save.'
        if (e?.response?.data?.errors) {
            errorMessage.value += ' ' + Object.values(e.response.data.errors).flat().join(' ')
        }
    }
}

async function remove(d: Definition) {
    if (d.is_cms_mandatory) {
        alert('CMS-mandatory definitions cannot be deleted.')
        return
    }
    if (!confirm(`Delete "${d.title}"? This cannot be undone.`)) return
    try {
        await axios.delete(`/executive/credential-definitions/${d.id}`)
        definitions.value = definitions.value.filter(x => x.id !== d.id)
        flashSuccess(`Deleted "${d.title}".`)
    } catch (e: any) {
        errorMessage.value = e?.response?.data?.message ?? 'Could not delete.'
    }
}

async function toggleSiteOverride(d: Definition, siteId: number) {
    if (d.is_cms_mandatory) return
    const existing = d.site_overrides.find(o => o.site_id === siteId)
    try {
        if (existing) {
            await axios.delete(`/executive/credential-definitions/${d.id}/site-overrides/${siteId}`)
            d.site_overrides = d.site_overrides.filter(o => o.site_id !== siteId)
            flashSuccess('Site override removed.')
        } else {
            const { data } = await axios.post(`/executive/credential-definitions/${d.id}/site-overrides`, { site_id: siteId })
            d.site_overrides.push({
                site_id: data.site_id,
                site_name: data.site?.name ?? sites.value.find(s => s.id === siteId)?.name ?? `Site ${siteId}`,
                action: data.action,
            })
            flashSuccess('Site override added : credential is disabled at this site.')
        }
    } catch (e: any) {
        errorMessage.value = e?.response?.data?.message ?? 'Could not toggle override.'
    }
}

function flashSuccess(msg: string) {
    successMessage.value = msg
    setTimeout(() => successMessage.value = null, 3500)
}

function deptLabel(code: string): string {
    return code.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function designationLabel(code: string): string {
    return code.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function targetSummary(d: Definition): string {
    if (d.targets.length === 0) return 'No targets : nobody is required to hold this'
    const counts: Record<string, number> = {}
    d.targets.forEach(t => counts[t.kind] = (counts[t.kind] ?? 0) + 1)
    return Object.entries(counts)
        .map(([k, c]) => `${c} ${k.replace('_', ' ')}${c > 1 ? 's' : ''}`)
        .join(' + ')
}

onMounted(load)
</script>

<template>
    <AppShell>
        <Head title="Credentials Catalog" />

        <div class="max-w-6xl mx-auto px-6 py-8">
            <div class="flex items-start justify-between mb-6 flex-wrap gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100 flex items-center gap-2">
                        <AcademicCapIcon class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                        Credentials Catalog
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                        The credentials your org tracks for staff. CMS-mandatory rows are locked but their targeting and reminder cadence can be tuned.
                    </p>
                </div>
                <button
                    @click="startNew"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium"
                >
                    <PlusIcon class="w-4 h-4" /> Add credential
                </button>
            </div>

            <OrgSettingsTabBar active="credentials" />

            <!-- Help banner -->
            <div class="rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/30 px-5 py-4 mb-6 flex items-start gap-3 text-sm">
                <InformationCircleIcon class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div class="text-blue-900 dark:text-blue-100 space-y-1">
                    <p><strong>How targeting works:</strong> A user is required to hold a credential if ANY of their attributes (department, job title, or designation) matches ANY targeting rule. OR semantics across all three columns.</p>
                    <p><strong>CMS-mandatory rows</strong> are seeded from §460.71, §460.74, §164.530(b) etc. and cannot be removed. You can edit their description, cadence, and targeting.</p>
                </div>
            </div>

            <div v-if="successMessage" class="rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/40 text-emerald-800 dark:text-emerald-200 px-4 py-2 mb-4 text-sm flex items-center gap-2">
                <CheckIcon class="w-4 h-4" /> {{ successMessage }}
            </div>
            <div v-if="errorMessage" class="rounded-lg bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/40 text-rose-800 dark:text-rose-200 px-4 py-2 mb-4 text-sm">
                {{ errorMessage }}
            </div>

            <!-- Definition list -->
            <div v-if="loading" class="text-center py-12 text-gray-500 dark:text-slate-400">Loading...</div>
            <div v-else class="space-y-3">
                <div v-for="d in sortedDefs" :key="d.id"
                     class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <h3 class="font-semibold text-gray-900 dark:text-slate-100">{{ d.title }}</h3>
                                <span v-if="d.is_cms_mandatory" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">
                                    <LockClosedIcon class="w-3 h-3" /> CMS-mandatory
                                </span>
                                <span class="px-2 py-0.5 rounded-full text-xs bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300">
                                    {{ d.credential_type_label }}
                                </span>
                                <span v-if="d.requires_psv" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">
                                    <ShieldCheckIcon class="w-3 h-3" /> Requires PSV
                                </span>
                                <span v-if="d.default_doc_required" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300">
                                    <DocumentArrowUpIcon class="w-3 h-3" /> Doc required
                                </span>
                                <span v-if="!d.is_active" class="px-2 py-0.5 rounded-full text-xs bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400">Inactive</span>
                            </div>
                            <p v-if="d.description" class="text-xs text-gray-600 dark:text-slate-400 mb-1">{{ d.description }}</p>
                            <p class="text-xs text-gray-500 dark:text-slate-500">
                                <span class="font-mono">{{ d.code }}</span> ·
                                Targets: {{ targetSummary(d) }} ·
                                Cadence: {{ (d.reminder_cadence_days ?? []).join(', ') }} days before
                            </p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button @click="startEdit(d)"
                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium shadow-sm">
                                <PencilSquareIcon class="w-4 h-4" /> Edit
                            </button>
                            <button v-if="!d.is_cms_mandatory" @click="remove(d)"
                                class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-rose-300 dark:border-rose-700 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40" title="Delete">
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                    <!-- Site overrides row (collapsible-ish; always visible if any) -->
                    <div v-if="!d.is_cms_mandatory && sites.length > 0" class="mt-2 pt-2 border-t border-gray-100 dark:border-slate-800">
                        <div class="text-xs text-gray-500 dark:text-slate-400 mb-1.5">Per-site overrides:</div>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="s in sites" :key="s.id"
                                @click="toggleSiteOverride(d, s.id)"
                                :class="[
                                    'px-2.5 py-1 rounded text-xs transition-colors',
                                    d.site_overrides.some(o => o.site_id === s.id)
                                        ? 'bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 line-through'
                                        : 'bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700'
                                ]"
                                :title="d.site_overrides.some(o => o.site_id === s.id) ? 'Currently disabled here. Click to re-enable.' : 'Click to disable for this site.'"
                            >
                                {{ s.name }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit / Add modal -->
            <div v-if="editing" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4 overflow-y-auto" @click.self="editing = null">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-200 dark:border-slate-700 max-w-3xl w-full p-6 my-8 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100">
                            {{ isNew ? 'Add credential definition' : (editing.is_cms_mandatory ? 'Edit (CMS-mandatory : code/type locked)' : 'Edit credential definition') }}
                        </h2>
                        <button @click="editing = null" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-200"><XMarkIcon class="w-5 h-5" /></button>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Title</span>
                            <input v-model="editing.title" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Code</span>
                            <input v-model="editing.code" :disabled="editing.is_cms_mandatory" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm font-mono disabled:bg-gray-100 dark:disabled:bg-slate-800/50 disabled:text-gray-500" placeholder="e.g. dea_registration" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Type</span>
                            <select v-model="editing.credential_type" :disabled="editing.is_cms_mandatory" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm disabled:bg-gray-100 dark:disabled:bg-slate-800/50 disabled:text-gray-500">
                                <option v-for="(label, code) in credentialTypes" :key="code" :value="code">{{ label }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Sort order</span>
                            <input v-model.number="editing.sort_order" type="number" min="0" max="9999" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" />
                        </label>
                    </div>
                    <label class="block mb-4">
                        <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Description</span>
                        <textarea v-model="editing.description" rows="2" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm"></textarea>
                    </label>
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <label class="flex items-center gap-2">
                            <input v-model="editing.requires_psv" type="checkbox" class="rounded text-indigo-600" />
                            <span class="text-sm text-gray-700 dark:text-slate-300">Requires PSV (primary source verification)</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input v-model="editing.default_doc_required" type="checkbox" class="rounded text-indigo-600" />
                            <span class="text-sm text-gray-700 dark:text-slate-300">Document upload required by default</span>
                        </label>
                        <label class="flex items-center gap-2 col-span-2">
                            <input v-model="editing.is_active" type="checkbox" class="rounded text-indigo-600" />
                            <span class="text-sm text-gray-700 dark:text-slate-300">Active (untick to retire without deleting)</span>
                        </label>
                    </div>
                    <div class="block mb-4">
                        <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Reminder cadence : when reminders fire and who gets them</span>
                        <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 mb-2">
                            Toggle each step on or off. Recipients escalate as the deadline approaches : staff only at the early steps, supervisor added at 14d, QA Compliance + dept alert at day-of and overdue.
                        </p>
                        <div class="space-y-1.5 rounded-lg border border-gray-200 dark:border-slate-700 p-2 max-h-80 overflow-y-auto">
                            <!-- Standard steps -->
                            <label v-for="step in STANDARD_CADENCE_STEPS" :key="`std-${step.days}`"
                                   class="flex items-start gap-3 px-2 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-slate-800 cursor-pointer">
                                <input type="checkbox" :checked="isCadenceStepActive(step.days)" @change="toggleCadenceStep(step.days)"
                                    class="rounded text-indigo-600 mt-0.5 shrink-0" />
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-xs font-semibold text-gray-900 dark:text-slate-100">{{ step.label }}</span>
                                        <span v-if="step.days <= 0" class="px-1.5 py-0.5 rounded text-[10px] bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">{{ step.days === 0 ? 'expiry' : 'overdue' }}</span>
                                    </div>
                                    <p class="text-[11px] text-gray-500 dark:text-slate-400 mt-0.5">{{ step.recipients }}</p>
                                </div>
                            </label>

                            <!-- Custom steps : separator + rendered inline so they look identical to standards -->
                            <div v-if="customCadenceSteps.length > 0" class="border-t border-gray-200 dark:border-slate-700 pt-1.5 mt-1.5">
                                <p class="text-[10px] uppercase tracking-wide font-semibold text-indigo-600 dark:text-indigo-400 px-2 mb-1">Custom steps</p>
                                <label v-for="step in customCadenceSteps" :key="`custom-${step.days}`"
                                       class="flex items-start gap-3 px-2 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-slate-800 cursor-pointer">
                                    <input type="checkbox" :checked="isCadenceStepActive(step.days)" @change="toggleCadenceStep(step.days)"
                                        class="rounded text-indigo-600 mt-0.5 shrink-0" />
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-xs font-semibold text-gray-900 dark:text-slate-100">{{ step.label }}</span>
                                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300">custom</span>
                                            <span v-if="step.days <= 0" class="px-1.5 py-0.5 rounded text-[10px] bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">{{ step.days === 0 ? 'expiry' : 'overdue' }}</span>
                                        </div>
                                        <p class="text-[11px] text-gray-500 dark:text-slate-400 mt-0.5">{{ step.recipients }}</p>
                                    </div>
                                    <button type="button" @click.stop="toggleCadenceStep(step.days)" class="text-rose-400 hover:text-rose-600 shrink-0" title="Remove this custom step">
                                        <XMarkIcon class="w-4 h-4" />
                                    </button>
                                </label>
                            </div>
                        </div>
                        <div class="mt-2">
                            <button @click="openCustomStepModal" type="button"
                                class="w-full px-3 py-2 rounded-lg border border-dashed border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-xs font-medium inline-flex items-center justify-center gap-1.5">
                                <PlusIcon class="w-3.5 h-3.5" />
                                Add custom step
                            </button>
                        </div>
                    </div>
                    <label class="block mb-4">
                        <span class="text-xs font-medium text-gray-700 dark:text-slate-300">CEU hours required per renewal cycle (0 = no CEU tracking)</span>
                        <input v-model.number="editing.ceu_hours_required" type="number" min="0" max="999" class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" />
                        <p class="text-xs text-gray-500 dark:text-slate-400 mt-1">When set, the credential row on a user's page shows CEU progress (sum of training records linked to it). Typical values: RN 30, MD 50, RD 75 over 5y, MSW 30-40.</p>
                    </label>

                    <!-- Targeting -->
                    <div class="border-t border-gray-200 dark:border-slate-700 pt-4 mb-4">
                        <h3 class="text-sm font-bold text-gray-900 dark:text-slate-100 mb-2">Targeting (OR semantics across all 3 columns)</h3>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <h4 class="text-xs font-medium text-gray-700 dark:text-slate-300 mb-2 flex items-center gap-1">
                                    <BuildingOfficeIcon class="w-3.5 h-3.5" /> Departments
                                </h4>
                                <div class="space-y-1 max-h-72 overflow-y-auto pr-2">
                                    <label v-for="d in departments" :key="d" class="flex items-center gap-2 text-xs">
                                        <input type="checkbox" :checked="isTargetSelected('department', d)" @change="toggleTarget('department', d)" class="rounded text-indigo-600" />
                                        <span class="text-gray-700 dark:text-slate-300">{{ deptLabel(d) }}</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <h4 class="text-xs font-medium text-gray-700 dark:text-slate-300 mb-2 flex items-center gap-1">
                                    <BriefcaseIcon class="w-3.5 h-3.5" /> Job Titles
                                </h4>
                                <div class="space-y-1 max-h-72 overflow-y-auto pr-2">
                                    <label v-for="jt in jobTitles" :key="jt.code" class="flex items-center gap-2 text-xs">
                                        <input type="checkbox" :checked="isTargetSelected('job_title', jt.code)" @change="toggleTarget('job_title', jt.code)" class="rounded text-indigo-600" />
                                        <span class="text-gray-700 dark:text-slate-300">{{ jt.label }}</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <h4 class="text-xs font-medium text-gray-700 dark:text-slate-300 mb-2 flex items-center gap-1">
                                    <IdentificationIcon class="w-3.5 h-3.5" /> Designations
                                </h4>
                                <div class="space-y-1 max-h-72 overflow-y-auto pr-2">
                                    <label v-for="g in designations" :key="g" class="flex items-center gap-2 text-xs">
                                        <input type="checkbox" :checked="isTargetSelected('designation', g)" @change="toggleTarget('designation', g)" class="rounded text-indigo-600" />
                                        <span class="text-gray-700 dark:text-slate-300">{{ designationLabel(g) }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-4 border-t border-gray-200 dark:border-slate-700">
                        <button @click="editing = null" class="px-4 py-2 rounded-lg text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800">Cancel</button>
                        <button @click="save" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium">Save</button>
                    </div>
                </div>
            </div>

            <!-- Custom cadence step modal (nested inside catalog modal) -->
            <div v-if="showCustomStepModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4" @click.self="showCustomStepModal = false">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-200 dark:border-slate-700 max-w-md w-full p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-bold text-gray-900 dark:text-slate-100">Add custom cadence step</h3>
                        <button @click="showCustomStepModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-200"><XMarkIcon class="w-5 h-5" /></button>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-slate-400 mb-3">
                        Use this for non-standard cycles (e.g. a state-specific 45-day pre-expiry window). Positive = before expiration ; 0 = day of ; negative = days overdue.
                    </p>
                    <label class="block">
                        <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Days relative to expiration (-30 to 365)</span>
                        <input v-model.number="customStepDays" type="number" min="-30" max="365" placeholder="e.g. 45"
                            class="w-full mt-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm font-mono" autofocus />
                    </label>

                    <div v-if="customStepPreview" class="mt-3 rounded-lg border border-indigo-200 dark:border-indigo-900/40 bg-indigo-50 dark:bg-indigo-950/30 p-3">
                        <p class="text-xs font-semibold text-indigo-900 dark:text-indigo-200">{{ customStepPreview.label }}</p>
                        <p class="text-[11px] text-indigo-700 dark:text-indigo-300 mt-0.5">
                            <strong>Recipients:</strong> {{ customStepPreview.recipients }}
                        </p>
                        <p class="text-[10px] text-indigo-600 dark:text-indigo-400 mt-1.5 italic">
                            Recipients are auto-derived from the day value to match the layered escalation rules. Will be added to the cadence list and pre-checked.
                        </p>
                    </div>
                    <div v-else-if="customStepDays !== ''" class="mt-3 text-xs text-rose-600 dark:text-rose-400">
                        Value must be between -30 and 365.
                    </div>

                    <div class="flex justify-end gap-2 mt-5">
                        <button @click="showCustomStepModal = false" class="px-4 py-2 rounded-lg text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800">Cancel</button>
                        <button @click="submitCustomStep" :disabled="!customStepPreview" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed">Add step</button>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
