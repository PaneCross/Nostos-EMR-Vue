<script setup lang="ts">
// ─── Participants/Show.vue ────────────────────────────────────────────────────
// Participant profile shell. Manages tab navigation across clinical and admin
// sections. Reads ?tab= from URL on mount, syncs URL on tab change without
// triggering an Inertia reload. Passes scoped data slices down to each tab
// component. Life-threatening allergy banner persists across all tabs.
// CLINICAL tabs (row 1) use blue active styling; ADMIN tabs (row 2) use slate.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { ExclamationTriangleIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import AppShell from '@/Layouts/AppShell.vue'
import ParticipantHeader from './Components/ParticipantHeader.vue'

// ── Tab components ─────────────────────────────────────────────────────────────
import OverviewTab       from './Tabs/OverviewTab.vue'
import ChartTab          from './Tabs/ChartTab.vue'
import AllergiesTab      from './Tabs/AllergiesTab.vue'
import ProblemsTab       from './Tabs/ProblemsTab.vue'
import VitalsTab         from './Tabs/VitalsTab.vue'
import FlagsTab          from './Tabs/FlagsTab.vue'
import MedicationsTab    from './Tabs/MedicationsTab.vue'
import EmarTab           from './Tabs/EmarTab.vue'
import CarePlanTab       from './Tabs/CarePlanTab.vue'
import AssessmentsTab    from './Tabs/AssessmentsTab.vue'
import TransfersTab      from './Tabs/TransfersTab.vue'
import SdohTab           from './Tabs/SdohTab.vue'
import ImmunizationsTab  from './Tabs/ImmunizationsTab.vue'
import ProceduresTab     from './Tabs/ProceduresTab.vue'
import LabResultsTab     from './Tabs/LabResultsTab.vue'
import WoundsTab         from './Tabs/WoundsTab.vue'
import RestraintsTab     from './Tabs/RestraintsTab.vue'
import AdlTab            from './Tabs/AdlTab.vue'
import IadlTab           from './Tabs/IadlTab.vue'
import TbScreeningTab    from './Tabs/TbScreeningTab.vue'
import MedReconTab       from './Tabs/MedReconTab.vue'
import OrdersTab         from './Tabs/OrdersTab.vue'
import ContactsTab       from './Tabs/ContactsTab.vue'
import AddressesTab      from './Tabs/AddressesTab.vue'
import InsuranceTab      from './Tabs/InsuranceTab.vue'
import DocumentsTab      from './Tabs/DocumentsTab.vue'
import GrievancesTab     from './Tabs/GrievancesTab.vue'
import ConsentsTab       from './Tabs/ConsentsTab.vue'
import DisenrollmentTab  from './Tabs/DisenrollmentTab.vue'
import AuditTab          from './Tabs/AuditTab.vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant {
  id: number; mrn: string; first_name: string; last_name: string
  preferred_name: string | null; dob: string; gender: string | null
  enrollment_status: string; is_active: boolean; photo_path: string | null
  advance_directive_status: string | null; advance_directive_type: string | null
  advance_directive_reviewed_at: string | null
  disenrollment_date: string | null; disenrollment_reason: string | null
  enrollment_date: string | null
  site: { id: number; name: string }; tenant: { id: number; name: string }
}

interface AuditEntry {
  id: number
  action: string
  description: string | null
  created_at: string
  user: { first_name: string; last_name: string } | null
}

const props = defineProps<{
  participant:          Participant
  notes?:               unknown[]
  vitals?:              unknown[]
  allergies?:           Record<string, unknown[]>
  problems?:            unknown[]
  flags?:               unknown[]
  medications?:         unknown[]
  assessments?:         unknown[]
  carePlan?:            unknown
  contacts?:            unknown[]
  addresses?:           unknown[]
  insurances?:          unknown[]
  transfers?:           unknown[]
  completedTransfers?:  unknown[]
  hasMultipleSites?:    boolean
  hasBreakGlassAccess?: boolean
  breakGlassExpiresAt?: string | null
  lifeThreateningAllergyCount?: number
  canEdit?:             boolean
  canDelete?:           boolean
  canViewAudit?:        boolean
  icd10Codes?:          unknown[]
  noteTemplates?:       Record<string, unknown>
  auditLogs?:           AuditEntry[]
  disenrollmentReasons?: Record<string, Record<string, string>>
}>()

// ── Tab definitions ────────────────────────────────────────────────────────────

