<script setup lang="ts">
// ─── Enrollment/Show.vue ──────────────────────────────────────────────────────
// Referral detail page. Shows the full referral record and provides status
// transition controls matching the CMS enrollment state machine.
//
// Valid forward path: new → intake_scheduled → intake_in_progress →
//   intake_complete → eligibility_pending → pending_enrollment → enrolled
// Any non-terminal state can also exit → declined / withdrawn
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ArrowLeftIcon, UserIcon, CheckCircleIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'

// ── Types ──────────────────────────────────────────────────────────────────────

interface ReferralData {
    id: number
    referred_by_name: string
    referral_source: string
    source_label: string
    referral_date: string | null
    status: string
    status_label: string
    priority: string | null
    notes: string | null
    decline_reason: string | null
    withdrawn_reason: string | null
    assigned_to: { id: number; first_name: string; last_name: string } | null
    created_by: { id: number; first_name: string; last_name: string } | null
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null
    site: { id: number; name: string } | null
    created_at: string | null
    updated_at: string | null
}

const props = defineProps<{
    referral: ReferralData
    validTransitions: string[]
    statusLabels: Record<string, string>
    pipelineSteps: string[]
}>()

// ── Status stepper ─────────────────────────────────────────────────────────────

const FORWARD_STEPS = [
    'new', 'intake_scheduled', 'intake_in_progress',
    'intake_complete', 'eligibility_pending', 'pending_enrollment', 'enrolled',
]

const currentStepIndex = computed(() =>
    FORWARD_STEPS.indexOf(props.referral.status)
)

const isTerminal = computed(() =>
    ['enrolled', 'declined', 'withdrawn'].includes(props.referral.status)
)

const isExited = computed(() =>
    ['declined', 'withdrawn'].includes(props.referral.status)
)

// ── Transition actions ─────────────────────────────────────────────────────────

const forwardTransition = computed(() =>
    props.validTransitions.find(s => !['declined', 'withdrawn'].includes(s)) ?? null
)

const canDecline   = computed(() => props.validTransitions.includes('declined'))
const canWithdraw  = computed(() => props.validTransitions.includes('withdrawn'))

// Transition state
const transitioning     = ref(false)
const transitionError   = ref('')

// Decline/Withdraw modal
const showExitModal     = ref(false)
const exitType          = ref<'declined' | 'withdrawn'>('declined')
const exitReason        = ref('')
const exitSaving        = ref(false)
const exitError         = ref('')

function openExitModal(type: 'declined' | 'withdrawn') {
    exitType.value   = type
    exitReason.value = ''
    exitError.value  = ''
    showExitModal.value = true
}

async function advanceStatus() {
    if (!forwardTransition.value) return
    transitioning.value = true; transitionError.value = ''
    try {
        await axios.post(`/enrollment/referrals/${props.referral.id}/transition`, {
            new_status: forwardTransition.value,
        })
        router.reload()
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } }
        transitionError.value = err.response?.data?.message ?? 'Transition failed.'
    } finally {
        transitioning.value = false
    }
}

async function submitExit() {
    if (!exitReason.value.trim()) { exitError.value = 'A reason is required.'; return }
    exitSaving.value = true; exitError.value = ''
    const payload: Record<string, string> = { new_status: exitType.value }
    if (exitType.value === 'declined')  payload.decline_reason   = exitReason.value
    if (exitType.value === 'withdrawn') payload.withdrawn_reason = exitReason.value
    try {
        await axios.post(`/enrollment/referrals/${props.referral.id}/transition`, payload)
        showExitModal.value = false
        router.reload()
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } }
        exitError.value = err.response?.data?.message ?? 'Could not update status.'
    } finally {
        exitSaving.value = false
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric',
    })
}

const STATUS_BADGE: Record<string, string> = {
    new:                'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    intake_scheduled:   'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
    intake_in_progress: 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
    intake_complete:    'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
    eligibility_pending:'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
    pending_enrollment: 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
    enrolled:           'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
    declined:           'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
    withdrawn:          'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
}
</script>

