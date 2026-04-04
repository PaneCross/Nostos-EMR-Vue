<script setup lang="ts">
// ─── Participants/Show.vue ────────────────────────────────────────────────────
// Participant profile shell. Manages tab navigation across clinical and admin
// sections. Reads ?tab= from URL on mount, syncs URL on tab change without
// triggering an Inertia reload. Passes scoped data slices down to each tab
// component. Life-threatening allergy banner persists across all tabs.
// CLINICAL tabs use blue active styling; ADMIN tabs use slate.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'
import ParticipantHeader from './Components/ParticipantHeader.vue'
import OverviewTab from './Tabs/OverviewTab.vue'
import ChartTab from './Tabs/ChartTab.vue'
import AllergiesTab from './Tabs/AllergiesTab.vue'
import ProblemsTab from './Tabs/ProblemsTab.vue'
import VitalsTab from './Tabs/VitalsTab.vue'
import FlagsTab from './Tabs/FlagsTab.vue'
import MedicationsTab from './Tabs/MedicationsTab.vue'
import EmarTab from './Tabs/EmarTab.vue'
import CarePlanTab from './Tabs/CarePlanTab.vue'
import AssessmentsTab from './Tabs/AssessmentsTab.vue'
import TransfersTab from './Tabs/TransfersTab.vue'
import SdohTab from './Tabs/SdohTab.vue'
import ImmunizationsTab from './Tabs/ImmunizationsTab.vue'
import ProceduresTab from './Tabs/ProceduresTab.vue'
import LabResultsTab from './Tabs/LabResultsTab.vue'
import WoundsTab from './Tabs/WoundsTab.vue'
import ContactsTab from './Tabs/ContactsTab.vue'
import AddressesTab from './Tabs/AddressesTab.vue'
import InsuranceTab from './Tabs/InsuranceTab.vue'
import DocumentsTab from './Tabs/DocumentsTab.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant {
    id: number
    mrn: string
    first_name: string
    last_name: string
    preferred_name: string | null
    dob: string
    gender: string | null
    enrollment_status: string
    is_active: boolean
    photo_path: string | null
    advance_directive_status: string | null
    advance_directive_type: string | null
    advance_directive_reviewed_at: string | null
    site: { id: number; name: string }
    tenant: { id: number; name: string }
}

const props = defineProps<{
    participant: Participant
    notes?: unknown[]
    vitals?: unknown[]
    allergies?: Record<string, unknown[]>
    problems?: unknown[]
    flags?: unknown[]
    medications?: unknown[]
    assessments?: unknown[]
    carePlan?: unknown
    contacts?: unknown[]
    addresses?: unknown[]
    insurances?: unknown[]
    transfers?: unknown[]
    completedTransfers?: unknown[]
    hasMultipleSites?: boolean
    hasBreakGlassAccess?: boolean
    breakGlassExpiresAt?: string | null
    lifeThreateningAllergyCount?: number
    canEdit?: boolean
    canDelete?: boolean
    icd10Codes?: unknown[]
    noteTemplates?: Record<string, unknown>
}>()

// ── Tab definitions ────────────────────────────────────────────────────────────

const CLINICAL_TABS = [
    { key: 'overview', label: 'Overview' },
    { key: 'chart', label: 'Chart' },
    { key: 'allergies', label: 'Allergies' },
    { key: 'problems', label: 'Problems' },
    { key: 'vitals', label: 'Vitals' },
    { key: 'flags', label: 'Flags' },
    { key: 'medications', label: 'Medications' },
    { key: 'emar', label: 'eMAR' },
    { key: 'care_plan', label: 'Care Plan' },
    { key: 'assessments', label: 'Assessments' },
    { key: 'sdoh', label: 'SDOH' },
    { key: 'immunizations', label: 'Immunizations' },
    { key: 'procedures', label: 'Procedures' },
    { key: 'lab_results', label: 'Lab Results' },
    { key: 'wounds', label: 'Wounds' },
]

const ADMIN_TABS = [
    { key: 'contacts', label: 'Contacts' },
    { key: 'addresses', label: 'Addresses' },
    { key: 'insurance', label: 'Insurance' },
    { key: 'transfers', label: 'Transfers' },
    { key: 'documents', label: 'Documents' },
]

const TAB_COMPONENTS: Record<string, unknown> = {
    overview: OverviewTab,
    chart: ChartTab,
    allergies: AllergiesTab,
    problems: ProblemsTab,
    vitals: VitalsTab,
    flags: FlagsTab,
    medications: MedicationsTab,
    emar: EmarTab,
    care_plan: CarePlanTab,
    assessments: AssessmentsTab,
    sdoh: SdohTab,
    immunizations: ImmunizationsTab,
    procedures: ProceduresTab,
    lab_results: LabResultsTab,
    wounds: WoundsTab,
    contacts: ContactsTab,
    addresses: AddressesTab,
    insurance: InsuranceTab,
    transfers: TransfersTab,
    documents: DocumentsTab,
}

// ── Tab state ──────────────────────────────────────────────────────────────────

const activeTab = ref('overview')