const CLINICAL_TABS = [
  { key: 'facesheet',     label: 'Facesheet' },
  { key: 'chart',         label: 'Chart' },
  { key: 'allergies',     label: 'Allergies' },
  { key: 'diagnoses',     label: 'Diagnoses' },
  { key: 'vitals',        label: 'Vitals' },
  { key: 'flags',         label: 'Flags' },
  { key: 'medications',   label: 'Medications' },
  { key: 'emar',          label: 'eMAR' },
  { key: 'care_plan',     label: 'Care Plan' },
  { key: 'assessments',   label: 'Assessments' },
  { key: 'labs',          label: 'Labs' },
  { key: 'adl',           label: 'ADL' },
  { key: 'iadl',          label: 'IADL' },
  { key: 'tb_screening',  label: 'TB Screening' },
  { key: 'med_recon',     label: 'Med Recon' },
  { key: 'orders',        label: 'Orders' },
  { key: 'sdoh',          label: 'SDOH' },
  { key: 'immunizations', label: 'Immunizations' },
  { key: 'procedures',    label: 'Procedures' },
  { key: 'wounds',        label: 'Wounds' },
  { key: 'restraints',    label: 'Restraints' },
]

const ADMIN_TABS = [
  { key: 'contacts',      label: 'Contacts' },
  { key: 'addresses',     label: 'Addresses' },
  { key: 'insurance',     label: 'Insurance' },
  { key: 'transfers',     label: 'Transfers' },
  { key: 'grievances',    label: 'Grievances' },
  { key: 'consents',      label: 'Consents' },
  { key: 'disenrollment', label: 'Disenrollment' },
  { key: 'documents',     label: 'Documents' },
  { key: 'audit',         label: 'Audit Trail' },
]

const TAB_COMPONENTS: Record<string, unknown> = {
  // Clinical
  facesheet:     OverviewTab,
  chart:         ChartTab,
  allergies:     AllergiesTab,
  diagnoses:     ProblemsTab,
  vitals:        VitalsTab,
  flags:         FlagsTab,
  medications:   MedicationsTab,
  emar:          EmarTab,
  care_plan:     CarePlanTab,
  assessments:   AssessmentsTab,
  labs:          LabResultsTab,
  adl:           AdlTab,
  iadl:          IadlTab,
  tb_screening:  TbScreeningTab,
  med_recon:     MedReconTab,
  orders:        OrdersTab,
  sdoh:          SdohTab,
  immunizations: ImmunizationsTab,
  procedures:    ProceduresTab,
  wounds:        WoundsTab,
  restraints:    RestraintsTab,
  // Admin
  contacts:      ContactsTab,
  addresses:     AddressesTab,
  insurance:     InsuranceTab,
  transfers:     TransfersTab,
  grievances:    GrievancesTab,
  consents:      ConsentsTab,
  disenrollment: DisenrollmentTab,
  documents:     DocumentsTab,
  audit:         AuditTab,
  // Legacy key aliases (backwards compat for old bookmarks)
  overview:      OverviewTab,
  problems:      ProblemsTab,
  lab_results:   LabResultsTab,
}

// ── Tab state ──────────────────────────────────────────────────────────────────

const activeTab = ref('facesheet')

// Resolve legacy key aliases on mount
const LEGACY_KEY_MAP: Record<string, string> = {
  overview:    'facesheet',
  problems:    'diagnoses',
  lab_results: 'labs',
}

onMounted(() => {
  const params = new URLSearchParams(window.location.search)
  const tabParam = params.get('tab') ?? ''
  const resolved = LEGACY_KEY_MAP[tabParam] ?? tabParam
  const allKeys = [...CLINICAL_TABS, ...ADMIN_TABS].map(t => t.key)
  if (resolved && allKeys.includes(resolved)) {
    activeTab.value = resolved
    if (resolved !== tabParam) {
      window.history.replaceState(null, '', '?tab=' + resolved)
    }
  }
})

function switchTab(tab: string) {
  activeTab.value = tab
  window.history.replaceState(null, '', '?tab=' + tab)
}

const activeTabComponent = computed(() => TAB_COMPONENTS[activeTab.value] ?? OverviewTab)
const isClinical = computed(() => CLINICAL_TABS.some(t => t.key === activeTab.value))
const hasLifeThreateningAllergy = computed(() => (props.lifeThreateningAllergyCount ?? 0) > 0)
const page = usePage()
const auth = computed(() => (page.props as Record<string, unknown>).auth as { user: { department: string } } | null)
</script>

