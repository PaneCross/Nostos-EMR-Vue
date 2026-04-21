<script setup lang="ts">
// ─── Appeals/Show ─────────────────────────────────────────────────────────────
// Detail view for a single §460.122 appeal: metadata, clock, decision form,
// PDFs (ack + decision), event timeline.
// ─────────────────────────────────────────────────────────────────────────────

import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import axios from 'axios'
import {
    ScaleIcon, ClockIcon, DocumentTextIcon, DocumentArrowDownIcon,
    CheckCircleIcon, XCircleIcon, ExclamationTriangleIcon, ArrowLeftIcon,
} from '@heroicons/vue/24/outline'

interface Actor { id: number; first_name: string; last_name: string }
interface Event {
    id: number
    event_type: string
    from_status: string | null
    to_status: string | null
    narrative: string | null
    occurred_at: string
    actor: Actor | null
}
interface Pdf { id: number; file_name: string; file_path: string }

interface Appeal {
    id: number
    type: 'standard' | 'expedited'
    status: string
    filed_at: string
    internal_decision_due_at: string
    internal_decision_at: string | null
    decision_narrative: string | null
    filing_reason: string | null
    filed_by: string
    filed_by_name: string | null
    continuation_of_benefits: boolean
    external_review_requested_at: string | null
    external_review_outcome: string | null
    external_review_outcome_at: string | null
    external_review_narrative: string | null
    participant: { id: number; mrn: string; first_name: string; last_name: string; dob: string }
    denial_notice: {
        id: number
        sdr_id: number | null
        reason_code: string
        issued_at: string
        sdr?: { id: number; request_type: string; description: string; status: string }
        pdf_document?: Pdf
    }
    acknowledgment_pdf: Pdf | null
    decision_pdf: Pdf | null
    decided_by: Actor | null
    events: Event[]
}

const props = defineProps<{ appeal: Appeal }>()

const page = usePage()
const user = computed(() => (page.props.auth as any)?.user)

const busy = ref(false)
const showDecideForm = ref(false)
const decideOutcome = ref<'decided_upheld' | 'decided_overturned' | 'decided_partially_overturned'>('decided_upheld')
const decideNarrative = ref('')

const STATUS_LABELS: Record<string, string> = {
    received: 'Received',
    acknowledged: 'Acknowledged',
    under_review: 'Under Review',
    decided_upheld: 'Decided — Upheld',
    decided_overturned: 'Decided — Overturned',
    decided_partially_overturned: 'Decided — Partially Overturned',
    withdrawn: 'Withdrawn',
    external_review_requested: 'External Review Requested',
    closed: 'Closed',
}

function fmt(d: string | null) { return d ? new Date(d).toLocaleString() : '—' }
function fmtD(d: string | null) { return d ? new Date(d).toLocaleDateString() : '—' }

const isOpen = computed(() => ['received', 'acknowledged', 'under_review', 'external_review_requested'].includes(props.appeal.status))

const windowPct = computed(() => {
    const filed = new Date(props.appeal.filed_at).getTime()
    const due = new Date(props.appeal.internal_decision_due_at).getTime()
    if (due <= filed) return 100
    return Math.max(0, Math.min(100, Math.round(((Date.now() - filed) / (due - filed)) * 100)))
})

const windowColor = computed(() => {
    if (!isOpen.value) return 'bg-slate-300 dark:bg-slate-600'
    if (windowPct.value >= 100) return 'bg-red-500'
    if (windowPct.value >= 75) return 'bg-orange-500'
    if (windowPct.value >= 50) return 'bg-amber-400'
    return 'bg-emerald-500'
})

function timeLabel(due: string): string {
    const ms = new Date(due).getTime() - Date.now()
    const hours = Math.floor(Math.abs(ms) / 3_600_000)
    const suffix = ms < 0 ? 'overdue' : 'remaining'
    if (hours >= 48) return `${Math.floor(hours / 24)}d ${hours % 24}h ${suffix}`
    if (hours >= 1) return `${hours}h ${suffix}`
    return `${Math.max(1, Math.floor(Math.abs(ms) / 60_000))}m ${suffix}`
}

async function doPost(path: string, body?: Record<string, unknown>) {
    busy.value = true
    try {
        await axios.post(path, body ?? {})
        router.reload({ only: ['appeal'] })
    } finally {
        busy.value = false
    }
}

