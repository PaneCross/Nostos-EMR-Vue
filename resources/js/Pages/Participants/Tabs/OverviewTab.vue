<script setup lang="ts">
// ─── OverviewTab.vue ──────────────────────────────────────────────────────────
// PACE Facesheet overview. Summarizes participant demographics, enrollment info,
// language preferences, advance directive status, and key clinical indicators.
// Designed for print-to-PDF use. Supports window.print() via the Print button.
// ─────────────────────────────────────────────────────────────────────────────

import { computed } from 'vue'
import { PrinterIcon } from '@heroicons/vue/24/outline'

interface Participant {
    id: number
    mrn: string
    first_name: string
    last_name: string
    preferred_name: string | null
    dob: string
    gender: string | null
    pronouns: string | null
    ssn_last_four: string | null
    medicare_id: string | null
    medicaid_id: string | null
    pace_contract_id: string | null
    h_number: string | null
    primary_language: string
    interpreter_needed: boolean
    interpreter_language: string | null
    enrollment_status: string
    enrollment_date: string | null
    disenrollment_date: string | null
    disenrollment_reason: string | null
    nursing_facility_eligible: boolean
    advance_directive_status: string | null
    advance_directive_type: string | null
    advance_directive_reviewed_at: string | null
    race: string | null
    ethnicity: string | null
    marital_status: string | null
    veteran_status: string | null
    education_level: string | null
    religion: string | null
    site: { id: number; name: string }
    tenant: { id: number; name: string }
    created_at: string
}

const props = defineProps<{
    participant: Participant
    flags?: unknown[]
    problems?: unknown[]
    allergies?: Record<string, unknown[]>
}>()