<template>
  <AppShell>
    <Head :title="`${participant.first_name} ${participant.last_name} | ${participant.mrn}`" />

    <!-- Sticky wrapper: breadcrumb + participant card + tab bar -->
    <div class="sticky top-0 z-30 bg-gray-50 dark:bg-slate-900 shadow-md">
      <div class="px-4 pt-4">

        <!-- Breadcrumb -->
        <div class="flex items-center gap-1.5 text-xs text-gray-400 dark:text-slate-500 mb-3">
          <a href="/participants" class="hover:text-gray-700 dark:hover:text-slate-200 transition-colors">Participants</a>
          <ChevronRightIcon class="w-3 h-3 shrink-0" />
          <span class="text-gray-700 dark:text-slate-300 font-medium truncate">
            {{ participant.first_name }} {{ participant.last_name }}
            <span v-if="participant.preferred_name" class="font-normal text-gray-400 dark:text-slate-500">"{{ participant.preferred_name }}"</span>
          </span>
        </div>

        <!-- Participant profile card -->
        <div class="bg-white dark:bg-slate-800 rounded-t-xl border border-b-0 border-gray-200 dark:border-slate-700 shadow-sm overflow-hidden">

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
              {{ lifeThreateningAllergyCount === 1 ? 'life-threatening allergy' : 'life-threatening allergies' }} on file.
            </span>
            <button
              class="ml-auto text-xs underline hover:no-underline opacity-90"
              @click="switchTab('allergies')"
            >
              View Allergies
            </button>
          </div>

          <!-- Two-row tab bar: CLINICAL (top) + ADMIN (bottom) -->
          <div class="border-t border-gray-100 dark:border-slate-700/60">
            <!-- CLINICAL row -->
            <div class="border-b border-gray-100 dark:border-slate-700/60 overflow-x-auto scrollbar-none">
              <div class="flex items-end px-3 min-w-max" role="tablist" aria-label="Clinical tabs">
                <span class="text-xs font-bold text-blue-500 uppercase tracking-widest px-2 pb-2 pt-1.5 mr-1 shrink-0 self-end">Clinical</span>
                <button
                  v-for="tab in CLINICAL_TABS"
                  :key="tab.key"
                  role="tab"
                  :aria-selected="activeTab === tab.key"
                  :aria-controls="`panel-${tab.key}`"
                  :class="[
                    'px-3 py-2 text-xs font-medium border-b-2 whitespace-nowrap transition-colors',
                    activeTab === tab.key
                      ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                      : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 hover:border-gray-300 dark:hover:border-slate-600'
                  ]"
                  @click="switchTab(tab.key)"
                >
                  {{ tab.label }}
                </button>
              </div>
            </div>

            <!-- ADMIN row -->
            <div class="overflow-x-auto scrollbar-none">
              <div class="flex items-end px-3 min-w-max" role="tablist" aria-label="Admin tabs">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-widest px-2 pb-2 pt-1.5 mr-1 shrink-0 self-end">Admin</span>
                <button
                  v-for="tab in ADMIN_TABS"
                  :key="tab.key"
                  role="tab"
                  :aria-selected="activeTab === tab.key"
                  :aria-controls="`panel-${tab.key}`"
                  :class="[
                    'px-3 py-2 text-xs font-medium border-b-2 whitespace-nowrap transition-colors',
                    activeTab === tab.key
                      ? 'border-slate-500 text-slate-700 dark:text-slate-200'
                      : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 hover:border-gray-300 dark:hover:border-slate-600'
                  ]"
                  @click="switchTab(tab.key)"
                >
                  {{ tab.label }}
                </button>
              </div>
            </div>
          </div><!-- end tab bar -->

        </div><!-- end profile card -->
      </div><!-- end px-4 pt-4 -->
    </div><!-- end sticky wrapper -->

    <!-- Active tab panel: gray page background with side padding matching the card -->
    <div
      :id="`panel-${activeTab}`"
      role="tabpanel"
      tabindex="0"
      class="flex-1 overflow-y-auto bg-gray-50 dark:bg-slate-900 px-4 pt-4 pb-6"
    >
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
        :can-view-audit="canViewAudit ?? false"
        :audit-logs="auditLogs ?? []"
        :disenrollment-reasons="disenrollmentReasons ?? {}"
        @tab-change="switchTab"
      />
    </div>
  </AppShell>
</template>