// Read ?tab= param from URL on mount so direct links work
onMounted(() => {
    const params = new URLSearchParams(window.location.search)
    const tab = params.get('tab')
    const allKeys = [...CLINICAL_TABS, ...ADMIN_TABS].map((t) => t.key)
    if (tab && allKeys.includes(tab)) activeTab.value = tab
})

function switchTab(tab: string) {
    activeTab.value = tab
    window.history.replaceState(null, '', '?tab=' + tab)
}

const activeTabComponent = computed(() => TAB_COMPONENTS[activeTab.value] ?? OverviewTab)
const isClinical = computed(() => CLINICAL_TABS.some((t) => t.key === activeTab.value))
const hasLifeThreateningAllergy = computed(() => (props.lifeThreateningAllergyCount ?? 0) > 0)
const page = usePage()
const auth = computed(
    () => (page.props as Record<string, unknown>).auth as { user: { department: string } } | null,
)
</script>

<template>
    <AppShell>
        <Head :title="`${participant.first_name} ${participant.last_name} | ${participant.mrn}`" />

        <!-- Sticky participant header -->
        <ParticipantHeader
            :participant="participant"
            :active-flags="(flags ?? []) as any[]"
            :active-tab="activeTab"
            :can-edit="canEdit ?? false"
            :can-delete="canDelete ?? false"
            :has-break-glass-access="hasBreakGlassAccess ?? false"
            :break-glass-expires-at="breakGlassExpiresAt ?? null"
            @tab-change="switchTab"
        />

        <!-- Life-threatening allergy banner — persists across all tabs -->
        <div
            v-if="hasLifeThreateningAllergy"
            role="alert"
            class="bg-red-600 text-white px-6 py-2 flex items-center gap-3 text-sm"
        >
            <ExclamationTriangleIcon class="w-5 h-5 shrink-0" />
            <span class="font-semibold">ALLERGY ALERT:</span>
            <span>
                {{ lifeThreateningAllergyCount }}
                {{
                    lifeThreateningAllergyCount === 1
                        ? 'life-threatening allergy'
                        : 'life-threatening allergies'
                }}
                on file.
            </span>
            <button
                class="ml-auto text-xs underline hover:no-underline opacity-90"
                @click="switchTab('allergies')"
            >
                View Allergies
            </button>
        </div>

        <!-- Tab bar: CLINICAL (blue) + ADMIN (slate) -->
        <div
            class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-4 overflow-x-auto"
        >
            <div class="flex items-end gap-0 min-w-max" role="tablist">
                <!-- Clinical label -->
                <span
                    class="text-xs font-bold text-blue-500 uppercase tracking-wider px-2 py-2 mr-1 self-center"
                    >Clinical</span
                >
                <button
                    v-for="tab in CLINICAL_TABS"
                    :key="tab.key"
                    role="tab"
                    :aria-selected="activeTab === tab.key"
                    :aria-controls="`panel-${tab.key}`"
                    :class="[
                        'px-3 py-2.5 text-xs font-medium border-b-2 whitespace-nowrap transition-colors',
                        activeTab === tab.key
                            ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                            : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200',
                    ]"
                    @click="switchTab(tab.key)"
                >
                    {{ tab.label }}
                </button>

                <!-- Admin section divider -->
                <span
                    class="text-xs font-bold text-slate-400 uppercase tracking-wider px-3 py-2 mx-1 self-center border-l border-gray-200 dark:border-slate-700"
                    >Admin</span
                >
                <button
                    v-for="tab in ADMIN_TABS"
                    :key="tab.key"
                    role="tab"
                    :aria-selected="activeTab === tab.key"
                    :aria-controls="`panel-${tab.key}`"
                    :class="[
                        'px-3 py-2.5 text-xs font-medium border-b-2 whitespace-nowrap transition-colors',
                        activeTab === tab.key
                            ? 'border-slate-500 text-slate-700 dark:text-slate-200'
                            : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200',
                    ]"
                    @click="switchTab(tab.key)"
                >
                    {{ tab.label }}
                </button>
            </div>
        </div>

        <!-- Active tab panel -->
        <div :id="`panel-${activeTab}`" role="tabpanel" tabindex="0" class="flex-1 overflow-y-auto">
            <component
                :is="activeTabComponent"
                :participant="participant"
                :notes="notes ?? []"
                :vitals="vitals ?? []"
                :allergies="allergies ?? {}"
                :problems="problems ?? []"
                :flags="flags ?? []"
                :medications="medications ?? []"
                :assessments="assessments ?? []"
                :care-plan="carePlan ?? null"
                :contacts="contacts ?? []"
                :addresses="addresses ?? []"
                :insurances="insurances ?? []"
                :transfers="transfers ?? []"
                :completed-transfers="completedTransfers ?? []"
                :has-multiple-sites="hasMultipleSites ?? false"
                :icd10-codes="icd10Codes ?? []"
                :note-templates="noteTemplates ?? {}"
                :can-edit="canEdit ?? false"
                @tab-change="switchTab"
            />
        </div>
    </AppShell>
</template>