async function acknowledge() { await doPost(`/appeals/${props.appeal.id}/acknowledge`) }
async function beginReview() { await doPost(`/appeals/${props.appeal.id}/begin-review`) }
async function requestExternal() { await doPost(`/appeals/${props.appeal.id}/request-external`) }
async function withdraw() {
    if (!confirm('Withdraw this appeal? This cannot be undone.')) return
    await doPost(`/appeals/${props.appeal.id}/withdraw`)
}
async function close() { await doPost(`/appeals/${props.appeal.id}/close`) }
async function decide() {
    if (decideNarrative.value.trim().length < 10) { alert('Please provide decision reasoning (10+ characters).'); return }
    await doPost(`/appeals/${props.appeal.id}/decide`, {
        outcome: decideOutcome.value,
        narrative: decideNarrative.value,
    })
    showDecideForm.value = false
    decideNarrative.value = ''
}

const canDecide = computed(() => {
    if (!user.value) return false
    const dept = user.value.department
    return user.value.is_super_admin
        || ['qa_compliance', 'enrollment', 'it_admin'].includes(dept)
        || (user.value.designations ?? []).some((d: string) => ['medical_director', 'compliance_officer'].includes(d))
})
</script>

<template>
    <AppShell>
        <Head :title="`APPEAL-${appeal.id}`" />

        <div class="px-6 py-6 max-w-5xl mx-auto space-y-6">
            <!-- Header -->
            <div>
                <Link href="/appeals" class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:underline mb-3">
                    <ArrowLeftIcon class="w-4 h-4" /> Back to Appeals
                </Link>
                <div class="flex items-start justify-between">
                    <div class="flex items-start gap-3">
                        <ScaleIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                        <div>
                            <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">APPEAL-{{ appeal.id }}</h1>
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                Participant appeal of service denial — 42 CFR §460.122
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <span :class="[
                            'inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold',
                            appeal.type === 'expedited'
                                ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
                                : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300',
                        ]">
                            <ClockIcon class="w-3.5 h-3.5" />
                            {{ appeal.type === 'expedited' ? 'Expedited — 72h decision' : 'Standard — 30-day decision' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Clock banner -->
            <div v-if="isOpen" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-200">Decision Window</span>
                    <span :class="[
                        'text-sm font-medium',
                        windowPct >= 100 ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-300',
                    ]">
                        Due {{ fmt(appeal.internal_decision_due_at) }} ({{ timeLabel(appeal.internal_decision_due_at) }})
                    </span>
                </div>
                <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                    <div :class="['h-full transition-all', windowColor]" :style="{ width: windowPct + '%' }" />
                </div>
            </div>

            <!-- Two-column meta grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- LEFT: appeal + participant + denial notice -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Participant -->
                    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Participant</h2>
                        <Link :href="`/participants/${appeal.participant.id}`" class="text-base font-semibold text-blue-600 dark:text-blue-400 hover:underline">
                            {{ appeal.participant.last_name }}, {{ appeal.participant.first_name }}
                        </Link>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            MRN {{ appeal.participant.mrn }} · DOB {{ fmtD(appeal.participant.dob) }}
                        </p>
                    </div>

                    <!-- Related denial -->
                    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Denial Being Appealed</h2>
                        <dl class="space-y-1 text-sm">
                            <div class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Notice ID:</dt>
                                <dd class="text-slate-800 dark:text-slate-200">DENIAL-{{ appeal.denial_notice.id }}</dd>
                            </div>
                            <div v-if="appeal.denial_notice.sdr" class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Related SDR:</dt>
                                <dd>
                                    <Link :href="`/sdrs/${appeal.denial_notice.sdr.id}`" class="text-blue-600 dark:text-blue-400 hover:underline">
                                        SDR-{{ appeal.denial_notice.sdr.id }}
                                    </Link>
                                    <span class="text-slate-500 dark:text-slate-400 ml-2">{{ appeal.denial_notice.sdr.description }}</span>
                                </dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Reason Code:</dt>
                                <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ appeal.denial_notice.reason_code }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Issued:</dt>
                                <dd class="text-slate-800 dark:text-slate-200">{{ fmt(appeal.denial_notice.issued_at) }}</dd>
                            </div>
                        </dl>
                        <a
                            v-if="appeal.denial_notice.pdf_document"
                            :href="`/denial-notices/${appeal.denial_notice.id}/download`"
                            class="mt-3 inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                        >
                            <DocumentArrowDownIcon class="w-4 h-4" /> Download Denial Notice PDF
                        </a>
                    </div>

                    <!-- Filing details -->
                    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Filing</h2>
                        <dl class="space-y-1 text-sm">
                            <div class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Filed By:</dt>
                                <dd class="text-slate-800 dark:text-slate-200 capitalize">
                                    {{ appeal.filed_by.replace(/_/g, ' ') }}
                                    <span v-if="appeal.filed_by_name" class="text-slate-500">({{ appeal.filed_by_name }})</span>
                                </dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Filed At:</dt>
                                <dd class="text-slate-800 dark:text-slate-200">{{ fmt(appeal.filed_at) }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Cont. of Benefits:</dt>
                                <dd class="text-slate-800 dark:text-slate-200">
                                    <span v-if="appeal.continuation_of_benefits" class="inline-flex items-center gap-1 text-amber-700 dark:text-amber-300">
                                        <ExclamationTriangleIcon class="w-3.5 h-3.5" /> Yes — service continues during appeal
                                    </span>
                                    <span v-else class="text-slate-500">No</span>
                                </dd>
                            </div>
                            <div v-if="appeal.filing_reason" class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Reason:</dt>
                                <dd class="text-slate-800 dark:text-slate-200">{{ appeal.filing_reason }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Decision -->
                    <div v-if="appeal.internal_decision_at" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Internal Decision</h2>
                        <dl class="space-y-1 text-sm">
                            <div class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Outcome:</dt>
                                <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ STATUS_LABELS[appeal.status] }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Decided:</dt>
                                <dd class="text-slate-800 dark:text-slate-200">{{ fmt(appeal.internal_decision_at) }}</dd>
                            </div>
                            <div v-if="appeal.decided_by" class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">By:</dt>
                                <dd class="text-slate-800 dark:text-slate-200">{{ appeal.decided_by.first_name }} {{ appeal.decided_by.last_name }}</dd>
                            </div>
                            <div v-if="appeal.decision_narrative" class="flex gap-2">
                                <dt class="w-40 text-slate-500 dark:text-slate-400">Reasoning:</dt>
                                <dd class="text-slate-800 dark:text-slate-200 whitespace-pre-wrap">{{ appeal.decision_narrative }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- External review -->
                    <div v-if="appeal.external_review_requested_at" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">External / Independent Review</h2>
                        <dl class="space-y-1 text-sm">
                            <div class="flex gap-2"><dt class="w-40 text-slate-500 dark:text-slate-400">Requested:</dt><dd class="text-slate-800 dark:text-slate-200">{{ fmt(appeal.external_review_requested_at) }}</dd></div>
                            <div v-if="appeal.external_review_outcome" class="flex gap-2"><dt class="w-40 text-slate-500 dark:text-slate-400">Outcome:</dt><dd class="text-slate-800 dark:text-slate-200 capitalize">{{ appeal.external_review_outcome.replace(/_/g, ' ') }}</dd></div>
                            <div v-if="appeal.external_review_outcome_at" class="flex gap-2"><dt class="w-40 text-slate-500 dark:text-slate-400">Outcome Date:</dt><dd class="text-slate-800 dark:text-slate-200">{{ fmt(appeal.external_review_outcome_at) }}</dd></div>
                            <div v-if="appeal.external_review_narrative" class="flex gap-2"><dt class="w-40 text-slate-500 dark:text-slate-400">Notes:</dt><dd class="text-slate-800 dark:text-slate-200 whitespace-pre-wrap">{{ appeal.external_review_narrative }}</dd></div>
                        </dl>
                    </div>
                </div>

                <!-- RIGHT: actions + PDFs + timeline -->
                <div class="space-y-6">
                    <!-- Actions -->
                    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Actions</h2>
                        <div class="flex flex-col gap-2">
                            <button
                                v-if="appeal.status === 'received'"
                                :disabled="busy"
                                @click="acknowledge"
                                class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
                            >
                                Acknowledge + Send Letter
                            </button>
                            <button
                                v-if="appeal.status === 'acknowledged'"
                                :disabled="busy"
                                @click="beginReview"
                                class="px-3 py-1.5 rounded-lg bg-amber-600 text-white text-sm font-medium hover:bg-amber-700 disabled:opacity-50"
                            >
                                Begin Review
                            </button>
                            <button
                                v-if="appeal.status === 'under_review' && canDecide"
                                :disabled="busy"
                                @click="showDecideForm = true"
                                class="px-3 py-1.5 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50"
                            >
                                Record Decision
                            </button>
                            <button
                                v-if="appeal.status === 'decided_upheld' || appeal.status === 'decided_partially_overturned'"
                                :disabled="busy"
                                @click="requestExternal"
                                class="px-3 py-1.5 rounded-lg bg-purple-600 text-white text-sm font-medium hover:bg-purple-700 disabled:opacity-50"
                            >
                                Request External Review
                            </button>
                            <button
                                v-if="isOpen && appeal.status !== 'closed'"
                                :disabled="busy"
                                @click="withdraw"
                                class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-600 disabled:opacity-50"
                            >
                                Withdraw
                            </button>
                            <button
                                v-if="['decided_upheld','decided_overturned','decided_partially_overturned','withdrawn','external_review_requested'].includes(appeal.status)"
                                :disabled="busy"
                                @click="close"
                                class="px-3 py-1.5 rounded-lg bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium hover:bg-slate-300 dark:hover:bg-slate-600 disabled:opacity-50"
                            >
                                Close Appeal
                            </button>
                        </div>

                        <!-- Decide form -->
                        <div v-if="showDecideForm" class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-700 space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Outcome</label>
                                <select v-model="decideOutcome" class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2">
                                    <option value="decided_upheld">Upheld (denial stands)</option>
                                    <option value="decided_overturned">Overturned (denial reversed)</option>
                                    <option value="decided_partially_overturned">Partially Overturned</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Reasoning</label>
                                <textarea v-model="decideNarrative" rows="4"
                                    placeholder="Clinical and administrative reasoning for the decision..."
                                    class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 px-3 py-2" />
                            </div>
                            <div class="flex gap-2 justify-end">
                                <button @click="showDecideForm = false" class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 text-sm">Cancel</button>
                                <button :disabled="busy" @click="decide" class="px-3 py-1.5 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50">
                                    Record + Send Letter
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- PDFs -->
                    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Letters</h2>
                        <div class="flex flex-col gap-2 text-sm">
                            <a
                                v-if="appeal.acknowledgment_pdf"
                                :href="`/appeals/${appeal.id}/acknowledgment.pdf`"
                                class="inline-flex items-center gap-2 text-indigo-600 dark:text-indigo-400 hover:underline"
                            >
                                <DocumentTextIcon class="w-4 h-4" /> Acknowledgment Letter
                            </a>
                            <a
                                v-if="appeal.decision_pdf"
                                :href="`/appeals/${appeal.id}/decision.pdf`"
                                class="inline-flex items-center gap-2 text-indigo-600 dark:text-indigo-400 hover:underline"
                            >
                                <DocumentTextIcon class="w-4 h-4" /> Decision Letter
                            </a>
                            <p v-if="!appeal.acknowledgment_pdf && !appeal.decision_pdf" class="text-xs text-slate-400 italic">No letters generated yet.</p>
                        </div>
                    </div>

                    <!-- Timeline -->
                    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Timeline</h2>
                        <ol class="space-y-3">
                            <li v-for="ev in appeal.events" :key="ev.id" class="flex gap-3 text-xs">
                                <div class="w-2 h-2 rounded-full bg-indigo-400 shrink-0 mt-1.5"></div>
                                <div class="flex-1">
                                    <p class="text-slate-800 dark:text-slate-200 font-medium capitalize">
                                        {{ ev.event_type.replace(/_/g, ' ') }}
                                        <span v-if="ev.from_status && ev.to_status" class="text-slate-500 dark:text-slate-400">
                                            ({{ ev.from_status }} → {{ ev.to_status }})
                                        </span>
                                    </p>
                                    <p v-if="ev.narrative" class="text-slate-600 dark:text-slate-300 whitespace-pre-wrap mt-0.5">{{ ev.narrative }}</p>
                                    <p class="text-slate-400 dark:text-slate-500 mt-0.5">
                                        {{ fmt(ev.occurred_at) }}
                                        <span v-if="ev.actor"> · {{ ev.actor.first_name }} {{ ev.actor.last_name }}</span>
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