function fmtDate(val: string | null | undefined): string {
    if (!val) return '-'
    const d = new Date(val.slice(0, 10) + 'T12:00:00')
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function age(dob: string): number {
    const d = new Date(dob.slice(0, 10) + 'T12:00:00')
    const now = new Date()
    let a = now.getFullYear() - d.getFullYear()
    if (now < new Date(now.getFullYear(), d.getMonth(), d.getDate())) a--
    return a
}

const activeProblems = computed(() =>
    (
        (props.problems ?? []) as Array<{
            status: string
            icd10_code: string
            icd10_description: string
            is_primary_diagnosis: boolean
        }>
    )
        .filter((p) => p.status === 'active' || p.status === 'chronic')
        .slice(0, 8),
)

const lifeThreateningAllergies = computed(() => {
    const groups = props.allergies ?? {}
    return Object.values(groups)
        .flat()
        .filter(
            (a: unknown) => (a as { severity: string }).severity === 'life_threatening',
        ) as Array<{ id: number; allergen_name: string; reaction_description: string | null }>
})
</script>

<template>
    <div id="facesheet-print" class="p-6 max-w-4xl">
        <!-- Print button -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">PACE Facesheet</h2>
            <button
                class="inline-flex items-center gap-2 text-sm px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors print:hidden"
                @click="() => window.print()"
            >
                <PrinterIcon class="w-4 h-4" />
                Print Facesheet
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Demographics -->
            <div
                class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4"
            >
                <h3
                    class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3"
                >
                    Demographics
                </h3>
                <dl class="space-y-2">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">Full Name</dt>
                            <dd class="text-sm font-medium text-gray-900 dark:text-slate-100">
                                {{ participant.first_name }} {{ participant.last_name }}
                                <span
                                    v-if="participant.preferred_name"
                                    class="text-gray-400 text-xs"
                                    >"{{ participant.preferred_name }}"</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">MRN</dt>
                            <dd class="text-sm font-mono text-gray-900 dark:text-slate-100">
                                {{ participant.mrn }}
                            </dd>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">Date of Birth</dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100">
                                {{ fmtDate(participant.dob) }} (age {{ age(participant.dob) }})
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">Gender</dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100">
                                {{ participant.gender ?? '-' }}
                            </dd>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">Language</dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100">
                                {{ participant.primary_language }}
                                <span
                                    v-if="participant.interpreter_needed"
                                    class="text-amber-600 dark:text-amber-400 text-xs ml-1"
                                    >(Interpreter needed)</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">
                                Marital Status
                            </dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100">
                                {{ participant.marital_status ?? '-' }}
                            </dd>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">
                                Race / Ethnicity
                            </dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100">
                                {{ participant.race ?? '-' }} / {{ participant.ethnicity ?? '-' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">
                                Veteran Status
                            </dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100">
                                {{ participant.veteran_status ?? '-' }}
                            </dd>
                        </div>
                    </div>
                </dl>
            </div>

            <!-- Enrollment -->
            <div
                class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4"
            >
                <h3
                    class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3"
                >
                    Enrollment
                </h3>
                <dl class="space-y-2">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">Status</dt>
                            <dd
                                class="text-sm font-medium text-gray-900 dark:text-slate-100 capitalize"
                            >
                                {{ participant.enrollment_status }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">
                                Enrollment Date
                            </dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100">
                                {{ fmtDate(participant.enrollment_date) }}
                            </dd>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">Medicare ID</dt>
                            <dd class="text-sm font-mono text-gray-900 dark:text-slate-100">
                                {{ participant.medicare_id ?? '-' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">Medicaid ID</dt>
                            <dd class="text-sm font-mono text-gray-900 dark:text-slate-100">
                                {{ participant.medicaid_id ?? '-' }}
                            </dd>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">Site</dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100">
                                {{ participant.site.name }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">NF Eligible</dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100">
                                {{ participant.nursing_facility_eligible ? 'Yes' : 'No' }}
                            </dd>
                        </div>
                    </div>
                </dl>
            </div>

            <!-- Advance Directive (42 CFR 460.96) -->
            <div
                class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4"
            >
                <h3
                    class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3"
                >
                    Advance Directive
                </h3>
                <dl class="space-y-2">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">Status</dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100 capitalize">
                                {{
                                    participant.advance_directive_status?.replace(/_/g, ' ') ?? '-'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-slate-400">Type</dt>
                            <dd class="text-sm text-gray-900 dark:text-slate-100 capitalize">
                                {{ participant.advance_directive_type?.replace(/_/g, ' ') ?? '-' }}
                            </dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-slate-400">Last Reviewed</dt>
                        <dd class="text-sm text-gray-900 dark:text-slate-100">
                            {{ fmtDate(participant.advance_directive_reviewed_at) }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Life-threatening allergies summary -->
            <div
                class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4"
            >
                <h3 class="text-xs font-bold text-red-500 uppercase tracking-wider mb-3">
                    Life-Threatening Allergies
                </h3>
                <div
                    v-if="lifeThreateningAllergies.length === 0"
                    class="text-sm text-gray-400 dark:text-slate-500"
                >
                    None on file
                </div>
                <ul v-else class="space-y-1">
                    <li
                        v-for="allergy in lifeThreateningAllergies"
                        :key="allergy.id"
                        class="text-sm text-red-700 dark:text-red-300 font-medium"
                    >
                        {{ allergy.allergen_name }}
                        <span
                            v-if="allergy.reaction_description"
                            class="text-gray-600 dark:text-slate-400 font-normal"
                        >
                            : {{ allergy.reaction_description }}
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Active problems summary -->
            <div
                class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4 md:col-span-2"
            >
                <h3
                    class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3"
                >
                    Active Problem List
                </h3>
                <div
                    v-if="activeProblems.length === 0"
                    class="text-sm text-gray-400 dark:text-slate-500"
                >
                    No active problems on file
                </div>
                <ul v-else class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                    <li
                        v-for="problem in activeProblems"
                        :key="problem.icd10_code"
                        class="flex items-start gap-2 text-sm"
                    >
                        <span
                            class="font-mono text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-1.5 py-0.5 rounded mt-0.5 shrink-0"
                            >{{ problem.icd10_code }}</span
                        >
                        <span class="text-gray-800 dark:text-slate-200">
                            {{ problem.icd10_description }}
                            <span
                                v-if="problem.is_primary_diagnosis"
                                class="text-xs text-blue-600 dark:text-blue-400 ml-1"
                                >(Primary)</span
                            >
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
