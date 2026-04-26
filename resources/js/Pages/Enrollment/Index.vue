<script setup lang="ts">
// ─── Enrollment/Index ───────────────────────────────────────────────────────
// Kanban board for the PACE enrollment pipeline: every prospect moves through
// columns from referral -> intake -> eligibility -> enrolled (or
// declined / withdrawn). One card == one Referral record.
//
// Audience: Enrollment department + Marketing.
//
// Notable rules:
//   - PACE language: pre-enrollment people are "Potential Enrollees" (not
//     "Participants" — that term is reserved for fully enrolled members).
//   - 42 CFR §460.150-158 govern enrollment determination + effective dates.
//   - State machine is enforced server-side; the UI hides invalid transitions.
// ────────────────────────────────────────────────────────────────────────────

import { ref, reactive } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { PlusIcon, XMarkIcon, UserIcon, ChatBubbleLeftEllipsisIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Referral {
    id: number
    referred_by_name: string
    prospective_first_name: string | null
    prospective_last_name: string | null
    prospective_dob: string | null
    referral_source: string
    referral_date: string | null
    status: string
    priority: string | null
    notes: string | null
    notes_count?: number  // from withCount('notes') on backend
    assigned_to: { id: number; first_name: string; last_name: string } | null
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null
    created_by: { id: number; first_name: string; last_name: string } | null
}

interface SiteOption { id: number; name: string }

const props = defineProps<{
    pipeline: Record<string, Referral[]>
    statuses: Record<string, string>
    sources: Record<string, string>
    pipelineOrder: string[]
    sites: SiteOption[]
    defaultSiteId: number | null
}>()

// Helper — potential enrollee's display name, with fallback.
// Per NPA / 42 CFR §460.154, pre-enrollment individuals are "potential enrollees".
// Internal DB columns use the shorter `prospective_*` prefix for brevity.
function potentialEnrolleeName(r: Referral): string {
    const full = `${r.prospective_first_name ?? ''} ${r.prospective_last_name ?? ''}`.trim()
    if (full) return full
    if (r.participant) return `${r.participant.first_name} ${r.participant.last_name}`
    return 'Name pending'
}

// ── Column header color map ────────────────────────────────────────────────────

const COLUMN_COLORS: Record<string, string> = {
    new: 'bg-slate-100 dark:bg-slate-700/50 text-slate-700 dark:text-slate-300',
    intake_scheduled: 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
    intake_in_progress: 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
    intake_complete: 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
    eligibility_pending: 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
    pending_enrollment: 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
    enrolled: 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
    declined: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
    withdrawn: 'bg-gray-100 dark:bg-slate-600/50 text-gray-600 dark:text-slate-400',
}

// ── Total count ────────────────────────────────────────────────────────────────

function totalCount(): number {
    return Object.values(props.pipeline).reduce((sum, arr) => sum + arr.length, 0)
}

function columnReferrals(status: string): Referral[] {
    return props.pipeline[status] ?? []
}

// ── Date formatting ────────────────────────────────────────────────────────────

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

// ── Modal state ────────────────────────────────────────────────────────────────

const showModal = ref(false)
const submitting = ref(false)
const formErrors = ref<Record<string, string | string[]>>({})
const formGeneralError = ref('')

const form = reactive({
    site_id: '' as string | number,
    prospective_first_name: '',
    prospective_last_name: '',
    prospective_dob: '',
    referred_by_name: '',
    referred_by_org: '',
    referral_source: '',
    referral_date: new Date().toISOString().slice(0, 10),  // default to today
    priority: 'routine',
    notes: '',
})

function openModal() {
    form.site_id = props.defaultSiteId ?? (props.sites[0]?.id ?? '')
    form.prospective_first_name = ''
    form.prospective_last_name = ''
    form.prospective_dob = ''
    form.referred_by_name = ''
    form.referred_by_org = ''
    form.referral_source = ''
    form.referral_date = new Date().toISOString().slice(0, 10)
    form.priority = 'routine'
    form.notes = ''
    formErrors.value = {}
    formGeneralError.value = ''
    showModal.value = true
}

function closeModal() {
    showModal.value = false
}

function submitReferral() {
    submitting.value = true
    formErrors.value = {}
    formGeneralError.value = ''
    axios
        .post('/enrollment/referrals', { ...form })
        .then(() => {
            closeModal()
            router.reload()
        })
        .catch((err) => {
            const status = err.response?.status
            if (status === 422) {
                formErrors.value = err.response.data.errors ?? {}
                formGeneralError.value = 'Please fix the highlighted fields.'
            } else {
                formGeneralError.value = status
                    ? `Save failed (HTTP ${status}): ${err.response?.data?.message ?? err.message}`
                    : `Save failed: ${err.message}`
            }
        })
        .finally(() => {
            submitting.value = false
        })
}
</script>

<template>
    <Head title="Enrollment Pipeline" />

    <AppShell>
        <template #header>
            <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                Enrollment Pipeline
            </h1>
        </template>

        <div class="px-6 py-5">
            <!-- ── Page header ── -->
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-500 dark:text-slate-400">
                        {{ totalCount().toLocaleString() }}
                        referral{{ totalCount() !== 1 ? 's' : '' }} total
                    </span>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
                    @click="openModal"
                >
                    <PlusIcon class="w-4 h-4" aria-hidden="true" />
                    New Referral
                </button>
            </div>

            <!-- ── Kanban board ── -->
            <div class="overflow-x-auto pb-4">
                <div class="flex gap-4" style="min-width: max-content;">
                    <div
                        v-for="statusKey in pipelineOrder"
                        :key="statusKey"
                        class="w-64 shrink-0 flex flex-col"
                    >
                        <!-- Column header -->
                        <div
                            :class="[
                                'flex items-center justify-between px-3 py-2 rounded-t-lg border border-b-0 border-gray-200 dark:border-slate-700',
                                COLUMN_COLORS[statusKey] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300',
                            ]"
                        >
                            <span class="text-xs font-semibold uppercase tracking-wide truncate">
                                {{ statuses[statusKey] ?? statusKey }}
                            </span>
                            <span class="ml-2 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-white/60 dark:bg-black/20 text-xs font-bold">
                                {{ columnReferrals(statusKey).length }}
                            </span>
                        </div>

                        <!-- Card list -->
                        <div class="flex-1 border border-gray-200 dark:border-slate-700 rounded-b-lg bg-gray-50 dark:bg-slate-800/50 overflow-y-auto max-h-[calc(100vh-220px)] divide-y divide-gray-100 dark:divide-slate-700">
                            <!-- Empty state -->
                            <div
                                v-if="columnReferrals(statusKey).length === 0"
                                class="px-3 py-6 text-center text-xs text-gray-400 dark:text-slate-500"
                            >
                                No referrals
                            </div>

                            <!-- Cards -->
                            <div
                                v-for="referral in columnReferrals(statusKey)"
                                :key="referral.id"
                                class="p-3 bg-white dark:bg-slate-800 hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors"
                                tabindex="0"
                                :aria-label="`Open referral for ${potentialEnrolleeName(referral)}`"
                                @click="router.visit('/enrollment/referrals/' + referral.id)"
                                @keydown.enter="router.visit('/enrollment/referrals/' + referral.id)"
                            >
                                <!-- Potential enrollee name + priority badge -->
                                <div class="flex items-start justify-between gap-1 mb-1">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-slate-100 leading-tight truncate">
                                        {{ potentialEnrolleeName(referral) }}
                                    </span>
                                    <span
                                        v-if="referral.priority === 'urgent'"
                                        class="inline-flex px-1.5 py-0.5 rounded text-xs font-bold bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 shrink-0"
                                    >
                                        Urgent
                                    </span>
                                    <span
                                        v-else-if="referral.priority === 'routine'"
                                        class="inline-flex px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 shrink-0"
                                    >
                                        Routine
                                    </span>
                                </div>

                                <!-- Referrer + source -->
                                <p class="text-xs text-gray-500 dark:text-slate-400 mb-1 truncate">
                                    via {{ referral.referred_by_name }}
                                </p>
                                <p class="text-xs text-gray-400 dark:text-slate-500 mb-1 truncate">
                                    {{ sources[referral.referral_source] ?? referral.referral_source }}
                                </p>

                                <!-- Referral date -->
                                <p class="text-xs text-gray-400 dark:text-slate-500 mb-1">
                                    {{ fmtDate(referral.referral_date) }}
                                </p>

                                <!-- Footer row: assigned-to + note-count chip -->
                                <div class="flex items-center justify-between gap-2 mt-1.5">
                                    <div
                                        v-if="referral.assigned_to"
                                        class="flex items-center gap-1 min-w-0"
                                    >
                                        <UserIcon class="w-3.5 h-3.5 text-gray-400 dark:text-slate-500 shrink-0" aria-hidden="true" />
                                        <span class="text-xs text-gray-500 dark:text-slate-400 truncate">
                                            {{ referral.assigned_to.first_name }} {{ referral.assigned_to.last_name }}
                                        </span>
                                    </div>
                                    <span v-else></span>
                                    <span
                                        v-if="(referral.notes_count ?? 0) > 0"
                                        class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-100 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300 shrink-0"
                                        :title="`${referral.notes_count} note${referral.notes_count === 1 ? '' : 's'}`"
                                    >
                                        <ChatBubbleLeftEllipsisIcon class="w-3 h-3" />
                                        {{ referral.notes_count }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── New Referral Modal ── -->
        <Teleport to="body">
            <div
                v-if="showModal"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="modal-title"
            >
                <!-- Backdrop -->
                <div
                    class="absolute inset-0 bg-black/50"
                    @click="closeModal"
                />

                <!-- Panel -->
                <div class="relative w-full max-w-md bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-gray-200 dark:border-slate-700 z-10">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 dark:border-slate-700">
                        <h2
                            id="modal-title"
                            class="text-sm font-semibold text-gray-800 dark:text-slate-100"
                        >
                            New Referral
                        </h2>
                        <button
                            type="button"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 transition-colors"
                            aria-label="Close modal"
                            @click="closeModal"
                        >
                            <XMarkIcon class="w-5 h-5" aria-hidden="true" />
                        </button>
                    </div>

                    <!-- Form -->
                    <form class="px-5 py-4 space-y-4 max-h-[75vh] overflow-y-auto" @submit.prevent="submitReferral">

                        <!-- General error banner -->
                        <div
                            v-if="formGeneralError"
                            class="rounded-lg border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/60 px-3 py-2 text-sm text-red-700 dark:text-red-300"
                        >
                            {{ formGeneralError }}
                        </div>

                        <!-- ── Potential Enrollee (NPA / 42 CFR §460.154 term) ── -->
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-2">Potential Enrollee</p>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label for="prospective_first_name" class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">First Name <span class="text-red-500">*</span></label>
                                    <input
                                        id="prospective_first_name"
                                        v-model="form.prospective_first_name"
                                        type="text"
                                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100"
                                    />
                                    <p v-if="formErrors.prospective_first_name" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                        {{ Array.isArray(formErrors.prospective_first_name) ? formErrors.prospective_first_name[0] : formErrors.prospective_first_name }}
                                    </p>
                                </div>
                                <div>
                                    <label for="prospective_last_name" class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Last Name <span class="text-red-500">*</span></label>
                                    <input
                                        id="prospective_last_name"
                                        v-model="form.prospective_last_name"
                                        type="text"
                                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100"
                                    />
                                    <p v-if="formErrors.prospective_last_name" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                        {{ Array.isArray(formErrors.prospective_last_name) ? formErrors.prospective_last_name[0] : formErrors.prospective_last_name }}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-2">
                                <label for="prospective_dob" class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
                                    Date of Birth <span class="text-gray-400 dark:text-slate-500 font-normal">(if known)</span>
                                </label>
                                <input
                                    id="prospective_dob"
                                    v-model="form.prospective_dob"
                                    type="date"
                                    class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100"
                                />
                                <p v-if="formErrors.prospective_dob" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                    {{ Array.isArray(formErrors.prospective_dob) ? formErrors.prospective_dob[0] : formErrors.prospective_dob }}
                                </p>
                            </div>
                        </div>

                        <!-- Site -->
                        <div>
                            <label for="site_id" class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Site <span class="text-red-500">*</span></label>
                            <select
                                id="site_id"
                                v-model="form.site_id"
                                class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                            >
                                <option value="">Select a site</option>
                                <option v-for="s in props.sites" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <p v-if="formErrors.site_id" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                {{ Array.isArray(formErrors.site_id) ? formErrors.site_id[0] : formErrors.site_id }}
                            </p>
                        </div>

                        <!-- ── Referrer info ── -->
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-2">Referred By</p>
                            <div class="space-y-2">
                                <div>
                                    <label for="referred_by_name" class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Referrer Name <span class="text-red-500">*</span></label>
                                    <input
                                        id="referred_by_name"
                                        v-model="form.referred_by_name"
                                        type="text"
                                        placeholder="Referring party (hospital SW, family member, self, etc.)"
                                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400"
                                    />
                                    <p v-if="formErrors.referred_by_name" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                        {{ Array.isArray(formErrors.referred_by_name) ? formErrors.referred_by_name[0] : formErrors.referred_by_name }}
                                    </p>
                                </div>
                                <div>
                                    <label for="referred_by_org" class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
                                        Organization <span class="text-gray-400 dark:text-slate-500 font-normal">(optional)</span>
                                    </label>
                                    <input
                                        id="referred_by_org"
                                        v-model="form.referred_by_org"
                                        type="text"
                                        placeholder="Hospital, clinic, agency, etc."
                                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Referral source -->
                        <div>
                            <label for="referral_source" class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Source <span class="text-red-500">*</span></label>
                            <select
                                id="referral_source"
                                v-model="form.referral_source"
                                class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                            >
                                <option value="">Select a source</option>
                                <option v-for="(label, key) in sources" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <p v-if="formErrors.referral_source" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                {{ Array.isArray(formErrors.referral_source) ? formErrors.referral_source[0] : formErrors.referral_source }}
                            </p>
                        </div>

                        <!-- Date + priority row -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="referral_date" class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Referral Date <span class="text-red-500">*</span></label>
                                <input
                                    id="referral_date"
                                    v-model="form.referral_date"
                                    type="date"
                                    class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100"
                                />
                                <p v-if="formErrors.referral_date" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                    {{ Array.isArray(formErrors.referral_date) ? formErrors.referral_date[0] : formErrors.referral_date }}
                                </p>
                            </div>
                            <div>
                                <label for="priority" class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Priority</label>
                                <select
                                    id="priority"
                                    v-model="form.priority"
                                    class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
                                >
                                    <option value="routine">Routine</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>

                        <!-- Initial notes -->
                        <div>
                            <label for="notes" class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
                                Initial Notes <span class="text-gray-400 dark:text-slate-500 font-normal">(optional)</span>
                            </label>
                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="3"
                                placeholder="Context from the referrer (e.g. discharge summary, reason for referral)"
                                class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400 resize-none"
                            />
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center justify-end gap-3 pt-1">
                            <button
                                type="button"
                                class="px-4 py-2 text-sm text-gray-600 dark:text-slate-300 hover:text-gray-800 dark:hover:text-slate-100 transition-colors"
                                @click="closeModal"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="submitting"
                                class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            >
                                {{ submitting ? 'Saving...' : 'Create Referral' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppShell>
</template>