<template>
    <Head :title="`Referral: ${referral.referred_by_name}`" />

    <AppShell>
        <template #header>
            <div class="flex items-center gap-3">
                <Link
                    href="/enrollment/referrals"
                    class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 transition-colors"
                >
                    <ArrowLeftIcon class="w-4 h-4" />
                </Link>
                <h1 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                    {{ referral.referred_by_name }}
                </h1>
                <span :class="['inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold', STATUS_BADGE[referral.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-500']">
                    {{ referral.status_label }}
                </span>
            </div>
        </template>

        <div class="px-6 py-5 max-w-4xl space-y-6">

            <!-- ── Status progress stepper ── -->
            <div v-if="!isExited" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-6 py-5">
                <h2 class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-4">Enrollment Progress</h2>
                <div class="flex items-center gap-0 overflow-x-auto">
                    <template v-for="(step, idx) in FORWARD_STEPS" :key="step">
                        <!-- Step circle -->
                        <div class="flex flex-col items-center shrink-0">
                            <div :class="[
                                'w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold border-2 transition-colors',
                                idx < currentStepIndex
                                    ? 'bg-green-500 border-green-500 text-white'
                                    : idx === currentStepIndex
                                        ? 'bg-blue-600 border-blue-600 text-white'
                                        : 'bg-white dark:bg-slate-800 border-gray-300 dark:border-slate-600 text-gray-400 dark:text-slate-500',
                            ]">
                                <CheckCircleIcon v-if="idx < currentStepIndex" class="w-4 h-4" />
                                <span v-else>{{ idx + 1 }}</span>
                            </div>
                            <span :class="[
                                'text-xs mt-1 text-center max-w-[4.5rem] leading-tight',
                                idx === currentStepIndex
                                    ? 'text-blue-600 dark:text-blue-400 font-semibold'
                                    : idx < currentStepIndex
                                        ? 'text-green-600 dark:text-green-400'
                                        : 'text-gray-400 dark:text-slate-500',
                            ]">{{ statusLabels[step] ?? step }}</span>
                        </div>
                        <!-- Connector line (not after last step) -->
                        <div
                            v-if="idx < FORWARD_STEPS.length - 1"
                            :class="[
                                'h-0.5 flex-1 min-w-[1rem] mx-1 mt-[-1rem]',
                                idx < currentStepIndex ? 'bg-green-400' : 'bg-gray-200 dark:bg-slate-700',
                            ]"
                        />
                    </template>
                </div>
            </div>

            <!-- Exited banner -->
            <div v-if="isExited" :class="[
                'rounded-xl border px-5 py-4 flex items-center gap-3',
                referral.status === 'declined'
                    ? 'bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800'
                    : 'bg-gray-50 dark:bg-slate-700/50 border-gray-200 dark:border-slate-700',
            ]">
                <XCircleIcon :class="['w-6 h-6 shrink-0', referral.status === 'declined' ? 'text-red-500' : 'text-gray-400']" />
                <div>
                    <p :class="['text-sm font-semibold', referral.status === 'declined' ? 'text-red-700 dark:text-red-300' : 'text-gray-700 dark:text-slate-300']">
                        Referral {{ referral.status_label }}
                    </p>
                    <p v-if="referral.decline_reason" class="text-xs text-red-600 dark:text-red-400 mt-0.5">Reason: {{ referral.decline_reason }}</p>
                    <p v-if="referral.withdrawn_reason" class="text-xs text-gray-600 dark:text-slate-400 mt-0.5">Reason: {{ referral.withdrawn_reason }}</p>
                </div>
            </div>

            <!-- ── Action buttons ── -->
            <div v-if="!isTerminal" class="flex flex-wrap items-center gap-3">
                <!-- Advance to next status -->
                <button
                    v-if="forwardTransition"
                    :disabled="transitioning"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
                    @click="advanceStatus"
                >
                    <CheckCircleIcon class="w-4 h-4" />
                    {{ transitioning ? 'Updating...' : 'Advance to: ' + (statusLabels[forwardTransition] ?? forwardTransition) }}
                </button>

                <!-- Decline -->
                <button
                    v-if="canDecline"
                    class="inline-flex items-center gap-2 px-4 py-2 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 text-sm rounded-lg hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors"
                    @click="openExitModal('declined')"
                >
                    <XCircleIcon class="w-4 h-4" />
                    Decline
                </button>

                <!-- Withdraw -->
                <button
                    v-if="canWithdraw"
                    class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 text-sm rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                    @click="openExitModal('withdrawn')"
                >
                    Withdrawn
                </button>

                <p v-if="transitionError" class="text-xs text-red-600 dark:text-red-400">{{ transitionError }}</p>
            </div>

            <!-- ── Linked participant ── -->
            <div v-if="referral.participant" class="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 rounded-xl px-5 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <UserIcon class="w-5 h-5 text-blue-500 shrink-0" />
                    <div>
                        <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">Participant Record Created</p>
                        <p class="text-xs text-blue-600 dark:text-blue-400 mt-0.5">
                            {{ referral.participant.first_name }} {{ referral.participant.last_name }}
                            &middot; <span class="font-mono">{{ referral.participant.mrn }}</span>
                        </p>
                    </div>
                </div>
                <Link
                    :href="`/participants/${referral.participant.id}`"
                    class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                >
                    View Profile
                </Link>
            </div>

            <!-- ── Referral details ── -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 divide-y divide-gray-100 dark:divide-slate-700">
                <div class="px-5 py-3">
                    <h2 class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">Referral Details</h2>
                </div>

                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-0 divide-y divide-gray-100 dark:divide-slate-700">
                    <div class="px-5 py-3 col-span-2 sm:col-span-1">
                        <dt class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Referred By</dt>
                        <dd class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ referral.referred_by_name }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Source</dt>
                        <dd class="text-sm text-gray-900 dark:text-slate-100">{{ referral.source_label }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Referral Date</dt>
                        <dd class="text-sm text-gray-900 dark:text-slate-100">{{ fmtDate(referral.referral_date) }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Priority</dt>
                        <dd class="text-sm text-gray-900 dark:text-slate-100 capitalize">{{ referral.priority ?? '-' }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Site</dt>
                        <dd class="text-sm text-gray-900 dark:text-slate-100">{{ referral.site?.name ?? '-' }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Assigned To</dt>
                        <dd class="text-sm text-gray-900 dark:text-slate-100">
                            {{ referral.assigned_to ? referral.assigned_to.first_name + ' ' + referral.assigned_to.last_name : '-' }}
                        </dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Created By</dt>
                        <dd class="text-sm text-gray-900 dark:text-slate-100">
                            {{ referral.created_by ? referral.created_by.first_name + ' ' + referral.created_by.last_name : '-' }}
                        </dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Created</dt>
                        <dd class="text-sm text-gray-900 dark:text-slate-100">{{ fmtDate(referral.created_at) }}</dd>
                    </div>
                    <div v-if="referral.notes" class="px-5 py-3 col-span-2 sm:col-span-3">
                        <dt class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Notes</dt>
                        <dd class="text-sm text-gray-900 dark:text-slate-100 whitespace-pre-wrap">{{ referral.notes }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- ── Decline / Withdraw modal ── -->
        <Teleport to="body">
            <div v-if="showExitModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60">
                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md p-6 space-y-4">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 capitalize">
                        {{ exitType === 'declined' ? 'Decline Referral' : 'Mark as Withdrawn' }}
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-slate-400">
                        {{ exitType === 'declined'
                            ? 'This referral will be marked as declined. Please provide a reason.'
                            : 'This referral will be marked as withdrawn by the prospective participant or family.' }}
                    </p>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">
                            Reason <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            v-model="exitReason"
                            rows="3"
                            :placeholder="exitType === 'declined' ? 'e.g. Does not meet eligibility criteria...' : 'e.g. Family decided not to proceed...'"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 resize-none"
                        />
                    </div>
                    <p v-if="exitError" class="text-xs text-red-600 dark:text-red-400">{{ exitError }}</p>
                    <div class="flex gap-2 justify-end">
                        <button
                            class="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                            @click="showExitModal = false"
                        >Cancel</button>
                        <button
                            :disabled="exitSaving"
                            :class="[
                                'text-sm px-4 py-2 rounded-lg font-medium disabled:opacity-50 transition-colors',
                                exitType === 'declined'
                                    ? 'bg-red-600 text-white hover:bg-red-700'
                                    : 'bg-gray-600 text-white hover:bg-gray-700',
                            ]"
                            @click="submitExit"
                        >{{ exitSaving ? 'Saving...' : (exitType === 'declined' ? 'Confirm Decline' : 'Confirm Withdrawal') }}</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppShell>
</template>
