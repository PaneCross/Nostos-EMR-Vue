<script setup lang="ts">
// ─── Dashboard/Index.vue ───────────────────────────────────────────────────────
// Main department dashboard page — rendered via Inertia for all departments.
// DashboardController injects: department, departmentLabel, role, impersonation.
// All 14 clinical/operations + executive + super_admin departments render
// live widget dashboards (real data via Promise.all widget endpoints).
// ─────────────────────────────────────────────────────────────────────────────

import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell.vue'
import { ArrowLeftIcon } from '@heroicons/vue/24/solid'
import type { PageProps } from '@/types'

// ── Clinical dept dashboard components (Phase 7A) ──────────────────────────────
import PrimaryCareDashboard from './Depts/PrimaryCareDashboard.vue'
import TherapiesDashboard from './Depts/TherapiesDashboard.vue'
import SocialWorkDashboard from './Depts/SocialWorkDashboard.vue'
import BehavioralHealthDashboard from './Depts/BehavioralHealthDashboard.vue'
import DietaryDashboard from './Depts/DietaryDashboard.vue'
import ActivitiesDashboard from './Depts/ActivitiesDashboard.vue'
import HomeCareDashboard from './Depts/HomeCareDashboard.vue'

// ── Operations dept dashboard components (Phase 7B) ───────────────────────────
import TransportationDashboard from './Depts/TransportationDashboard.vue'
import PharmacyDashboard from './Depts/PharmacyDashboard.vue'
import IdtDashboard from './Depts/IdtDashboard.vue'
import EnrollmentDashboard from './Depts/EnrollmentDashboard.vue'
import FinanceDashboard from './Depts/FinanceDashboard.vue'
import QaComplianceDashboard from './Depts/QaComplianceDashboard.vue'
import ItAdminDashboard from './Depts/ItAdminDashboard.vue'

// ── Phase 10B: Executive + Super Admin dashboards ──────────────────────────────
import ExecutiveDashboard from './Depts/ExecutiveDashboard.vue'
import SuperAdminDashboard from './Depts/SuperAdminDashboard.vue'

// ── Inertia page props ─────────────────────────────────────────────────────────
interface DashboardProps extends PageProps {
    department: string
    departmentLabel: string
    role: string
}

const page = usePage<DashboardProps>()
const department = computed(() => page.props.department)
const departmentLabel = computed(() => page.props.departmentLabel)
const role = computed(() => page.props.role)
const user = computed(() => page.props.auth?.user)

// ── Department-to-component map ────────────────────────────────────────────────
// Each key matches the 'department' prop value from DashboardController.
// All 16 departments render a live widget dashboard (no static fallback in prod).
type Component = typeof PrimaryCareDashboard
const DEPT_COMPONENT_MAP: Record<string, Component> = {
    primary_care: PrimaryCareDashboard,
    therapies: TherapiesDashboard,
    social_work: SocialWorkDashboard,
    behavioral_health: BehavioralHealthDashboard,
    dietary: DietaryDashboard,
    activities: ActivitiesDashboard,
    home_care: HomeCareDashboard,
    transportation: TransportationDashboard,
    pharmacy: PharmacyDashboard,
    idt: IdtDashboard,
    enrollment: EnrollmentDashboard,
    finance: FinanceDashboard,
    qa_compliance: QaComplianceDashboard,
    it_admin: ItAdminDashboard,
    executive: ExecutiveDashboard,
    super_admin: SuperAdminDashboard,
}

const activeDashboard = computed(() => DEPT_COMPONENT_MAP[department.value] ?? null)

const formattedDate = computed(() =>
    new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
)

const impersonation = computed(() => page.props.impersonation as { active: boolean; viewing_as_dept: string | null } | undefined)
// "Back to Executive Dashboard" only makes semantic sense for users whose home
// is the executive dashboard. Super-admins default to the IT Admin view; they
// have the dashboard view selector for navigation, so we don't show this button
// for them. Only show when an actual executive has clicked into a dept view.
const isViewingAsDept = computed(() =>
    user.value?.department === 'executive'
    && impersonation.value?.viewing_as_dept
    && impersonation.value.viewing_as_dept !== 'executive'
    && department.value !== 'executive'
)

function backToExecutive() {
    axios.post('/super-admin/view-as', { department: 'executive' }).then(() => {
        location.href = '/'
    })
}
</script>

<template>
    <AppShell>
        <template #header>
            <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ departmentLabel }}</span>
        </template>

        <Head :title="departmentLabel" />

        <div class="p-6">
            <!-- Back to Executive Dashboard button -->
            <button
                v-if="isViewingAsDept"
                type="button"
                class="mb-4 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-100 dark:hover:bg-indigo-950/60 transition-colors"
                @click="backToExecutive"
            >
                <ArrowLeftIcon class="w-4 h-4" />
                Back to Executive Dashboard
            </button>

            <!-- Welcome header -->
            <div class="mb-6">
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
                            Welcome back, {{ user?.first_name }}
                        </h1>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-sm font-medium bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300">
                                {{ departmentLabel }}
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-sm font-medium bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 capitalize">
                                {{ role }}
                            </span>
                        </div>
                    </div>
                    <div class="text-right text-sm text-slate-400 hidden md:block">
                        <p>{{ formattedDate }}</p>
                    </div>
                </div>
            </div>

            <!-- Live dept dashboard component -->
            <component
                :is="activeDashboard"
                v-if="activeDashboard"
                :department-label="departmentLabel"
                :role="role"
            />

            <!-- Fallback for unknown department -->
            <div
                v-else
                class="mt-8 p-4 rounded-lg border border-dashed border-slate-300 bg-slate-50 dark:bg-slate-900 text-center"
            >
                <p class="text-sm text-slate-400">
                    Dashboard for
                    <span class="font-medium text-slate-500">{{ department }}</span>
                    is not yet configured.
                </p>
            </div>
        </div>
    </AppShell>
</template>
