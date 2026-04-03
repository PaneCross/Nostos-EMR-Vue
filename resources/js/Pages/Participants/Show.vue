<script setup lang="ts">
// ─── Participants/Show.vue ────────────────────────────────────────────────────
// Main participant record page. Two-row tab navigation: CLINICAL tabs (chart,
// vitals, assessments, meds, etc.) and ADMIN tabs (overview/facesheet, contacts,
// flags, insurance, audit, etc.). Tab state syncs to ?tab= URL param. Role-based
// tab visibility. Lazy-load for most clinical tabs.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import { usePage, router, Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import OverviewTab from './Tabs/OverviewTab.vue'
import ChartTab from './Tabs/ChartTab.vue'
import VitalsTab from './Tabs/VitalsTab.vue'
import ContactsTab from './Tabs/ContactsTab.vue'
import FlagsTab from './Tabs/FlagsTab.vue'
import InsuranceTab from './Tabs/InsuranceTab.vue'
import AssessmentsTab from './Tabs/AssessmentsTab.vue'
import ProblemsTab from './Tabs/ProblemsTab.vue'
import AllergiesTab from './Tabs/AllergiesTab.vue'
import AdlTab from './Tabs/AdlTab.vue'
import CarePlanTab from './Tabs/CarePlanTab.vue'
import MedicationsTab from './Tabs/MedicationsTab.vue'
import EmarTab from './Tabs/EmarTab.vue'
import MedReconTab from './Tabs/MedReconTab.vue'
import OrdersTab from './Tabs/OrdersTab.vue'
import ImmunizationsTab from './Tabs/ImmunizationsTab.vue'
import ProceduresTab from './Tabs/ProceduresTab.vue'
import SdohTab from './Tabs/SdohTab.vue'
import WoundsTab from './Tabs/WoundsTab.vue'
import LabResultsTab from './Tabs/LabResultsTab.vue'
import DocumentsTab from './Tabs/DocumentsTab.vue'
import AuditTab from './Tabs/AuditTab.vue'
import TransfersTab from './Tabs/TransfersTab.vue'
import GrievancesTab from './Tabs/GrievancesTab.vue'
import ConsentsTab from './Tabs/ConsentsTab.vue'
import DisenrollmentTab from './Tabs/DisenrollmentTab.vue'
import { ExclamationTriangleIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline'

// ── Interfaces ────────────────────────────────────────────────────────────────
interface Participant {
    id: number
    first_name: string
    last_name: string
    dob: string | null
    mrn: string
    status: string
    enrollment_date: string | null
    primary_language: string | null
    gender: string | null
    advance_directive_status: string | null
    has_dnr: boolean
    photo_url: string | null
    site_name: string | null
}
interface Address {
    id: number
    address_type: string
    street_line_1: string
    street_line_2: string | null
    city: string
    state: string
    zip_code: string
    is_primary: boolean
}
interface Contact {
    id: number
    contact_type: string
    full_name: string
    relationship: string | null
    phone_primary: string | null
    email: string | null
    is_emergency_contact: boolean
    is_primary_caregiver: boolean
}
interface Flag {
    id: number
    flag_type: string
    severity: string
    notes: string | null
    is_active: boolean
}
interface Insurance {
    id: number
    payer_type: string
    member_id: string | null
    plan_name: string | null
    effective_date: string | null
    term_date: string | null
    is_active: boolean
}
interface AuditEntry {
    id: number
    action: string
    description: string | null
    user_id: number | null
    created_at: string
}
interface Problem {
    id: number
    icd10_code: string
    icd10_description: string
    category: string | null
    status: string
    onset_date: string | null
    is_primary_diagnosis: boolean
}
interface Allergy {
    id: number
    allergen_name: string
    allergy_type: string
    severity: string
    reaction_description: string | null
    is_active: boolean
}
interface Vital {
    id: number
    recorded_at: string
    systolic_bp: number | null
    diastolic_bp: number | null
    heart_rate: number | null
    respiratory_rate: number | null
    temperature_f: number | null
    weight_lbs: number | null
    oxygen_saturation: number | null
    pain_scale: number | null
    blood_glucose: number | null
}

type Tab =
    | 'overview'
    | 'chart'
    | 'vitals'
    | 'assessments'
    | 'problems'
    | 'allergies'
    | 'adl'
    | 'careplan'
    | 'medications'
    | 'emar'
    | 'med-recon'
    | 'immunizations'
    | 'procedures'
    | 'sdoh'
    | 'wounds'
    | 'lab-results'
    | 'orders'
    | 'contacts'
    | 'flags'
    | 'insurance'
    | 'documents'
    | 'audit'
    | 'transfers'
    | 'grievances'
    | 'consents'
    | 'disenrollment'

const props = defineProps<{
    participant: Participant
    addresses: Address[]
    contacts: Contact[]
    flags: Flag[]
    insurances: Insurance[]
    auditLogs: AuditEntry[]
    canEdit: boolean
    canDelete: boolean
    canViewAudit: boolean
    problems: Problem[]
    allergies: Allergy[]
    lifeThreateningAllergyCount: number
    vitals: Vital[]
    icd10Codes: { code: string; description: string }[]
    noteTemplates: Record<string, { label: string; departments: string[] }>
    hasMultipleSites: boolean
    completedTransfers: {
        effective_date: string
        from_site_name: string | null
        to_site_name: string | null
    }[]
    hasBreakGlassAccess: boolean
    breakGlassExpiresAt: string | null
}>()

const page = usePage()

const auth = computed(
    () =>
        (page.props as Record<string, unknown>).auth as {
            user: { id: number; department: string; is_super_admin: boolean }
        },
)

const dept = computed(() => auth.value.user.department)
const isSuperAdmin = computed(() => auth.value.user.is_super_admin)

const canManageTransfers = computed(
    () => isSuperAdmin.value || ['enrollment', 'it_admin'].includes(dept.value),
)
const canViewGrievances = computed(
    () => isSuperAdmin.value || ['qa_compliance', 'it_admin'].includes(dept.value),
)
const canViewConsents = computed(
    () => isSuperAdmin.value || ['enrollment', 'qa_compliance', 'it_admin'].includes(dept.value),
)
const canViewDisenrollment = computed(
    () => isSuperAdmin.value || ['enrollment', 'qa_compliance', 'it_admin'].includes(dept.value),
)

// ── Tab definitions ───────────────────────────────────────────────────────────
const clinicalTabs: { id: Tab; label: string }[] = [
    { id: 'chart', label: 'Chart' },
    { id: 'vitals', label: 'Vitals' },
    { id: 'assessments', label: 'Assessments' },
    { id: 'medications', label: 'Medications' },
    { id: 'emar', label: 'eMAR' },
    { id: 'med-recon', label: 'Reconciliation' },
    { id: 'problems', label: 'Diagnoses' },
    { id: 'allergies', label: 'Allergies' },
    { id: 'adl', label: 'ADL' },
    { id: 'careplan', label: 'Care Plan' },
    { id: 'orders', label: 'Orders' },
    { id: 'immunizations', label: 'Immunizations' },
    { id: 'procedures', label: 'Procedures' },
    { id: 'wounds', label: 'Wounds' },
    { id: 'lab-results', label: 'Lab Results' },
]

const adminTabs = computed(() => {
    const base: { id: Tab; label: string }[] = [
        { id: 'overview', label: 'Facesheet' },
        { id: 'contacts', label: 'Contacts' },
        { id: 'flags', label: 'Flags' },
        { id: 'insurance', label: 'Insurance' },
        { id: 'documents', label: 'Documents' },
        { id: 'sdoh', label: 'SDOH' },
    ]
    if (canManageTransfers.value) base.push({ id: 'transfers', label: 'Transfers' })
    if (canViewGrievances.value) base.push({ id: 'grievances', label: 'Grievances' })
    if (canViewConsents.value) base.push({ id: 'consents', label: 'Consents' })
    if (canViewDisenrollment.value) base.push({ id: 'disenrollment', label: 'Disenrollment' })
    if (props.canViewAudit) base.push({ id: 'audit', label: 'Audit' })
    return base
})

// ── Active tab ────────────────────────────────────────────────────────────────
function getInitialTab(): Tab {
    const param = new URLSearchParams(window.location.search).get('tab') as Tab | null
    const allTabs: Tab[] = [...clinicalTabs.map((t) => t.id), ...adminTabs.value.map((t) => t.id)]
    return param && allTabs.includes(param) ? param : 'chart'
}

const activeTab = ref<Tab>('chart')
onMounted(() => {
    activeTab.value = getInitialTab()
})

function setTab(tab: Tab) {
    activeTab.value = tab
    const url = new URL(window.location.href)
    url.searchParams.set('tab', tab)
    window.history.replaceState({}, '', url.toString())
}

// ── Status colors ─────────────────────────────────────────────────────────────
const STATUS_COLORS: Record<string, string> = {
    active: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    inactive: 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
    disenrolled: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    pending: 'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-700 dark:text-yellow-300',
    transferred: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
}

function calcAge(dob: string | null): string {
    if (!dob) return '-'
    const d = new Date(dob.slice(0, 10) + 'T12:00:00')
    const today = new Date()
    let age = today.getFullYear() - d.getFullYear()
    if (
        today.getMonth() < d.getMonth() ||
        (today.getMonth() === d.getMonth() && today.getDate() < d.getDate())
    )
        age--
    return String(age)
}

function fmtDate(val: string | null): string {
    if (!val) return '-'
    return new Date(val.slice(0, 10) + 'T12:00:00').toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}

// ── Break glass ───────────────────────────────────────────────────────────────
const bgLoading = ref(false)
const bgError = ref<string | null>(null)

async function requestBreakGlass() {
    bgLoading.value = true
    bgError.value = null
    router.post(
        `/participants/${props.participant.id}/break-glass`,
        {},
        {
            preserveScroll: true,
            onError: (e: Record<string, string>) => {
                bgError.value = e.message ?? 'Failed.'
            },
            onFinish: () => {
                bgLoading.value = false
            },
        },
    )
}

const isBreakGlassActive = computed(() => {
    if (!props.hasBreakGlassAccess || !props.breakGlassExpiresAt) return false
    return new Date(props.breakGlassExpiresAt) > new Date()
})
</script>

<template>
    <Head :title="`${participant.first_name} ${participant.last_name}`" />
    <AppShell>
        <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <!-- Participant header -->
            <div
                class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-5 mb-4 flex items-start gap-5"
            >
                <!-- Photo / initials -->
                <div
                    class="w-16 h-16 rounded-full bg-gray-200 dark:bg-slate-700 flex-shrink-0 overflow-hidden flex items-center justify-center"
                >
                    <img
                        v-if="participant.photo_url"
                        :src="participant.photo_url"
                        :alt="participant.first_name"
                        class="w-full h-full object-cover"
                    />
                    <span v-else class="text-2xl font-bold text-gray-400 dark:text-slate-500">
                        {{ participant.first_name[0] }}{{ participant.last_name[0] }}
                    </span>
                </div>

                <!-- Name + meta -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="text-xl font-bold text-gray-900 dark:text-slate-100">
                            {{ participant.first_name }} {{ participant.last_name }}
                        </h1>
                        <span
                            :class="`text-xs px-2 py-0.5 rounded-full font-medium ${STATUS_COLORS[participant.status] ?? 'bg-gray-100 text-gray-600'}`"
                        >
                            {{ participant.status }}
                        </span>
                        <span
                            v-if="participant.has_dnr"
                            class="text-xs px-2 py-0.5 rounded-full font-bold bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border border-red-300"
                            >DNR</span
                        >
                        <span
                            v-if="lifeThreateningAllergyCount > 0"
                            class="flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-bold bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border border-red-300"
                        >
                            <ExclamationTriangleIcon class="w-3 h-3" />
                            {{ lifeThreateningAllergyCount }}
                            {{
                                lifeThreateningAllergyCount === 1
                                    ? 'Life-Threatening Allergy'
                                    : 'Life-Threatening Allergies'
                            }}
                        </span>
                    </div>
                    <div
                        class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-slate-400"
                    >
                        <span
                            >MRN:
                            <span class="font-mono text-gray-800 dark:text-slate-200">{{
                                participant.mrn
                            }}</span></span
                        >
                        <span v-if="participant.dob"
                            >DOB: {{ fmtDate(participant.dob) }} ({{
                                calcAge(participant.dob)
                            }}
                            y/o)</span
                        >
                        <span v-if="participant.gender">{{ participant.gender }}</span>
                        <span v-if="participant.site_name">Site: {{ participant.site_name }}</span>
                        <span v-if="participant.enrollment_date"
                            >Enrolled: {{ fmtDate(participant.enrollment_date) }}</span
                        >
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-2 flex-shrink-0">
                    <div
                        v-if="isBreakGlassActive"
                        class="flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 rounded-lg px-3 py-1.5"
                    >
                        <ShieldCheckIcon class="w-4 h-4" />
                        Emergency Access Active
                    </div>
                    <button
                        v-else-if="!hasBreakGlassAccess"
                        :disabled="bgLoading"
                        aria-label="Request emergency break-glass access"
                        class="text-xs px-3 py-1.5 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white rounded-lg transition-colors flex items-center gap-1.5"
                        @click="requestBreakGlass"
                    >
                        <ShieldCheckIcon class="w-4 h-4" />
                        Break Glass
                    </button>
                    <Link
                        v-if="canEdit"
                        :href="`/participants/${participant.id}/edit`"
                        class="text-xs px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors"
                        >Edit</Link
                    >
                </div>
            </div>

            <p v-if="bgError" class="mb-2 text-sm text-red-600 dark:text-red-400">{{ bgError }}</p>

            <!-- CLINICAL tab row -->
            <div class="border-b border-gray-200 dark:border-slate-700">
                <div class="flex items-center gap-0.5 overflow-x-auto">
                    <span
                        class="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wider pr-2 flex-shrink-0"
                        >Clinical</span
                    >
                    <button
                        v-for="tab in clinicalTabs"
                        :key="tab.id"
                        class="flex-shrink-0 px-3 py-2.5 text-xs font-medium transition-colors border-b-2"
                        :class="
                            activeTab === tab.id
                                ? 'border-blue-600 text-blue-600 dark:text-blue-400 dark:border-blue-400'
                                : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200'
                        "
                        @click="setTab(tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                </div>
            </div>

            <!-- ADMIN tab row -->
            <div class="mb-4 border-b border-gray-200 dark:border-slate-700">
                <div class="flex items-center gap-0.5 overflow-x-auto">
                    <span
                        class="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wider pr-2 flex-shrink-0"
                        >Admin</span
                    >
                    <button
                        v-for="tab in adminTabs"
                        :key="tab.id"
                        class="flex-shrink-0 px-3 py-2.5 text-xs font-medium transition-colors border-b-2"
                        :class="
                            activeTab === tab.id
                                ? 'border-slate-600 text-slate-700 dark:text-slate-200 dark:border-slate-400'
                                : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200'
                        "
                        @click="setTab(tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                </div>
            </div>

            <!-- Tab panels -->
            <div
                class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-5"
            >
                <KeepAlive>
                    <ChartTab
                        v-if="activeTab === 'chart'"
                        :participant-id="participant.id"
                        :note-templates="noteTemplates"
                        :has-multiple-sites="hasMultipleSites"
                    />
                    <VitalsTab
                        v-else-if="activeTab === 'vitals'"
                        :participant-id="participant.id"
                        :initial-vitals="vitals"
                        :completed-transfers="completedTransfers"
                    />
                    <AssessmentsTab
                        v-else-if="activeTab === 'assessments'"
                        :participant-id="participant.id"
                    />
                    <MedicationsTab
                        v-else-if="activeTab === 'medications'"
                        :participant-id="participant.id"
                    />
                    <EmarTab v-else-if="activeTab === 'emar'" :participant-id="participant.id" />
                    <MedReconTab
                        v-else-if="activeTab === 'med-recon'"
                        :participant-id="participant.id"
                    />
                    <ProblemsTab
                        v-else-if="activeTab === 'problems'"
                        :participant-id="participant.id"
                        :initial-problems="problems"
                        :icd10-codes="icd10Codes"
                    />
                    <AllergiesTab
                        v-else-if="activeTab === 'allergies'"
                        :participant-id="participant.id"
                        :initial-allergies="allergies"
                    />
                    <AdlTab v-else-if="activeTab === 'adl'" :participant-id="participant.id" />
                    <CarePlanTab
                        v-else-if="activeTab === 'careplan'"
                        :participant-id="participant.id"
                    />
                    <OrdersTab
                        v-else-if="activeTab === 'orders'"
                        :participant-id="participant.id"
                        :user-dept="dept"
                        :is-super-admin="isSuperAdmin"
                    />
                    <ImmunizationsTab
                        v-else-if="activeTab === 'immunizations'"
                        :participant-id="participant.id"
                    />
                    <ProceduresTab
                        v-else-if="activeTab === 'procedures'"
                        :participant-id="participant.id"
                    />
                    <WoundsTab
                        v-else-if="activeTab === 'wounds'"
                        :participant-id="participant.id"
                    />
                    <LabResultsTab
                        v-else-if="activeTab === 'lab-results'"
                        :participant-id="participant.id"
                        :user-dept="dept"
                        :user-is-super-admin="isSuperAdmin"
                    />
                    <OverviewTab
                        v-else-if="activeTab === 'overview'"
                        :participant="participant"
                        :addresses="addresses"
                        :contacts="contacts"
                        :flags="flags"
                    />
                    <ContactsTab
                        v-else-if="activeTab === 'contacts'"
                        :participant-id="participant.id"
                        :initial-contacts="contacts"
                    />
                    <FlagsTab
                        v-else-if="activeTab === 'flags'"
                        :participant-id="participant.id"
                        :initial-flags="flags"
                    />
                    <InsuranceTab v-else-if="activeTab === 'insurance'" :insurances="insurances" />
                    <DocumentsTab
                        v-else-if="activeTab === 'documents'"
                        :participant-id="participant.id"
                    />
                    <SdohTab v-else-if="activeTab === 'sdoh'" :participant-id="participant.id" />
                    <TransfersTab
                        v-else-if="activeTab === 'transfers'"
                        :participant-id="participant.id"
                    />
                    <GrievancesTab
                        v-else-if="activeTab === 'grievances'"
                        :participant-id="participant.id"
                    />
                    <ConsentsTab
                        v-else-if="activeTab === 'consents'"
                        :participant-id="participant.id"
                    />
                    <DisenrollmentTab
                        v-else-if="activeTab === 'disenrollment'"
                        :participant-id="participant.id"
                    />
                    <AuditTab v-else-if="activeTab === 'audit'" :logs="auditLogs" />
                </KeepAlive>
            </div>
        </div>
    </AppShell>
</template>
